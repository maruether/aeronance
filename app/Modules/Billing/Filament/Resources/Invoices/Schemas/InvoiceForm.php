<?php

declare(strict_types=1);

namespace App\Modules\Billing\Filament\Resources\Invoices\Schemas;

use App\Modules\Billing\Actions\PriceList;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Enums\LineOrigin;
use App\Modules\Billing\Enums\TaxTreatment;
use App\Modules\Billing\Models\Customer;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * Der Rechnungsentwurf.
 *
 * Beträge stehen nicht im Formular: Sie werden beim Speichern gerechnet
 * (InvoiceCalculator), damit es eine Rechenregel gibt und nicht zwei. Was
 * der Mensch eingibt, sind Menge, Listenpreis, Rabatt und Steuersatz -- der
 * Rest ist Folge.
 */
final class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('billing.invoice.section.recipient'))
                ->columns(3)
                ->schema([
                    Select::make('customer_id')
                        ->label(__('billing.invoice.field.customer'))
                        ->helperText(__('billing.invoice.help.customer'))
                        ->relationship(
                            'customer',
                            'name',
                            fn (Builder $query): Builder => $query->where('is_active', true)->orderBy('name'),
                        )
                        ->getOptionLabelFromRecordUsing(fn (Customer $record): string => $record->label())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                            $customer = $state !== null ? Customer::query()->find($state) : null;

                            if ($customer === null) {
                                return;
                            }

                            // Nur Vorbelegen, was noch leer ist -- wer schon
                            // etwas eingetragen hat, meinte es.
                            if (blank($get('tax_treatment'))) {
                                $set('tax_treatment', $customer->effectiveTaxTreatment()->value);
                            }
                            if (blank($get('discount_percent')) && $customer->default_discount_percent !== null) {
                                $set('discount_percent', (string) $customer->default_discount_percent);
                            }
                        })
                        ->columnSpan(2),
                    Select::make('kind')
                        ->label(__('billing.invoice.field.kind'))
                        ->helperText(__('billing.invoice.help.kind'))
                        ->options(collect(InvoiceKind::cases())
                            ->mapWithKeys(fn (InvoiceKind $k): array => [$k->value => $k->label()])
                            ->all())
                        ->default(InvoiceKind::Invoice->value)
                        ->required()
                        ->native(false),
                ]),

            Section::make(__('billing.invoice.section.details'))
                ->columns(3)
                ->schema([
                    DatePicker::make('service_from')
                        ->label(__('billing.invoice.field.service_from')),
                    DatePicker::make('service_to')
                        ->label(__('billing.invoice.field.service_to')),
                    TextInput::make('work_order_number')
                        ->label(__('billing.invoice.field.work_order_number'))
                        ->maxLength(32),
                    TextInput::make('aircraft_registration')
                        ->label(__('billing.invoice.field.aircraft_registration'))
                        ->maxLength(32),
                    Select::make('tax_treatment')
                        ->label(__('billing.invoice.field.tax_treatment'))
                        ->options(collect(TaxTreatment::cases())
                            ->mapWithKeys(fn (TaxTreatment $t): array => [$t->value => $t->label()])
                            ->all())
                        ->default(TaxTreatment::organisationDefault()->value)
                        ->required()
                        ->native(false),
                    TextInput::make('discount_percent')
                        ->label(__('billing.invoice.field.discount_percent'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->step(0.01),
                    Toggle::make('discount_visible')
                        ->label(__('billing.invoice.field.discount_visible'))
                        ->helperText(__('billing.invoice.help.discount_visible'))
                        ->default((bool) config('aeronance.billing.discount_visible_default', true))
                        ->inline(false),
                ]),

            Section::make(__('billing.invoice.section.lines'))
                ->description(__('billing.invoice.help.lines'))
                ->schema([
                    Repeater::make('lines')
                        ->relationship()
                        ->hiddenLabel()
                        ->orderColumn('position')
                        ->reorderable()
                        ->defaultItems(0)
                        ->columns(12)
                        ->itemLabel(fn (array $state): ?string => filled($state['description'] ?? null)
                            ? mb_strimwidth((string) $state['description'], 0, 70, '…')
                            : null)
                        ->schema([
                            Hidden::make('origin')->default(LineOrigin::Manual->value),
                            Hidden::make('source_reference'),
                            Hidden::make('needs_price')->default(false),
                            Textarea::make('description')
                                ->label(__('billing.invoice.line_field.description'))
                                ->required()
                                ->rows(1)
                                ->columnSpan(12),
                            TextInput::make('quantity')
                                ->label(__('billing.invoice.line_field.quantity'))
                                ->numeric()
                                ->required()
                                ->default(1)
                                ->step(0.001)
                                ->columnSpan(2),
                            TextInput::make('unit')
                                ->label(__('billing.invoice.line_field.unit'))
                                ->default('Stk')
                                ->required()
                                ->maxLength(16)
                                ->columnSpan(2),
                            TextInput::make('list_unit_price')
                                ->label(__('billing.invoice.line_field.list_unit_price'))
                                ->numeric()
                                ->required()
                                ->default(0)
                                ->step(0.01)
                                ->suffix('€')
                                ->columnSpan(3),
                            TextInput::make('discount_percent')
                                ->label(__('billing.invoice.line_field.discount_percent'))
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(100)
                                ->step(0.01)
                                ->columnSpan(2),
                            Toggle::make('discount_visible')
                                ->label(__('billing.invoice.line_field.discount_visible'))
                                ->default(true)
                                ->inline(false)
                                ->columnSpan(1),
                            TextInput::make('tax_rate')
                                ->label(__('billing.invoice.line_field.tax_rate'))
                                ->numeric()
                                ->default(PriceList::defaultTaxRate())
                                ->required()
                                ->minValue(0)
                                ->maxValue(100)
                                ->step(0.01)
                                ->columnSpan(2),
                        ]),
                ]),

            Section::make(__('billing.invoice.section.texts'))
                ->columns(1)
                ->collapsible()
                ->schema([
                    Textarea::make('intro')
                        ->label(__('billing.invoice.field.intro'))
                        ->rows(2),
                    Textarea::make('note')
                        ->label(__('billing.invoice.field.note'))
                        ->rows(2),
                    Textarea::make('internal_note')
                        ->label(__('billing.invoice.field.internal_note'))
                        ->rows(2),
                ]),
        ]);
    }
}
