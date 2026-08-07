<?php

declare(strict_types=1);

namespace App\Core\Filament\Resources\Roles;

use App\Core\Access\CorePermissions;
use App\Core\Filament\Resources\Roles\Pages\EditRole;
use App\Core\Filament\Resources\Roles\Pages\ListRoles;
use App\Core\Filament\Resources\Roles\Schemas\RoleForm;
use App\Core\Filament\Resources\Roles\Tables\RolesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

/**
 * Roles and what they may do.
 *
 * Only the permissions of ACTIVE modules are offered. A module that is switched
 * off keeps its assignments in the database -- they belong to the role, and
 * deactivating is not uninstalling -- but showing rights for something that is
 * not running would be offering a choice with no meaning.
 */
final class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'rollen';

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.people');
    }

    public static function getNavigationLabel(): string
    {
        return __('roles.plural');
    }

    public static function getModelLabel(): string
    {
        return __('roles.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('roles.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RolesTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(CorePermissions::ROLES_MANAGE) ?? false;
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    /** @param  Role  $record */
    public static function canEdit($record): bool
    {
        return self::canViewAny();
    }

    /**
     * Roles are not deleted through the interface.
     *
     * Removing one silently strips its permissions from everybody who held it,
     * with no trace of what they used to be able to do.
     */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'edit' => EditRole::route('/{record}/bearbeiten'),
        ];
    }
}
