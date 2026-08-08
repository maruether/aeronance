<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Pages;

use App\Modules\Warehouse\Actions\IssueStock;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Throwable;

/**
 * Taking stock out.
 *
 * Two behaviours here come straight from how the work is actually done (F26):
 *
 *  - For quantity-tracked parts the lot that expires first is preselected, so
 *    nothing quietly ages out on the shelf. Choosing another needs no
 *    justification: traceability hangs on the certificate recorded against the
 *    lot, not on which lot was picked.
 *  - For serialised parts nothing is preselected. The serial number is asked
 *    for outright, because there the choice IS the identification -- offering a
 *    default would invite confirming the wrong part.
 *
 * Where the part went can be recorded as a work order and an aircraft. Both are
 * free text: those modules need not be installed, and the warehouse has to work
 * on its own (D4).
 */
final class IssueStockPage extends Page
{
    protected string $view = 'warehouse.filament.pages.issue-stock';

    protected static ?string $slug = 'entnehmen';

    protected static ?int $navigationSort = 11;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.warehouse');
    }

    public static function getNavigationLabel(): string
    {
        return __('warehouse.issue.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('warehouse.issue.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('warehouse.issue.subheading');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedArrowUpOnSquare;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permissions::STOCK_ISSUE) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('warehouse.issue.section.what'))
                    ->schema([
                        Select::make('part_type_id')
                            ->label(__('warehouse.part_type.singular'))
                            ->options(fn (): array => PartType::orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn () => $this->preselectLot())
                            ->helperText(fn (): ?string => $this->stockHint()),

                        Select::make('stock_lot_id')
                            ->label(__('warehouse.issue.field.lot'))
                            ->options(fn (): array => $this->availableLots())
                            ->searchable()
                            ->required(fn (): bool => (bool) $this->selectedPart()?->isLotTracked())
                            ->visible(fn (): bool => (bool) $this->selectedPart()?->isLotTracked())
                            ->helperText(fn (): ?string => $this->lotHint()),

                        TextInput::make('quantity')
                            ->label(__('warehouse.issue.field.quantity'))
                            ->numeric()
                            ->minValue(0.001)
                            ->required()
                            ->suffix(fn (): ?string => $this->selectedPart()?->unit_of_measure)
                            ->default(fn (): ?float => $this->selectedPart()?->serial_tracked ? 1 : null),
                    ])
                    ->columns(2),

                Section::make(__('warehouse.issue.section.where'))
                    ->description(__('warehouse.issue.help.destination'))
                    ->schema([
                        TextInput::make('aircraft_reference')
                            ->label(__('warehouse.issue.field.aircraft'))
                            ->maxLength(32)
                            ->placeholder('D-KABC')
                            // The lot list depends on this, so the field cannot
                            // be a write-only afterthought at the bottom.
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn () => $this->aircraftChanged()),

                        TextInput::make('work_order_reference')
                            ->label(__('warehouse.issue.field.work_order'))
                            ->maxLength(64),

                        Textarea::make('note')
                            ->label(__('warehouse.issue.field.note'))
                            ->rows(2)
                            ->columnSpanFull(),
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
            // Auf Namen: Vier ?string-Parameter nebeneinander -- ein Dreher
            // oder ein Einschub verschiebt positionsgebunden alles still.
            app(IssueStock::class)->handle(
                partType: $part,
                quantity: (float) $data['quantity'],
                lot: isset($data['stock_lot_id']) ? StockLot::find($data['stock_lot_id']) : null,
                user: auth()->user(),
                workOrderReference: $data['work_order_reference'] ?? null,
                aircraftReference: $data['aircraft_reference'] ?? null,
                note: $data['note'] ?? null,
            );
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title(__('warehouse.issue.notification.refused'))
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('warehouse.issue.notification.done'))
            ->send();

        $this->form->fill();
    }

    /** @return list<Action> */
    public function getFormActions(): array
    {
        return [
            Action::make('submit')
                ->label(__('warehouse.issue.action'))
                ->submit('submit'),
        ];
    }

    private function selectedPart(): ?PartType
    {
        $id = $this->data['part_type_id'] ?? null;

        return $id !== null ? PartType::find($id) : null;
    }

    /**
     * @return array<int, string>
     */
    private function availableLots(): array
    {
        $part = $this->selectedPart();

        if ($part === null) {
            return [];
        }

        $aircraft = $this->data['aircraft_reference'] ?? null;

        return $part->lots()->issuable()->fefo()->get()
            // A lot tied to another aircraft is left out rather than shown and
            // then refused. It is not hidden silently: the hint under the field
            // says how many were dropped and why, so nobody hunts for a lot they
            // know is on the shelf.
            ->filter(fn (StockLot $lot): bool => $lot->mayBeFittedTo($aircraft))
            ->mapWithKeys(fn (StockLot $lot): array => [
                $lot->id => sprintf(
                    '%s — %s %s%s%s',
                    $lot->label(),
                    rtrim(rtrim(number_format($lot->remainingQuantity(), 3, ',', '.'), '0'), ','),
                    $part->unit_of_measure,
                    $lot->expires_at !== null
                        ? ', '.__('warehouse.issue.expires_on', ['date' => $lot->expires_at->format('d.m.Y')])
                        : '',
                    $lot->isRestrictedToItsAircraft()
                        ? ', '.__('warehouse.issue.only_for', ['aircraft' => $lot->removed_from_aircraft])
                        : '',
                ),
            ])
            ->all();
    }

    /**
     * Lots kept out of the list because they belong to another aircraft.
     */
    private function restrictedLotCount(): int
    {
        $part = $this->selectedPart();

        if ($part === null) {
            return 0;
        }

        $aircraft = $this->data['aircraft_reference'] ?? null;

        return $part->lots()->issuable()->fefo()->get()
            ->reject(fn (StockLot $lot): bool => $lot->mayBeFittedTo($aircraft))
            ->count();
    }

    /**
     * What to say under the lot field.
     *
     * Three different situations, and conflating them is what makes a screen
     * feel broken: nothing on the shelf, something on the shelf that this
     * aircraft may not have, and the ordinary case.
     */
    private function lotHint(): ?string
    {
        $part = $this->selectedPart();

        if ($part === null) {
            return null;
        }

        $hidden = $this->restrictedLotCount();

        if ($hidden > 0) {
            $aircraft = $this->data['aircraft_reference'] ?? null;

            return filled($aircraft)
                ? __('warehouse.issue.help.restricted_hidden', ['n' => $hidden, 'aircraft' => $aircraft])
                : __('warehouse.issue.help.restricted_no_aircraft', ['n' => $hidden]);
        }

        return $part->serial_tracked
            ? __('warehouse.issue.help.pick_serial')
            : __('warehouse.issue.help.fefo');
    }

    private function preselectLot(): void
    {
        $part = $this->selectedPart();

        $this->data['quantity'] = $part?->serial_tracked ? 1 : null;

        // Nothing is suggested for serialised parts on purpose -- see the class
        // comment and F26.
        $this->data['stock_lot_id'] = $part !== null
            ? app(IssueStock::class)->suggestLot($part, $this->data['aircraft_reference'] ?? null)?->id
            : null;
    }

    /**
     * The aircraft decides which lots are eligible, so entering it late has to
     * put right what was chosen early.
     *
     * A lot already picked that this aircraft may not have is dropped and said
     * so -- leaving it selected would mean a refusal at booking with the field
     * still showing a perfectly plausible lot.
     */
    private function aircraftChanged(): void
    {
        $part = $this->selectedPart();
        $lotId = $this->data['stock_lot_id'] ?? null;

        if ($part === null) {
            return;
        }

        $lot = $lotId !== null ? StockLot::find($lotId) : null;
        $aircraft = $this->data['aircraft_reference'] ?? null;

        if ($lot !== null && ! $lot->mayBeFittedTo($aircraft)) {
            $this->data['stock_lot_id'] = null;

            Notification::make()
                ->warning()
                ->title(__('warehouse.issue.notification.lot_dropped', ['lot' => $lot->lot_number]))
                ->body(__('warehouse.removal.help.restriction'))
                ->persistent()
                ->send();
        }

        if (($this->data['stock_lot_id'] ?? null) === null) {
            $this->data['stock_lot_id'] = app(IssueStock::class)->suggestLot($part, $aircraft)?->id;
        }
    }

    private function stockHint(): ?string
    {
        $part = $this->selectedPart();

        if ($part === null) {
            return null;
        }

        return __('warehouse.issue.help.available', [
            'quantity' => rtrim(rtrim(number_format($part->availableStock(), 3, ',', '.'), '0'), ','),
            'unit' => $part->unit_of_measure,
        ]);
    }
}
