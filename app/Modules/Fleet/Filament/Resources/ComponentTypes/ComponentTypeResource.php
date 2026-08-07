<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\ComponentTypes;

use App\Modules\Fleet\Filament\Resources\ComponentTypes\Pages\ListComponentTypes;
use App\Modules\Fleet\Models\ComponentType;
use App\Modules\Fleet\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class ComponentTypeResource extends Resource
{
    protected static ?string $model = ComponentType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.fleet');
    }

    public static function getNavigationLabel(): string
    {
        return __('fleet.component_type.plural');
    }

    public static function getModelLabel(): string
    {
        return __('fleet.component_type.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fleet.component_type.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::FLEET_VIEW) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(Permissions::COMPONENTS_MANAGE) ?? false;
    }

    /** @param  ComponentType  $record */
    public static function canEdit($record): bool
    {
        return auth()->user()?->can(Permissions::COMPONENTS_MANAGE) ?? false;
    }

    /**
     * A type that is fitted somewhere is not deleted.
     *
     * Its installations point at it and its directives match through it --
     * removing it would silently loosen every one of those back to name
     * comparison.
     *
     * @param  ComponentType  $record
     */
    public static function canDelete($record): bool
    {
        return ($user = auth()->user()) !== null
            && $user->can(Permissions::COMPONENTS_MANAGE)
            && $record->installations()->doesntExist();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListComponentTypes::route('/'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
