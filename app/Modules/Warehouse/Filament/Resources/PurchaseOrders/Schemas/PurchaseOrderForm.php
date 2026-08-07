<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\PurchaseOrders\Schemas;

use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\Supplier;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

/**
 * Was man eintraegt, nachdem man bestellt hat.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * BESTELLT WIRD AUSSERHALB. Bestellt wird am Telefon, per Mail oder im
 * Webshop und traegt hinterher ein, was er bekommen hat: Nummer, Lieferant,
 * zugesagtes Datum, Teile und Mengen. Dieses Formular fuehrt keine Bestellung
 * aus -- es haelt fest, worauf jemand wartet.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class PurchaseOrderForm
{
    /**
     * Das vorbelegte Lieferdatum zu einem Bestelldatum.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Vorgabe: „da einige lieferanten kein lieferdatum angeben, würde ich gerne
     * bestelldatum + 1 Woche als default einsetzen."
     *
     * Der Erinnerer hängt am zugesagten Datum. Ohne Vorbelegung gäbe es bei
     * genau den Lieferanten keine Erinnerung, die gar kein Datum nennen — also
     * bei dem, der sich nicht meldet, und das ist der Fall, um den es geht.
     *
     * ÖFFENTLICH, DAMIT ES PRÜFBAR IST -- wie UserResource::canLock. Der
     * Alternativweg wäre ein Test, der die halbe Anwendung neu baut, um ein
     * Formularfeld zu lesen; für eine Regel, die aus einem Datum ein Datum
     * macht, ist das der falsche Preis.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public static function defaultExpectedAt(string $orderedAt): string
    {
        return Carbon::parse($orderedAt)->addDays(self::frist())->toDateString();
    }

    /** Wie viele Tage nach der Bestellung das Lieferdatum vorbelegt wird. */
    private static function frist(): int
    {
        return max(1, (int) config('aeronance.orders.default_lead_days', 7));
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('warehouse.order.singular'))
                ->schema([
                    TextInput::make('order_number')
                        ->label(__('warehouse.order.field.number'))
                        ->maxLength(64)
                        ->helperText(__('warehouse.order.help.number')),

                    Select::make('supplier_id')
                        ->label(__('warehouse.order.field.supplier'))
                        ->options(fn (): array => Supplier::orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->required(),

                    DatePicker::make('ordered_at')
                        ->label(__('warehouse.order.field.ordered'))
                        ->required()
                        ->default(now())
                        ->live(onBlur: true)
                        /*
                         * Zieht das Lieferdatum mit -- aber NUR, wenn es leer
                         * ist oder noch auf der alten Vorbelegung steht. Wer
                         * ein zugesagtes Datum eingetragen hat, bekommt es
                         * nicht unter den Haenden weggerechnet, bloss weil er
                         * das Bestelldatum korrigiert.
                         */
                        ->afterStateUpdated(function ($state, $old, callable $get, callable $set): void {
                            $bisher = $get('expected_at');

                            $warVorbelegt = $bisher === null || $bisher === ''
                                || ($old !== null && $bisher === self::defaultExpectedAt($old));

                            if ($warVorbelegt && $state !== null) {
                                $set('expected_at', self::defaultExpectedAt($state));
                            }
                        }),

                    /*
                     * Das zugesagte Datum ist das Herz der Sache -- daran haengt
                     * die Erinnerung.
                     *
                     * VORBELEGT MIT BESTELLDATUM PLUS EINER WOCHE, weil viele
                     * Lieferanten gar kein Datum nennen. Ohne Vorbelegung gaebe
                     * es bei genau denen keine Erinnerung -- also bei dem
                     * Lieferanten, der sich nicht meldet, und das ist der Fall,
                     * um den es geht.
                     *
                     * Leeren bleibt moeglich und heisst weiterhin: nicht
                     * erinnern.
                     */
                    DatePicker::make('expected_at')
                        ->label(__('warehouse.order.field.expected'))
                        ->default(fn (): string => self::defaultExpectedAt(now()->toDateString()))
                        ->helperText(__('warehouse.order.help.expected')),

                    Textarea::make('note')
                        ->label(__('warehouse.order.field.note'))
                        ->rows(2)
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make(__('warehouse.order.field.lines'))
                ->description(__('warehouse.order.help.lines'))
                ->schema([
                    Repeater::make('lines')
                        ->hiddenLabel()
                        ->relationship()
                        ->schema([
                            Select::make('part_type_id')
                                ->label(__('warehouse.order.field.part'))
                                ->options(fn (): array => PartType::orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()
                                ->required()
                                ->columnSpan(2),

                            TextInput::make('quantity_ordered')
                                ->label(__('warehouse.order.field.quantity_ordered'))
                                ->numeric()
                                ->minValue(0.001)
                                ->required(),

                            /*
                             * Die gelieferte Menge steht hier NUR zum Ansehen.
                             * Sie entsteht durch das Einbuchen, und zwar ueber
                             * die Lageraktion -- wer sie hier von Hand
                             * hochsetzen koennte, haette Bestand behauptet, den
                             * keine Bewegung deckt.
                             */
                            TextInput::make('quantity_received')
                                ->label(__('warehouse.order.field.quantity_received'))
                                ->numeric()
                                ->disabled()
                                ->dehydrated(false),
                        ])
                        ->columns(4)
                        ->addActionLabel(__('warehouse.order.field.part'))
                        ->defaultItems(1)
                        ->reorderable(false),
                ]),
        ]);
    }
}
