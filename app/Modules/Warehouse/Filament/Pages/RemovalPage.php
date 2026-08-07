<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Pages;

use App\Modules\Warehouse\Actions\RemovePartFromAircraft;
use App\Modules\Warehouse\Enums\LifeLimitType;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StorageCompartment;
use App\Modules\Warehouse\Permissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
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
 * Taking a part out of an aircraft and into the store.
 *
 * The screen says two things before anything is booked, because both come as a
 * surprise otherwise: that declaring the part serviceable is a determination
 * needing a licence rather than a tick, and that without a Form 1 the part may
 * only go back into the aircraft it came from.
 */
final class RemovalPage extends Page
{
    protected string $view = 'warehouse.filament.pages.removal';

    protected static ?string $slug = 'ausbau';

    protected static ?int $navigationSort = 12;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.warehouse');
    }

    public static function getNavigationLabel(): string
    {
        return __('warehouse.removal.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('warehouse.removal.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('warehouse.removal.subheading');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedWrenchScrewdriver;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permissions::STOCK_RECEIVE) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(['removed_at' => now()->toDateString()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('warehouse.removal.section.what'))
                    ->schema([
                        Select::make('part_type_id')
                            ->label(__('warehouse.part_type.singular'))
                            ->options(fn (): array => PartType::orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required()
                            ->live()
                            ->helperText(fn (): ?string => $this->partHint()),

                        TextInput::make('quantity')
                            ->label(__('warehouse.receive.field.quantity'))
                            ->numeric()
                            ->minValue(0.001)
                            ->required()
                            ->default(1)
                            ->disabled(fn (): bool => (bool) $this->selectedPart()?->serial_tracked)
                            ->dehydrated()
                            ->suffix(fn (): ?string => $this->selectedPart()?->unit_of_measure),

                        TextInput::make('serial_number')
                            ->label(__('warehouse.lot.field.serial_number'))
                            ->maxLength(128)
                            ->required(fn (): bool => (bool) $this->selectedPart()?->serial_tracked)
                            ->visible(fn (): bool => (bool) $this->selectedPart()?->serial_tracked),

                        TextInput::make('aircraft')
                            ->label(__('warehouse.removal.field.aircraft'))
                            ->required()
                            ->maxLength(32)
                            ->placeholder('D-KABC')
                            ->live()
                            ->helperText(__('warehouse.removal.help.aircraft')),

                        TextInput::make('aircraft_type')
                            ->label(__('warehouse.removal.field.aircraft_type'))
                            ->maxLength(64)
                            ->placeholder('ASK 21'),

                        DatePicker::make('removed_at')
                            ->label(__('warehouse.removal.field.removed_at'))
                            ->required()
                            ->default(now()),

                        Textarea::make('reason')
                            ->label(__('warehouse.removal.field.reason'))
                            ->required()
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(__('warehouse.removal.section.condition'))
                    ->description(__('warehouse.removal.help.serviceable'))
                    ->schema([
                        Checkbox::make('determined_serviceable')
                            ->label(__('warehouse.removal.field.serviceable'))
                            ->live(),
                    ])
                    ->visible(fn (): bool => $this->selectedPart() !== null),

                Section::make(__('warehouse.removal.section.where'))
                    ->schema([
                        Select::make('storage_compartment_id')
                            ->label(__('warehouse.part_type.field.compartment'))
                            ->options(fn (): array => StorageCompartment::with('location')->get()
                                ->mapWithKeys(fn (StorageCompartment $c): array => [$c->id => $c->fullName()])
                                ->all())
                            ->searchable()
                            ->default(fn (): mixed => $this->selectedPart()?->storage_compartment_id),
                    ])
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
            $lot = app(RemovePartFromAircraft::class)->handle(
                $part,
                (float) $data['quantity'],
                (string) $data['aircraft'],
                auth()->user(),
                (string) $data['reason'],
                (bool) ($data['determined_serviceable'] ?? false),
                $data['aircraft_type'] ?? null,
                $data['removed_at'] ?? null,
                $data,
            );
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title(__('warehouse.removal.notification.refused'))
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('warehouse.removal.notification.stored', ['lot' => $lot->lot_number]))
            ->send();

        // Both of these surprise people, so they are said plainly and stay on
        // screen rather than sliding away.
        if ($lot->state->value !== 'serviceable') {
            Notification::make()
                ->warning()
                ->title(__('warehouse.removal.notification.quarantined'))
                ->persistent()
                ->send();
        } elseif ($lot->isRestrictedToItsAircraft()) {
            Notification::make()
                ->info()
                ->title(__('warehouse.removal.notification.restricted', [
                    'aircraft' => $lot->removed_from_aircraft,
                ]))
                ->body(__('warehouse.removal.help.restriction'))
                ->persistent()
                ->send();
        }

        $this->form->fill(['removed_at' => now()->toDateString()]);
    }

    /** @return list<Action> */
    public function getFormActions(): array
    {
        return [
            Action::make('submit')
                ->label(__('warehouse.removal.action'))
                ->submit('submit'),
        ];
    }

    private function selectedPart(): ?PartType
    {
        $id = $this->data['part_type_id'] ?? null;

        return $id !== null ? PartType::find($id) : null;
    }

    /**
     * Warns about a replacement-interval part before the form is filled in
     * rather than after it is submitted.
     */
    private function partHint(): ?string
    {
        $part = $this->selectedPart();

        if ($part === null) {
            return null;
        }

        if (! $part->allowsReuseAfterRemoval()) {
            return __('warehouse.removal.help.tbr');
        }

        return $part->life_limit_type !== LifeLimitType::None
            ? $part->life_limit_type->label()
            : null;
    }
}
