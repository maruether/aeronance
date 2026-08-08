<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\Aircraft\Tables;

use App\Modules\Fleet\Actions\OnboardAircraft;
use App\Modules\Fleet\Enums\CounterKind;
use App\Modules\Fleet\Filament\Resources\Aircraft\AircraftResource;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\CounterReading;
use App\Modules\Fleet\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Throwable;

/**
 * The fleet list.
 *
 * Reading an instrument and writing the figure down is the commonest thing
 * anybody does here, so it sits on the row rather than behind a screen of its
 * own -- the same reasoning as moving a lot in the warehouse.
 */
final class AircraftTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('registration')
            ->columns([
                TextColumn::make('registration')
                    ->label(__('fleet.aircraft.field.registration'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Aircraft $r): string => $r->model),

                TextColumn::make('holder.name')
                    ->label(__('fleet.aircraft.field.holder'))
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),

                // "—" statt 0: null heisst "nie abgelesen", und eine Null an
                // dieser Stelle sieht aus wie ein fabrikneues Luftfahrzeug.
                TextColumn::make('flight_hours')
                    ->label(__('fleet.counter.flight_hours'))
                    ->alignEnd()
                    ->state(fn (Aircraft $r): string => ($v = $r->currentValue(CounterKind::FlightHours)) === null
                        ? '—'
                        : number_format($v, 2, ',', '.').' h'),

                TextColumn::make('landings')
                    ->label(__('fleet.counter.landings'))
                    ->alignEnd()
                    ->state(fn (Aircraft $r): string => ($v = $r->currentValue(CounterKind::Landings)) === null
                        ? '—'
                        : number_format($v, 0, ',', '.')),

                TextColumn::make('review')
                    ->label(__('fleet.review.singular'))
                    ->badge()
                    ->state(fn (Aircraft $r): string => $r->currentReview()?->valid_until->format('d.m.Y')
                        ?? __('fleet.due.no_review'))
                    // Red for nothing on file as well as for expired: an
                    // aircraft with no ARC is not an aircraft with nothing
                    // expiring.
                    ->color(fn (Aircraft $r): string => match (true) {
                        $r->currentReview() === null => 'danger',
                        ! $r->currentReview()->isValid() => 'danger',
                        $r->currentReview()->daysRemaining() <= 60 => 'warning',
                        default => 'success',
                    }),

                IconColumn::make('is_active')
                    ->label(__('fleet.aircraft.field.is_active'))
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('fleet.aircraft.field.is_active'))
                    ->default(true),
            ])
            ->recordUrl(fn (Aircraft $record): string => AircraftResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                self::recordReadingAction(),
                self::arrivalCountersAction(),
            ]);
    }

    /**
     * The counters an aircraft arrives with.
     *
     * Separate from an ordinary reading because it is a different act: it sets
     * where this operation's record of the aircraft STARTS, and it stamps the
     * onboarding date. Doing it as a normal reading would work and would lose
     * that -- and an aircraft whose history begins at zero is one whose
     * components all look brand new.
     *
     * Offered only while nothing has been read yet, so it cannot be used to
     * quietly restate an aircraft that has been on the books for years.
     */
    private static function arrivalCountersAction(): Action
    {
        return Action::make('arrivalCounters')
            ->label(__('fleet.onboarding.title'))
            ->icon('heroicon-o-inbox-arrow-down')
            ->visible(fn (Aircraft $record): bool => $record->onboarded_at === null
                && $record->readings()->doesntExist()
                && (auth()->user()?->can(Permissions::COUNTERS_RECORD) ?? false))
            ->modalDescription(__('fleet.onboarding.help.what'))
            ->schema(fn (Aircraft $record): array => array_merge(
                [
                    DatePicker::make('onboarded_at')
                        ->label(__('fleet.onboarding.field.onboarded_at'))
                        ->default(now())
                        ->required(),
                ],
                array_map(
                    fn (CounterKind $kind) => TextInput::make('counter_'.$kind->value)
                        ->label($kind->label())
                        ->numeric()
                        ->minValue(0)
                        ->suffix($kind->unit()),
                    $record->counters(),
                ),
            ))
            ->action(function (Aircraft $record, array $data): void {
                $readings = [];

                foreach ($record->counters() as $kind) {
                    $value = $data['counter_'.$kind->value] ?? null;

                    if (filled($value)) {
                        $readings[$kind->value] = (float) $value;
                    }
                }

                app(OnboardAircraft::class)->recordArrivalCounters(
                    $record,
                    $readings,
                    auth()->user(),
                    $data['onboarded_at'] ?? null,
                );

                Notification::make()
                    ->success()
                    ->title(__('fleet.onboarding.title'))
                    ->send();
            });
    }

    /**
     * Writing down what the instrument said.
     */
    private static function recordReadingAction(): Action
    {
        return Action::make('reading')
            ->label(__('fleet.reading.singular'))
            ->icon('heroicon-o-clock')
            ->visible(fn (): bool => auth()->user()?->can(Permissions::COUNTERS_RECORD) ?? false)
            ->modalDescription(__('fleet.reading.help.append_only'))
            ->schema(fn (Aircraft $record): array => [
                Select::make('kind')
                    ->label(__('fleet.reading.field.kind'))
                    ->options(collect($record->counters())
                        ->mapWithKeys(fn (CounterKind $k): array => [$k->value => $k->label()])
                        ->all())
                    ->default(CounterKind::FlightHours->value)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live(),

                TextInput::make('value')
                    ->label(__('fleet.reading.field.value'))
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->helperText(__('fleet.reading.help.absolute')),

                DatePicker::make('read_at')
                    ->label(__('fleet.reading.field.read_at'))
                    ->default(now())
                    ->required(),

                Textarea::make('note')
                    ->label(__('fleet.reading.field.note'))
                    ->rows(2),
            ])
            ->action(function (Aircraft $record, array $data): void {
                try {
                    CounterReading::create([
                        'aircraft_id' => $record->id,
                        'kind' => $data['kind'],
                        'value' => $data['value'],
                        'read_at' => $data['read_at'],
                        'user_id' => auth()->id(),
                        'note' => $data['note'] ?? null,
                    ]);
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title(__('fleet.reading.singular'))->send();
            });
    }
}
