<?php

declare(strict_types=1);

namespace App\Modules\Tooling\Filament\Resources\Tools\Schemas;

use App\Modules\Tooling\Enums\ToolState;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Ein Werkzeug anlegen.
 *
 * Das Fälligkeitsdatum steht NICHT in diesem Formular: Es entsteht aus einem
 * Kalibrierschein und sonst nirgends. Wäre es hier von Hand setzbar, wäre es
 * genau das Feld, das jemand „vorläufig" auf nächstes Jahr stellt.
 */
final class ToolForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('tooling.singular'))
                ->columns(2)
                ->schema([
                    TextInput::make('inventory_number')
                        ->label(__('tooling.field.inventory_number'))
                        ->required()
                        ->maxLength(64)
                        ->unique(ignoreRecord: true),

                    TextInput::make('name')
                        ->label(__('tooling.field.name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('manufacturer')
                        ->label(__('tooling.field.manufacturer'))
                        ->maxLength(255),

                    TextInput::make('model')
                        ->label(__('tooling.field.model'))
                        ->maxLength(255),

                    TextInput::make('serial_number')
                        ->label(__('tooling.field.serial_number'))
                        ->maxLength(64),

                    TextInput::make('location')
                        ->label(__('tooling.field.location'))
                        ->maxLength(255),

                    Select::make('state')
                        ->label(__('tooling.field.state'))
                        ->options(collect(ToolState::cases())
                            ->mapWithKeys(fn (ToolState $s): array => [$s->value => $s->label()])
                            ->all())
                        ->default(ToolState::InService->value)
                        ->required(),
                ]),

            Section::make(__('tooling.field.calibration_due_at'))
                ->columns(2)
                ->schema([
                    Toggle::make('calibration_required')
                        ->label(__('tooling.field.calibration_required'))
                        ->helperText(__('tooling.help.calibration_required'))
                        ->live()
                        ->columnSpanFull(),

                    TextInput::make('calibration_interval_months')
                        ->label(__('tooling.field.calibration_interval_months'))
                        ->helperText(__('tooling.help.interval'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(600)
                        ->visible(fn (Get $get): bool => (bool) $get('calibration_required')),

                    /*
                     * Kein Betaetigungszaehler, sondern der Text, worauf das
                     * Intervall beruht. EN ISO 6789 kennt fuer
                     * Drehmomentwerkzeuge "12 Monate ODER 5.000 Betaetigungen";
                     * ein Zaehler dafuer muesste bei jedem Handgriff gepflegt
                     * werden, und einer, den niemand hochzaehlt, ist eine Luege
                     * mit Nachkommastelle.
                     */
                    TextInput::make('calibration_basis')
                        ->label(__('tooling.field.calibration_basis'))
                        ->helperText(__('tooling.help.basis'))
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => (bool) $get('calibration_required')),
                ]),

            Section::make(__('tooling.field.note'))
                ->schema([
                    Textarea::make('note')
                        ->label(__('tooling.field.note'))
                        ->rows(3),
                ]),
        ]);
    }
}
