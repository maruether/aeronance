<?php

declare(strict_types=1);

namespace App\Modules\Billing\Filament\Resources\Customers;

use App\Modules\Billing\Enums\TaxTreatment;
use App\Modules\Billing\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Modules\Billing\Filament\Resources\Customers\Pages\EditCustomer;
use App\Modules\Billing\Filament\Resources\Customers\Pages\ListCustomers;
use App\Modules\Billing\Models\Customer;
use App\Modules\Billing\Permissions;
use App\Modules\Fleet\Models\Holder;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Kunden -- wer Rechnungen bekommt.
 *
 * Die nullable-Felder der Konditionen zeigen „Vorgabe der Organisation" als
 * leere Auswahl: Ein Kunde ohne eigene Abmachung folgt der Einstellung, und
 * eine geänderte Einstellung erreicht ihn. Kunden werden nicht gelöscht,
 * nur inaktiv gesetzt (siehe Modell).
 */
final class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'kunden';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('billing.customer.section.master'))
                ->columns(2)
                ->schema([
                    TextInput::make('customer_number')
                        ->label(__('billing.customer.field.customer_number'))
                        ->maxLength(32)
                        ->unique(ignoreRecord: true),
                    TextInput::make('name')
                        ->label(__('billing.customer.field.name'))
                        ->required()
                        ->maxLength(160),
                    Textarea::make('address')
                        ->label(__('billing.customer.field.address'))
                        ->helperText(__('billing.customer.help.address'))
                        ->rows(4)
                        ->columnSpanFull(),
                    TextInput::make('country')
                        ->label(__('billing.customer.field.country'))
                        ->default('DE')
                        ->required()
                        ->length(2)
                        ->extraInputAttributes(['style' => 'text-transform: uppercase']),
                    TextInput::make('contact_name')
                        ->label(__('billing.customer.field.contact_name'))
                        ->maxLength(160),
                    TextInput::make('email')
                        ->label(__('billing.customer.field.email'))
                        ->email()
                        ->maxLength(191),
                    TextInput::make('phone')
                        ->label(__('billing.customer.field.phone'))
                        ->maxLength(64),
                ]),

            Section::make(__('billing.customer.section.terms'))
                ->columns(3)
                ->schema([
                    TextInput::make('payment_terms_days')
                        ->label(__('billing.customer.field.payment_terms_days'))
                        ->helperText(__('billing.customer.help.payment_terms_days'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(365),
                    TextInput::make('default_discount_percent')
                        ->label(__('billing.customer.field.default_discount_percent'))
                        ->helperText(__('billing.customer.help.default_discount_percent'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->step(0.01),
                    Select::make('discount_visible')
                        ->label(__('billing.customer.field.discount_visible'))
                        ->helperText(__('billing.customer.help.discount_visible'))
                        ->options([
                            '1' => __('billing.customer.option.visible'),
                            '0' => __('billing.customer.option.hidden'),
                        ])
                        ->placeholder(__('billing.customer.option.default'))
                        ->native(false),
                ]),

            Section::make(__('billing.customer.section.tax'))
                ->columns(3)
                ->schema([
                    Select::make('tax_treatment')
                        ->label(__('billing.customer.field.tax_treatment'))
                        ->helperText(__('billing.customer.help.tax_treatment'))
                        ->options(collect(TaxTreatment::cases())
                            ->mapWithKeys(fn (TaxTreatment $t): array => [$t->value => $t->label()])
                            ->all())
                        ->placeholder(__('billing.customer.option.default'))
                        ->native(false),
                    TextInput::make('vat_id')
                        ->label(__('billing.customer.field.vat_id'))
                        ->maxLength(32),
                    TextInput::make('tax_number')
                        ->label(__('billing.customer.field.tax_number'))
                        ->maxLength(32),
                    TextInput::make('buyer_reference')
                        ->label(__('billing.customer.field.buyer_reference'))
                        ->helperText(__('billing.customer.help.buyer_reference'))
                        ->maxLength(64),
                ]),

            Section::make(__('billing.customer.section.links'))
                ->columns(2)
                ->schema([
                    Select::make('holder_id')
                        ->label(__('billing.customer.field.holder'))
                        ->helperText(__('billing.customer.help.holder'))
                        ->options(fn (): array => Holder::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->native(false),
                    Toggle::make('is_active')
                        ->label(__('billing.customer.field.is_active'))
                        ->helperText(__('billing.customer.help.is_active'))
                        ->default(true),
                    Textarea::make('note')
                        ->label(__('billing.customer.field.note'))
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer_number')
                    ->label(__('billing.customer.field.customer_number'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('billing.customer.field.name'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Customer $record): ?string => $record->contact_name),
                TextColumn::make('email')
                    ->label(__('billing.customer.field.email'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('payment_terms_days')
                    ->label(__('billing.customer.field.payment_terms_days'))
                    ->placeholder(__('billing.customer.option.default'))
                    ->toggleable(),
                TextColumn::make('default_discount_percent')
                    ->label(__('billing.customer.field.default_discount_percent'))
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label(__('billing.customer.field.is_active'))
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('billing.customer.field.is_active'))
                    ->default(true),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.billing');
    }

    public static function getNavigationLabel(): string
    {
        return __('billing.customer.plural');
    }

    public static function getModelLabel(): string
    {
        return __('billing.customer.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('billing.customer.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::VIEW) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(Permissions::MANAGE) ?? false;
    }

    /** @param  Customer  $record */
    public static function canEdit($record): bool
    {
        return self::canCreate();
    }

    /**
     * Nicht löschen: Rechnungen zeigen auf ihn, und die bleiben acht Jahre.
     *
     * @param  Customer  $record
     */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/neu'),
            'edit' => EditCustomer::route('/{record}/bearbeiten'),
        ];
    }
}
