<?php

declare(strict_types=1);

namespace App\Modules\Billing\Filament\Resources\PartPrices;

use App\Modules\Billing\Filament\Resources\PartPrices\Pages\CreatePartPrice;
use App\Modules\Billing\Filament\Resources\PartPrices\Pages\EditPartPrice;
use App\Modules\Billing\Filament\Resources\PartPrices\Pages\ListPartPrices;
use App\Modules\Billing\Models\PartPrice;
use App\Modules\Billing\Permissions;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Verkaufspreise je Teilenummer.
 *
 * Eine Zeile je Nummer und Gültigkeitsbeginn; die Liste zeigt alle, damit
 * die Historie sichtbar bleibt. Welche Zeile eine Rechnung nimmt, entscheidet
 * das Leistungsdatum (PriceList).
 */
final class PartPriceResource extends Resource
{
    protected static ?string $model = PartPrice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyEuro;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'verkaufspreise';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('billing.price.singular'))
                ->columns(2)
                ->schema([
                    TextInput::make('part_number')
                        ->label(__('billing.price.field.part_number'))
                        ->helperText(__('billing.price.help.part_number'))
                        ->required()
                        ->maxLength(128),
                    TextInput::make('description')
                        ->label(__('billing.price.field.description'))
                        ->maxLength(255),
                    TextInput::make('net_price')
                        ->label(__('billing.price.field.net_price'))
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->suffix('€'),
                    TextInput::make('unit')
                        ->label(__('billing.price.field.unit'))
                        ->default('Stk')
                        ->required()
                        ->maxLength(16),
                    DatePicker::make('valid_from')
                        ->label(__('billing.price.field.valid_from'))
                        ->helperText(__('billing.price.help.valid_from'))
                        ->default(now()->toDateString())
                        ->required(),
                    Textarea::make('note')
                        ->label(__('billing.price.field.note'))
                        ->rows(2),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('part_number')
                    ->label(__('billing.price.field.part_number'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('description')
                    ->label(__('billing.price.field.description'))
                    ->searchable()
                    ->limit(60),
                TextColumn::make('net_price')
                    ->label(__('billing.price.field.net_price'))
                    ->money('EUR', locale: 'de')
                    ->sortable(),
                TextColumn::make('unit')
                    ->label(__('billing.price.field.unit')),
                TextColumn::make('valid_from')
                    ->label(__('billing.price.field.valid_from'))
                    ->date('d.m.Y')
                    ->sortable(),
            ])
            ->defaultSort('part_number')
            ->recordActions([EditAction::make()]);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.billing');
    }

    public static function getNavigationLabel(): string
    {
        return __('billing.price.plural');
    }

    public static function getModelLabel(): string
    {
        return __('billing.price.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('billing.price.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::VIEW) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(Permissions::MANAGE) ?? false;
    }

    /** @param  PartPrice  $record */
    public static function canEdit($record): bool
    {
        return self::canCreate();
    }

    /** @param  PartPrice  $record */
    public static function canDelete($record): bool
    {
        return self::canCreate();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartPrices::route('/'),
            'create' => CreatePartPrice::route('/neu'),
            'edit' => EditPartPrice::route('/{record}/bearbeiten'),
        ];
    }
}
