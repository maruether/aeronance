<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\Suppliers;

use App\Modules\Warehouse\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Modules\Warehouse\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Modules\Warehouse\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Modules\Warehouse\Filament\Resources\Suppliers\Schemas\SupplierForm;
use App\Modules\Warehouse\Filament\Resources\Suppliers\Tables\SuppliersTable;
use App\Modules\Warehouse\Models\Supplier;
use App\Modules\Warehouse\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Suppliers -- master data, nothing more.
 *
 * No ordering, no purchase history, no assessment: that is merchandise
 * management, which this module deliberately is not (E6). A supplier answers
 * one question -- where do we get this from.
 */
final class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.warehouse');
    }

    public static function getNavigationLabel(): string
    {
        return __('warehouse.supplier.plural');
    }

    public static function getModelLabel(): string
    {
        return __('warehouse.supplier.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('warehouse.supplier.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return SupplierForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SuppliersTable::configure($table);
    }

    /**
     * Deny by default: without the permission the resource does not exist --
     * no navigation entry and no reachable route.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::SUPPLIERS_MANAGE) ?? false;
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    /** @param  Supplier  $record */
    public static function canEdit($record): bool
    {
        return self::canViewAny();
    }

    /** @param  Supplier  $record */
    public static function canDelete($record): bool
    {
        return self::canViewAny();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/create'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
