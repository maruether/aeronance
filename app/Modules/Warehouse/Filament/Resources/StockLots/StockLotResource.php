<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\StockLots;

use App\Modules\Warehouse\Filament\Resources\StockLots\Pages\ListStockLots;
use App\Modules\Warehouse\Filament\Resources\StockLots\Pages\ViewStockLot;
use App\Modules\Warehouse\Filament\Resources\StockLots\Schemas\StockLotInfolist;
use App\Modules\Warehouse\Filament\Resources\StockLots\Tables\StockLotsTable;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class StockLotResource extends Resource
{
    protected static ?string $model = StockLot::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    public static function table(Table $table): Table
    {
        return StockLotsTable::configure($table);
    }

    /**
     * The detail view -- where traceability becomes something one can read.
     */
    public static function infolist(Schema $schema): Schema
    {
        return StockLotInfolist::configure($schema);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.warehouse');
    }

    public static function getNavigationLabel(): string
    {
        return __('warehouse.lot.plural');
    }

    public static function getModelLabel(): string
    {
        return __('warehouse.lot.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('warehouse.lot.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::STOCK_VIEW) ?? false;
    }

    /**
     * A lot comes into being by booking goods in and empties by issuing them.
     * Neither happens by editing a form, so there is no create and no edit --
     * the quantity is the sum of the movements and must not be typed over.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /** @param  StockLot  $record */
    public static function canEdit($record): bool
    {
        return false;
    }

    /** @param  StockLot  $record */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockLots::route('/'),
            'view' => ViewStockLot::route('/{record}'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
