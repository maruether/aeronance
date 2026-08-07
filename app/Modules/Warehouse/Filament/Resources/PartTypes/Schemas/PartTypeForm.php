<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\PartTypes\Schemas;

use App\Modules\Warehouse\Enums\LifeLimitType;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StorageCompartment;
use App\Modules\Warehouse\Support\UnitsOfMeasure;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The part type form.
 *
 * The classification comes first and is required, because it is the decision
 * with regulatory weight: it determines what evidence the part needs. The
 * legacy system had a single "Form One" tick and no notion of consumable
 * material at all.
 *
 * Three settings further down decide, between them, whether this part is kept
 * in lots or simply counted -- form 1, serial number, shelf life. That is
 * derived rather than asked separately, so the two can never contradict each
 * other.
 */
final class PartTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('warehouse.part_type.section.identity'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('warehouse.part_type.field.name'))
                        ->required()
                        ->maxLength(128)
                        ->unique(ignoreRecord: true),

                    Select::make('classification')
                        ->label(__('warehouse.part_type.field.classification'))
                        ->options(fn (): array => collect(PartClassification::cases())
                            ->mapWithKeys(fn (PartClassification $c): array => [$c->value => $c->label()])
                            ->all())
                        ->default(PartClassification::Component->value)
                        ->required()
                        ->live()
                        ->helperText(__('warehouse.part_type.help.classification')),

                    TextInput::make('ipc_part_number')
                        ->label(__('warehouse.part_type.field.ipc_part_number'))
                        ->maxLength(128)
                        ->helperText(__('warehouse.part_type.help.ipc_part_number')),

                    Textarea::make('description')
                        ->label(__('warehouse.part_type.field.description'))
                        ->rows(3),
                ])
                ->columns(2),

            Section::make(__('warehouse.part_type.section.evidence'))
                ->schema([
                    Checkbox::make('requires_form_one')
                        ->label(__('warehouse.part_type.field.requires_form_one'))
                        ->helperText(__('warehouse.part_type.help.requires_form_one'))
                        ->default(fn (callable $get): bool => PartClassification::tryFrom((string) $get('classification'))
                            ?->normallyNeedsFormOne() ?? false),

                    Checkbox::make('serial_tracked')
                        ->label(__('warehouse.part_type.field.serial_tracked'))
                        ->helperText(__('warehouse.part_type.help.serial_tracked')),

                    TextInput::make('shelf_life_days')
                        ->label(__('warehouse.part_type.field.shelf_life_days'))
                        ->numeric()
                        ->minValue(1)
                        ->suffix(__('warehouse.unit.days'))
                        // Empty means "no limit". No sentinel values -- the
                        // legacy schema used -1 and every read had to know it.
                        ->helperText(__('warehouse.part_type.help.shelf_life_days')),

                    // Decides one thing only, but a consequential one: whether a
                    // part taken out of an aircraft may go back on the shelf.
                    // Spark plugs are replaced (TBR) and never return; a tow
                    // release is overhauled (TBO) and does. Lumping both under
                    // "life-limited" would have blocked the release along with
                    // the plugs.
                    Select::make('life_limit_type')
                        ->label(__('warehouse.part_type.field.life_limit_type'))
                        ->options(fn (): array => collect(LifeLimitType::cases())
                            ->mapWithKeys(fn (LifeLimitType $t): array => [$t->value => $t->label()])
                            ->all())
                        ->default(LifeLimitType::None->value)
                        ->selectablePlaceholder(false)
                        ->helperText(__('warehouse.part_type.help.life_limit_type')),
                ])
                ->columns(2),

            Section::make(__('warehouse.part_type.section.stock'))
                ->schema([
                    Select::make('storage_compartment_id')
                        ->label(__('warehouse.part_type.field.compartment'))
                        ->options(fn (): array => StorageCompartment::with('location')
                            ->get()
                            ->mapWithKeys(fn (StorageCompartment $c): array => [$c->id => $c->fullName()])
                            ->all())
                        ->searchable(),

                    /*
                     * ─────────────────────────────────────────────────────────
                     * AUSWAHL STATT FREIEM TEXT -- Vorgabe (F17): "kann ne liste
                     * sein, wir muessen aber alles abdecken."
                     *
                     * Der eigene Wert bleibt trotzdem erreichbar. Eine feste
                     * Liste, die etwas nicht kennt, fuehrt dazu, dass jemand
                     * "St" nimmt und die wahre Einheit in die Bezeichnung
                     * schreibt -- dann steht sie an einer Stelle, an der keine
                     * Auswertung sie findet.
                     *
                     * optionsIncluding() haelt eine selbst eingetragene
                     * Einheit in der Liste. Ohne das verschwaende sie nach dem
                     * Speichern aus der Auswahl, und das naechste Bearbeiten
                     * ersetzte sie stillschweigend durch eine andere.
                     * ─────────────────────────────────────────────────────────
                     */
                    Select::make('unit_of_measure')
                        ->label(__('warehouse.part_type.field.unit'))
                        ->options(fn (?PartType $record): array => UnitsOfMeasure::optionsIncluding(
                            $record?->unit_of_measure,
                        ))
                        ->default('St')
                        ->required()
                        ->searchable()
                        ->native(false)
                        ->helperText(__('warehouse.part_type.help.unit'))
                        ->createOptionForm([
                            TextInput::make('unit_of_measure')
                                ->label(__('warehouse.part_type.field.unit_own'))
                                ->required()
                                ->maxLength(16)
                                ->helperText(__('warehouse.part_type.help.unit_own')),
                        ])
                        // Es entsteht kein Datensatz -- die Einheit IST ihr
                        // Wert. Zurueck kommt deshalb schlicht der Text.
                        ->createOptionUsing(fn (array $data): string => trim((string) $data['unit_of_measure'])),

                    TextInput::make('minimum_stock')
                        ->label(__('warehouse.part_type.field.minimum_stock'))
                        ->numeric()
                        ->minValue(0),

                    TextInput::make('maximum_stock')
                        ->label(__('warehouse.part_type.field.maximum_stock'))
                        ->numeric()
                        ->minValue(0)
                        ->gte('minimum_stock'),
                ])
                ->columns(2),

            Section::make(__('warehouse.part_type.section.procurement'))
                ->description(__('warehouse.part_type.help.procurement'))
                ->schema([
                    Select::make('supplier_id')
                        ->label(__('warehouse.part_type.field.supplier'))
                        ->relationship('supplier', 'name')
                        ->searchable(),

                    TextInput::make('order_code')
                        ->label(__('warehouse.part_type.field.order_code'))
                        ->maxLength(128),

                    TextInput::make('net_purchase_price')
                        ->label(__('warehouse.part_type.field.price'))
                        ->numeric()
                        ->prefix('€'),
                ])
                ->columns(3)
                ->collapsed(),
        ]);
    }
}
