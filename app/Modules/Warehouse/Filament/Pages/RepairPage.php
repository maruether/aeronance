<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Pages;

use App\Core\Modules\ModuleManager;
use App\Modules\Warehouse\Actions\DispatchForRepair;
use App\Modules\Warehouse\Enums\RepairDestination;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\Supplier;
use App\Modules\Warehouse\Permissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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
 * Sending a part away to be repaired.
 *
 * A third way out of the store, and neither issuing nor scrapping. What the
 * screen has to make plain, because it is the reason the function exists: a
 * part tied to one aircraft is freed by the certificate that comes back, and by
 * nothing else the club can do itself.
 *
 * Note which lots are offered. Unlike the issue screen this deliberately shows
 * quarantined and unserviceable ones -- those are the normal case here, and a
 * repair screen that hid them would be a repair screen for parts that do not
 * need repairing.
 */
final class RepairPage extends Page
{
    protected string $view = 'warehouse.filament.pages.repair';

    protected static ?string $slug = 'reparatur';

    protected static ?int $navigationSort = 13;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.warehouse');
    }

    public static function getNavigationLabel(): string
    {
        return __('warehouse.repair.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('warehouse.repair.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('warehouse.repair.subheading');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedPaperAirplane;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permissions::STOCK_REPAIR) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'dispatched_at' => now()->toDateString(),
            'destination' => RepairDestination::External->value,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('warehouse.repair.section.what'))
                    ->schema([
                        Select::make('part_type_id')
                            ->label(__('warehouse.part_type.singular'))
                            ->options(fn (): array => PartType::orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn () => $this->data['stock_lot_id'] = null),

                        Select::make('stock_lot_id')
                            ->label(__('warehouse.issue.field.lot'))
                            ->options(fn (): array => $this->dispatchableLots())
                            ->searchable()
                            ->live()
                            ->required(fn (): bool => (bool) $this->selectedPart()?->isLotTracked())
                            ->visible(fn (): bool => (bool) $this->selectedPart()?->isLotTracked())
                            ->helperText(__('warehouse.repair.help.unserviceable_ok')),

                        TextInput::make('quantity')
                            ->label(__('warehouse.issue.field.quantity'))
                            ->numeric()
                            ->minValue(0.001)
                            ->required()
                            ->default(1)
                            ->suffix(fn (): ?string => $this->selectedPart()?->unit_of_measure),

                        Textarea::make('reason')
                            ->label(__('warehouse.repair.field.reason'))
                            ->required()
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(__('warehouse.repair.section.where'))
                    // Said before the booking, not after: if this part is tied
                    // to an aircraft, the certificate that comes back is what
                    // frees it, and choosing a shop that cannot issue one means
                    // the part comes back exactly as restricted as it left.
                    ->description(fn (): ?string => $this->restrictionHint())
                    ->schema([
                        Select::make('destination')
                            ->label(__('warehouse.repair.field.destination'))
                            ->options(fn (): array => collect(RepairDestination::available(app(ModuleManager::class)))
                                ->mapWithKeys(fn (RepairDestination $d): array => [$d->value => $d->label()])
                                ->all())
                            ->default(RepairDestination::External->value)
                            ->selectablePlaceholder(false)
                            ->live()
                            // With no component repair module installed there is
                            // exactly one destination, so asking is noise.
                            ->visible(fn (): bool => count(RepairDestination::available(app(ModuleManager::class))) > 1),

                        /*
                         * DER BETRIEB AUS DEM VERZEICHNIS, wenn es einen gibt.
                         * Dann werden Name und Zulassungsnummer von dort
                         * kopiert -- und eine abgelaufene Zulassung faellt beim
                         * Absenden auf, statt erst beim naechsten Audit.
                         *
                         * Der Freitext bleibt daneben: Nicht jede
                         * Instandsetzung geht an einen eingetragenen Betrieb,
                         * und ein Pflichtverzeichnis haette den Weg verbaut.
                         */
                        Select::make('supplier_id')
                            ->label(__('warehouse.repair.field.shop_from_register'))
                            ->options(fn (): array => Supplier::query()
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (Supplier $s): array => [
                                    $s->id => $s->labelWithApproval().($s->approvalHasLapsed()
                                        ? ' — '.__('warehouse.supplier.approval.lapsed', [
                                            'date' => $s->approval_expires_at?->format('d.m.Y') ?? '',
                                        ])
                                        : ''),
                                ])
                                ->all())
                            ->searchable()
                            ->live()
                            ->helperText(__('warehouse.repair.help.shop_from_register'))
                            ->visible(fn (callable $get): bool => ($get('destination') ?? RepairDestination::External->value)
                                === RepairDestination::External->value),

                        TextInput::make('shop_name')
                            ->label(__('warehouse.repair.field.shop_name'))
                            ->maxLength(160)
                            ->required(fn (callable $get): bool => blank($get('supplier_id'))
                                && ($get('destination') ?? RepairDestination::External->value)
                                === RepairDestination::External->value)
                            ->visible(fn (callable $get): bool => blank($get('supplier_id'))
                                && ($get('destination') ?? RepairDestination::External->value)
                                === RepairDestination::External->value),

                        TextInput::make('shop_approval')
                            ->label(__('warehouse.repair.field.shop_approval'))
                            ->maxLength(64)
                            ->placeholder('DE.145.0123')
                            ->helperText(__('warehouse.repair.help.shop_approval'))
                            ->visible(fn (callable $get): bool => blank($get('supplier_id'))
                                && ($get('destination') ?? RepairDestination::External->value)
                                === RepairDestination::External->value),

                        TextInput::make('dispatch_reference')
                            ->label(__('warehouse.repair.field.dispatch_reference'))
                            ->maxLength(128),

                        DatePicker::make('dispatched_at')
                            ->label(__('warehouse.repair.field.dispatched_at'))
                            ->default(now())
                            ->required(),

                        DatePicker::make('expected_back_at')
                            ->label(__('warehouse.repair.field.expected_back_at')),
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
            /*
             * BENANNT und nicht der Reihe nach: Diese Aktion hat inzwischen
             * zwoelf Parameter, und ein neuer in der Mitte hat den Aufruf hier
             * schon einmal still verschoben. Namen halten das aus.
             */
            $dispatch = app(DispatchForRepair::class)->handle(
                partType: $part,
                quantity: (float) $data['quantity'],
                lot: isset($data['stock_lot_id']) ? StockLot::find($data['stock_lot_id']) : null,
                user: auth()->user(),
                reason: (string) $data['reason'],
                destination: RepairDestination::from($data['destination'] ?? RepairDestination::External->value),
                shopName: $data['shop_name'] ?? null,
                shopApproval: $data['shop_approval'] ?? null,
                shop: isset($data['supplier_id']) ? Supplier::find($data['supplier_id']) : null,
                dispatchReference: $data['dispatch_reference'] ?? null,
                expectedBackAt: $data['expected_back_at'] ?? null,
                dispatchedAt: $data['dispatched_at'] ?? null,
            );
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title(__('warehouse.repair.notification.refused'))
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('warehouse.repair.notification.dispatched', [
                'part' => $dispatch->label(),
                'shop' => $dispatch->shop_name ?? $dispatch->destination->label(),
            ]))
            ->send();

        if ($dispatch->carriesAircraftRestriction()) {
            Notification::make()
                ->info()
                ->title(__('warehouse.repair.help.restriction_at_stake', [
                    'aircraft' => $dispatch->restricted_to_aircraft,
                ]))
                ->persistent()
                ->send();
        }

        $this->form->fill([
            'dispatched_at' => now()->toDateString(),
            'destination' => RepairDestination::External->value,
        ]);
    }

    /** @return list<Action> */
    public function getFormActions(): array
    {
        return [
            Action::make('submit')
                ->label(__('warehouse.repair.action'))
                ->submit('submit'),
        ];
    }

    private function selectedPart(): ?PartType
    {
        $id = $this->data['part_type_id'] ?? null;

        return $id !== null ? PartType::find($id) : null;
    }

    /**
     * Lots that may be sent.
     *
     * Everything with something left in it, EXCEPT what was determined beyond
     * repair -- that is one-way, and a repair trip would be the way back into
     * the supply system it exists to prevent. Quarantined and unserviceable
     * lots are offered on purpose: they are the reason for this screen.
     *
     * @return array<int, string>
     */
    private function dispatchableLots(): array
    {
        $part = $this->selectedPart();

        if ($part === null) {
            return [];
        }

        return $part->lots()
            ->whereNotIn('state', ['unsalvageable', 'disposed'])
            ->orderBy('lot_number')
            ->get()
            ->filter(fn (StockLot $lot): bool => $lot->remainingQuantity() > 0)
            ->mapWithKeys(fn (StockLot $lot): array => [
                $lot->id => sprintf(
                    '%s — %s %s (%s)%s',
                    $lot->label(),
                    rtrim(rtrim(number_format($lot->remainingQuantity(), 3, ',', '.'), '0'), ','),
                    $part->unit_of_measure,
                    $lot->state->label(),
                    $lot->isRestrictedToItsAircraft()
                        ? ', '.__('warehouse.issue.only_for', ['aircraft' => $lot->removed_from_aircraft])
                        : '',
                ),
            ])
            ->all();
    }

    private function restrictionHint(): ?string
    {
        $id = $this->data['stock_lot_id'] ?? null;
        $lot = $id !== null ? StockLot::find($id) : null;

        if ($lot === null || ! $lot->isRestrictedToItsAircraft()) {
            return __('warehouse.repair.help.form_one_lifts');
        }

        return __('warehouse.repair.help.restriction_at_stake', [
            'aircraft' => $lot->removed_from_aircraft,
        ]);
    }
}
