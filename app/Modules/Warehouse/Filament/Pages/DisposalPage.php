<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Pages;

use App\Modules\Warehouse\Actions\DisposeStock;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
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
use Illuminate\Support\Collection;
use Throwable;

/**
 * Booking stock out because it was destroyed.
 *
 * the two cases: expired resin, and parts finally disposed of out of the
 * quarantine store. Neither had a screen, and one of them had no path at all --
 * bulk stock could not be destroyed, only counted differently.
 *
 * The screen leads with what is already expired, because that is the commonest
 * reason to throw anything away and the easiest to overlook: expired stock sits
 * on the shelf looking exactly like the rest of it.
 *
 * Unlike the issue screen, quarantined and unserviceable lots are offered on
 * purpose. Those are most of what gets destroyed.
 */
final class DisposalPage extends Page
{
    protected string $view = 'warehouse.filament.pages.disposal';

    protected static ?string $slug = 'vernichten';

    protected static ?int $navigationSort = 15;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.warehouse');
    }

    public static function getNavigationLabel(): string
    {
        return __('warehouse.disposal.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('warehouse.disposal.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('warehouse.disposal.subheading');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedTrash;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permissions::STOCK_SCRAP) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(['occurred_at' => now()->toDateString()]);

        /*
         * Von "Was liegt an" hierher: Der Klick auf ein abgelaufenes Los
         * bringt seine Nummer mit, und das Formular steht schon darauf --
         * das versprochene "direkt beheben" statt einer Seite, auf der man
         * dasselbe Los noch einmal suchen muss.
         */
        $lotId = request()->integer('lot');

        if ($lotId > 0) {
            $this->pick($lotId);
        }
    }

    /**
     * What is already past its date, shown above the form.
     *
     * @return Collection<int, StockLot>
     */
    public function expiredLots(): Collection
    {
        return app(DisposeStock::class)->expiredLots();
    }

    /**
     * Fills the form from the expired list, so the commonest case is two clicks.
     */
    public function pick(int $lotId): void
    {
        $lot = StockLot::find($lotId);

        if ($lot === null) {
            return;
        }

        $this->form->fill([
            'part_type_id' => $lot->part_type_id,
            'stock_lot_id' => $lot->id,
            'quantity' => $lot->remainingQuantity(),
            'reason' => __('warehouse.disposal.expired_reason', [
                'date' => $lot->expires_at?->format('d.m.Y') ?? '',
            ]),
            'occurred_at' => now()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('warehouse.disposal.section.what'))
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
                            ->options(fn (): array => $this->disposableLots())
                            ->searchable()
                            ->live()
                            ->required(fn (): bool => (bool) $this->selectedPart()?->isLotTracked())
                            ->visible(fn (): bool => (bool) $this->selectedPart()?->isLotTracked())
                            ->helperText(__('warehouse.disposal.help.lots')),

                        TextInput::make('quantity')
                            ->label(__('warehouse.issue.field.quantity'))
                            ->numeric()
                            ->minValue(0.001)
                            ->required()
                            ->suffix(fn (): ?string => $this->selectedPart()?->unit_of_measure)
                            ->helperText(__('warehouse.disposal.help.partial')),

                        DatePicker::make('occurred_at')
                            ->label(__('warehouse.disposal.field.occurred_at'))
                            ->default(now())
                            ->required(),

                        Textarea::make('reason')
                            ->label(__('warehouse.movement.field.reason'))
                            ->required()
                            ->rows(2)
                            ->columnSpanFull()
                            ->helperText(__('warehouse.disposal.help.reason')),
                    ])
                    ->columns(2),
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
            app(DisposeStock::class)->handle(
                $part,
                (float) $data['quantity'],
                isset($data['stock_lot_id']) ? StockLot::find($data['stock_lot_id']) : null,
                auth()->user(),
                (string) $data['reason'],
                $data['occurred_at'] ?? null,
            );
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title(__('warehouse.disposal.notification.refused'))
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('warehouse.disposal.notification.done'))
            ->body(__('warehouse.disposal.notification.no_way_back'))
            ->persistent()
            ->send();

        $this->form->fill(['occurred_at' => now()->toDateString()]);
    }

    /** @return list<Action> */
    public function getFormActions(): array
    {
        return [
            Action::make('submit')
                ->label(__('warehouse.disposal.action'))
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(__('warehouse.disposal.notification.no_way_back'))
                ->action('submit'),
        ];
    }

    private function selectedPart(): ?PartType
    {
        $id = $this->data['part_type_id'] ?? null;

        return $id !== null ? PartType::find($id) : null;
    }

    /**
     * Lots that still hold something.
     *
     * Everything except what is already disposed of, quarantined and
     * unserviceable included -- those are most of what gets destroyed, and a
     * screen that hid them would be a disposal screen for usable parts.
     *
     * @return array<int, string>
     */
    private function disposableLots(): array
    {
        $part = $this->selectedPart();

        if ($part === null) {
            return [];
        }

        return $part->lots()
            ->where('state', '!=', 'disposed')
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
                    $lot->hasExpired()
                        ? ', '.__('warehouse.disposal.expired_on', [
                            'date' => $lot->expires_at->format('d.m.Y'),
                        ])
                        : '',
                ),
            ])
            ->all();
    }
}
