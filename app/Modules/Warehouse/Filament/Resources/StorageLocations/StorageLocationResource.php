<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\StorageLocations;

use App\Modules\Warehouse\Filament\Resources\StorageLocations\Pages\CreateStorageLocation;
use App\Modules\Warehouse\Filament\Resources\StorageLocations\Pages\EditStorageLocation;
use App\Modules\Warehouse\Filament\Resources\StorageLocations\Pages\ListStorageLocations;
use App\Modules\Warehouse\Filament\Resources\StorageLocations\Schemas\StorageLocationForm;
use App\Modules\Warehouse\Filament\Resources\StorageLocations\Tables\StorageLocationsTable;
use App\Modules\Warehouse\Models\StorageLocation;
use App\Modules\Warehouse\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class StorageLocationResource extends Resource
{
    protected static ?string $model = StorageLocation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    public static function form(Schema $schema): Schema
    {
        return StorageLocationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StorageLocationsTable::configure($table);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.warehouse');
    }

    public static function getNavigationLabel(): string
    {
        return __('warehouse.location.plural');
    }

    public static function getModelLabel(): string
    {
        return __('warehouse.location.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('warehouse.location.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::LOCATIONS_MANAGE) ?? false;
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    /** @param  StorageLocation  $record */
    public static function canEdit($record): bool
    {
        return self::canViewAny();
    }

    /** @param  StorageLocation  $record */
    public static function canDelete($record): bool
    {
        return self::canViewAny();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStorageLocations::route('/'),
            'create' => CreateStorageLocation::route('/create'),
            'edit' => EditStorageLocation::route('/{record}/edit'),
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
