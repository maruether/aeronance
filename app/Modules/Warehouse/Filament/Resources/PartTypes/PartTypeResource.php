<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\PartTypes;

use App\Modules\Warehouse\Filament\Resources\PartTypes\Pages\CreatePartType;
use App\Modules\Warehouse\Filament\Resources\PartTypes\Pages\EditPartType;
use App\Modules\Warehouse\Filament\Resources\PartTypes\Pages\ListPartTypes;
use App\Modules\Warehouse\Filament\Resources\PartTypes\Schemas\PartTypeForm;
use App\Modules\Warehouse\Filament\Resources\PartTypes\Tables\PartTypesTable;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class PartTypeResource extends Resource
{
    protected static ?string $model = PartType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    public static function form(Schema $schema): Schema
    {
        return PartTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PartTypesTable::configure($table);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.warehouse');
    }

    public static function getNavigationLabel(): string
    {
        return __('warehouse.part_type.plural');
    }

    public static function getModelLabel(): string
    {
        return __('warehouse.part_type.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('warehouse.part_type.plural');
    }

    /**
     * Managing part types is a separate permission from booking goods in --
     * decision E5. One is a master-data judgement with regulatory weight, the
     * other a routine act at the shelf.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::PART_TYPES_MANAGE) ?? false;
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    /** @param  PartType  $record */
    public static function canEdit($record): bool
    {
        return self::canViewAny();
    }

    /** @param  PartType  $record */
    public static function canDelete($record): bool
    {
        return self::canViewAny();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartTypes::route('/'),
            'create' => CreatePartType::route('/create'),
            'edit' => EditPartType::route('/{record}/edit'),
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
