<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\AircraftTypes;

use App\Modules\Fleet\Filament\Resources\AircraftTypes\Pages\ListAircraftTypes;
use App\Modules\Fleet\Models\AircraftType;
use App\Modules\Fleet\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * The type list -- searchable, with free text always possible.
 *
 * Vorgabe: "am liebsten hätte ich eine durchsuchbare liste mit der möglichkeit zum
 * freitext." Both halves matter: the authority lookup fills in what it knows, and
 * a designation nobody catalogued can still be typed.
 */
final class AircraftTypeResource extends Resource
{
    protected static ?string $model = AircraftType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.fleet');
    }

    public static function getNavigationLabel(): string
    {
        return __('fleet.type.plural');
    }

    public static function getModelLabel(): string
    {
        return __('fleet.type.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fleet.type.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::FLEET_VIEW) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(Permissions::FLEET_MANAGE) ?? false;
    }

    /** @param  AircraftType  $record */
    public static function canEdit($record): bool
    {
        return auth()->user()?->can(Permissions::FLEET_MANAGE) ?? false;
    }

    /**
     * A type in use is not deleted.
     *
     * Its aircraft point at it and its directives are matched through it --
     * removing it would silently loosen every one of those matches back to string
     * comparison. An unused one may go.
     *
     * @param  AircraftType  $record
     */
    public static function canDelete($record): bool
    {
        return ($auth = auth()->user()) !== null
            && $auth->can(Permissions::FLEET_MANAGE)
            && $record->aircraft()->doesntExist();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAircraftTypes::route('/'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
