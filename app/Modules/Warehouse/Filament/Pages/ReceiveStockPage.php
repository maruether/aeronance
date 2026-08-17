<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Pages;

use App\Core\Documents\DocumentType;
use App\Core\Documents\Rules\SafeDocument;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StorageCompartment;
use App\Modules\Warehouse\Permissions;
use App\Modules\Warehouse\Support\DocumentTypes;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Goods in.
 *
 * The form changes shape according to what the part type actually needs, rather
 * than showing everything and letting the user work out which half applies:
 * a serial number only for serialised parts, the Form 1 blocks only where a
 * certificate is required, the expiry date shown once a shelf life exists.
 *
 * The Form 1 fields follow the blocks of the printed form -- reference, issuer,
 * approval number, date, signatory -- so a paper certificate can be transcribed
 * straight down the page without hunting for the matching box.
 */
final class ReceiveStockPage extends Page
{
    protected string $view = 'warehouse.filament.pages.receive-stock';

    protected static ?string $slug = 'einbuchen';

    protected static ?int $navigationSort = 10;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.warehouse');
    }

    public static function getNavigationLabel(): string
    {
        return __('warehouse.receive.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('warehouse.receive.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('warehouse.receive.subheading');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedArrowDownOnSquare;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permissions::STOCK_RECEIVE) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(['received_at' => now()->toDateString()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('warehouse.receive.section.what'))
                    ->schema([
                        Select::make('part_type_id')
                            ->label(__('warehouse.part_type.singular'))
                            ->options(fn (): array => PartType::orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn () => $this->resetLotFields()),

                        TextInput::make('quantity')
                            ->label(__('warehouse.receive.field.quantity'))
                            ->numeric()
                            ->minValue(0.001)
                            ->required()
                            ->suffix(fn (): ?string => $this->selectedPart()?->unit_of_measure)
                            // A serialised part is a lot of one -- that is what
                            // the serial number identifies.
                            ->default(fn (): ?float => $this->selectedPart()?->serial_tracked ? 1 : null)
                            ->disabled(fn (): bool => (bool) $this->selectedPart()?->serial_tracked)
                            ->dehydrated(),

                        DatePicker::make('received_at')
                            ->label(__('warehouse.receive.field.received_at'))
                            ->required()
                            ->default(now())
                            ->live()
                            ->helperText(fn (): ?string => $this->expiryHint()),

                        TextInput::make('serial_number')
                            ->label(__('warehouse.lot.field.serial_number'))
                            ->maxLength(128)
                            ->required(fn (): bool => (bool) $this->selectedPart()?->serial_tracked)
                            ->visible(fn (): bool => (bool) $this->selectedPart()?->serial_tracked),
                    ])
                    ->columns(2),

                Section::make(__('warehouse.receive.section.evidence'))
                    ->description(__('warehouse.receive.help.evidence'))
                    ->visible(fn (): bool => (bool) $this->selectedPart()?->requires_form_one)
                    ->schema([
                        Select::make('document_type')
                            ->label(__('warehouse.lot.field.document_type'))
                            ->options(fn (): array => DocumentTypes::options())
                            ->default(StockLot::DOCUMENT_FORM_ONE)
                            ->live()
                            /*
                             * Freitext, aber NIE als vierter gleichberechtigter
                             * Typ -- siehe DocumentTypes. Wer hier "Form 1"
                             * eintraegt, bekommt ein Papier mit dieser
                             * Aufschrift, keinen Nachweis.
                             */
                            ->createOptionForm([
                                TextInput::make('label')
                                    ->label(__('warehouse.lot.field.document_type_own'))
                                    ->required()
                                    ->maxLength(26)
                                    ->helperText(__('warehouse.receive.help.document_type_own')),
                            ])
                            ->createOptionUsing(fn (array $data): string => DocumentTypes::custom((string) $data['label'])),

                        TextInput::make('document_reference')
                            ->label(__('warehouse.lot.field.document_reference'))
                            ->maxLength(128)
                            ->helperText(__('warehouse.receive.help.document_reference')),

                        TextInput::make('batch_number')
                            ->label(__('warehouse.lot.field.batch_number'))
                            ->maxLength(128)
                            ->helperText(__('warehouse.receive.help.batch_number')),

                        TextInput::make('document_issuer')
                            ->label(__('warehouse.lot.field.document_issuer'))
                            ->maxLength(255),

                        TextInput::make('document_issuer_approval')
                            ->label(__('warehouse.lot.field.document_issuer_approval'))
                            ->maxLength(128),

                        DatePicker::make('document_issued_at')
                            ->label(__('warehouse.lot.field.document_issued_at')),

                        TextInput::make('document_signatory')
                            ->label(__('warehouse.lot.field.document_signatory'))
                            ->maxLength(255),

                        /*
                         * Scan des Papierdokuments.
                         *
                         * acceptedFileTypes und maxSize sind die bequeme
                         * Ebene -- sie halten Vertipper ab und beschriften das
                         * Dateiauswahlfenster. Die belastbare Ebene ist
                         * SafeDocument: die liest die Datei selbst, prueft
                         * Signatur, Struktur und Endung gegeneinander und laesst
                         *, falls eingerichtet, clamd darueber. Ein Angreifer
                         * benennt eine Datei um; an den ersten Bytes aendert das
                         * nichts.
                         */
                        FileUpload::make('document_file')
                            ->label(__('warehouse.lot.field.document_file'))
                            ->disk('documents')
                            ->directory('certificates')
                            ->visibility('private')
                            ->acceptedFileTypes(DocumentType::mimeTypes())
                            ->maxSize(config('aeronance.documents.max_size_mb', 20) * 1024)
                            ->rules([new SafeDocument])
                            ->helperText(__('warehouse.receive.help.document_file'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(__('warehouse.receive.section.where'))
                    ->schema([
                        Select::make('storage_compartment_id')
                            ->label(__('warehouse.part_type.field.compartment'))
                            ->options(fn (): array => StorageCompartment::with('location')->get()
                                ->mapWithKeys(fn (StorageCompartment $c): array => [$c->id => $c->fullName()])
                                ->all())
                            ->searchable()
                            ->default(fn (): mixed => $this->selectedPart()?->storage_compartment_id)
                            ->helperText(__('warehouse.receive.help.compartment')),

                        Textarea::make('note')
                            ->label(__('warehouse.receive.field.note'))
                            ->rows(2),
                    ])
                    ->columns(2)
                    ->visible(fn (): bool => $this->selectedPart() !== null),
            ]);
    }

    public function submit(): void
    {
        $data = $this->form->getState();
        $part = PartType::find($data['part_type_id'] ?? null);

        if ($part === null) {
            return;
        }

        try {
            $movement = app(ReceiveStock::class)->handle(
                $part,
                (float) $data['quantity'],
                $data['received_at'],
                auth()->user(),
                $data,
            );
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title(__('warehouse.receive.notification.refused'))
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        $lot = $movement->lot;

        if ($lot !== null && filled($data['document_file'] ?? null)) {
            $this->attachDocument($lot, $data['document_file']);
        }

        $notification = Notification::make()
            ->success()
            ->title(__('warehouse.receive.notification.done'));

        if ($lot !== null) {
            $notification->body(__('warehouse.receive.notification.lot', ['lot' => $lot->lot_number]));

            /*
             * ─────────────────────────────────────────────────────────────────
             * DAS ETIKETT DIREKT HIER, nicht erst über den Bestand.
             *
             * Feldtest: „um losaufkleber zu drucken muss ich erst einbuchen und
             * dann im bestand den druck auswählen. das sollte direkt beim
             * einbuchen gehen."
             *
             * Das ist die Reihenfolge, in der es tatsächlich passiert: Die Ware
             * liegt auf dem Tisch, das Los ist gerade entstanden, der Aufkleber
             * gehört jetzt darauf -- nicht nach einem Umweg über eine Liste, in
             * der man das eben angelegte Los erst wiederfinden muss.
             *
             * In einem neuen Tab, damit die Einbuchungsmaske stehen bleibt: Wer
             * eine Lieferung annimmt, bucht selten genau ein Los ein.
             * ─────────────────────────────────────────────────────────────────
             */
            $notification->actions([
                Action::make('etikett')
                    ->label(__('warehouse.receive.notification.print_label'))
                    ->icon('heroicon-o-printer')
                    ->url(route('warehouse.label.print', ['lots' => $lot->getKey()]))
                    ->openUrlInNewTab(),
            ]);

            // Goods without the certificate they need land in quarantine rather
            // than on the usable shelf. Say so plainly, because it is not what
            // the person booking them in expected.
            if ($lot->state->value !== 'serviceable') {
                Notification::make()
                    ->warning()
                    ->title(__('warehouse.receive.notification.quarantined_title'))
                    ->body(__('warehouse.receive.notification.quarantined_body'))
                    ->persistent()
                    ->send();
            }
        }

        $notification->send();

        $this->form->fill(['received_at' => now()->toDateString()]);
    }

    /** @return list<Action> */
    public function getFormActions(): array
    {
        return [
            Action::make('submit')
                ->label(__('warehouse.receive.action'))
                ->submit('submit'),
        ];
    }

    /**
     * Moves the uploaded file into the lot's document collection.
     *
     * Filament has already written it to the private disk; this hands it to the
     * media library so it gets a generated name, a recorded original name and a
     * single route that can check who is asking.
     */
    private function attachDocument(StockLot $lot, string $path): void
    {
        $absolute = Storage::disk('documents')->path($path);

        if (! is_file($absolute)) {
            return;
        }

        $lot->addMedia($absolute)
            ->usingFileName(basename($path))
            ->toMediaCollection(StockLot::DOCUMENTS, 'documents');
    }

    private function selectedPart(): ?PartType
    {
        $id = $this->data['part_type_id'] ?? null;

        return $id !== null ? PartType::find($id) : null;
    }

    private function expiryHint(): ?string
    {
        $part = $this->selectedPart();
        $receivedAt = $this->data['received_at'] ?? null;

        if ($part === null || $receivedAt === null || $part->shelf_life_days === null) {
            return null;
        }

        return __('warehouse.receive.help.expires', [
            'date' => Carbon::parse($part->expiryFor($receivedAt))->format('d.m.Y'),
            'days' => $part->shelf_life_days,
        ]);
    }

    private function resetLotFields(): void
    {
        foreach (['serial_number', 'document_reference', 'batch_number', 'document_issuer',
            'document_issuer_approval', 'document_issued_at', 'document_signatory'] as $field) {
            unset($this->data[$field]);
        }

        $part = $this->selectedPart();
        $this->data['quantity'] = $part?->serial_tracked ? 1 : null;
        $this->data['storage_compartment_id'] = $part?->storage_compartment_id;
        $this->data['document_type'] = $part?->requires_form_one ? StockLot::DOCUMENT_FORM_ONE : StockLot::DOCUMENT_NONE;
    }
}
