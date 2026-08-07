<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\Aircraft\Schemas;

use App\Modules\Fleet\Enums\CounterKind;
use App\Modules\Fleet\Models\AircraftType;
use App\Modules\Fleet\Models\Holder;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * The aircraft form.
 *
 * The one thing worth pointing out is the counters. Flight time and landings are
 * NOT offered here, and their absence is the feature: they are required by law,
 * so there is no box to untick. The section says so rather than leaving somebody
 * to wonder where they went.
 */
final class AircraftForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('fleet.aircraft.singular'))
                ->schema([
                    TextInput::make('registration')
                        ->label(__('fleet.aircraft.field.registration'))
                        ->required()
                        ->maxLength(32)
                        ->unique(ignoreRecord: true)
                        ->helperText(__('fleet.aircraft.help.registration')),

                    /*
                     * The catalogued type, with the free-text model beneath it.
                     *
                     * Both, deliberately. Picking a type is what makes the LTA/TM
                     * matching exact and gives the aircraft its Kennblatt; typing
                     * a name still has to work for anything nobody catalogued.
                     * the requirement was for the free text to stay, and it does.
                     *
                     * createOptionForm rather than a plain select: the type a
                     * person needs is usually missing precisely when they are
                     * entering the aircraft, and sending them to another screen
                     * to add it is how a field gets left empty.
                     */
                    Select::make('aircraft_type_id')
                        ->label(__('fleet.type.singular'))
                        ->relationship('aircraftType', 'designation')
                        ->searchable()
                        ->preload()
                        ->helperText(__('fleet.aircraft.help.type'))
                        ->createOptionForm([
                            TextInput::make('designation')
                                ->label(__('fleet.type.field.designation'))
                                ->required()
                                ->maxLength(96)
                                ->unique(table: 'aircraft_types'),

                            TextInput::make('manufacturer')
                                ->label(__('fleet.type.field.manufacturer'))
                                ->maxLength(160),

                            TextInput::make('type_certificate')
                                ->label(__('fleet.type.field.type_certificate'))
                                ->maxLength(64)
                                ->helperText(__('fleet.type.help.lookup_later')),
                        ])
                        /*
                         * Choosing a type fills the model in, so the two do not
                         * silently disagree -- the free-text field stays editable
                         * for the club that spells it differently on purpose.
                         *
                         * What to write lives on the model (AircraftType::prefill),
                         * not here: logic inside a form closure cannot be tested,
                         * and this decides what a person sees in two fields.
                         */
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            $type = $state !== null ? AircraftType::find($state) : null;

                            if ($type === null) {
                                return;
                            }

                            foreach ($type->prefill() as $attribute => $value) {
                                $set($attribute, $value);
                            }
                        })
                        ->live(),

                    TextInput::make('model')
                        ->label(__('fleet.aircraft.field.model'))
                        ->required()
                        ->maxLength(96)
                        ->helperText(__('fleet.aircraft.help.model_free_text')),

                    TextInput::make('manufacturer')
                        ->label(__('fleet.aircraft.field.manufacturer'))
                        ->maxLength(96),

                    TextInput::make('serial_number')
                        ->label(__('fleet.aircraft.field.serial_number'))
                        ->maxLength(96),

                    TextInput::make('year_built')
                        ->label(__('fleet.aircraft.field.year_built'))
                        ->numeric()
                        ->minValue(1900)
                        ->maxValue((int) date('Y') + 1),

                    Select::make('holder_id')
                        ->label(__('fleet.aircraft.field.holder'))
                        ->options(fn (): array => Holder::orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->helperText(__('fleet.holder.help.why')),
                ])
                ->columns(2),

            Section::make(__('fleet.aircraft.field.optional_counters'))
                ->description(__('fleet.aircraft.help.mandatory_counters'))
                ->schema([
                    CheckboxList::make('optional_counters')
                        ->hiddenLabel()
                        ->options(fn (): array => collect(CounterKind::optional())
                            ->mapWithKeys(fn (CounterKind $k): array => [$k->value => $k->label()])
                            ->all())
                        ->helperText(__('fleet.aircraft.help.optional_counters'))
                        ->columns(3),
                ]),

            Section::make(__('fleet.aircraft.field.is_active'))
                ->schema([
                    Toggle::make('is_active')
                        ->label(__('fleet.aircraft.field.is_active'))
                        ->default(true),

                    DatePicker::make('in_service_since')
                        ->label(__('fleet.aircraft.field.in_service_since')),

                    Textarea::make('note')
                        ->label(__('fleet.aircraft.field.note'))
                        ->rows(2)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->collapsed(),
        ]);
    }
}
