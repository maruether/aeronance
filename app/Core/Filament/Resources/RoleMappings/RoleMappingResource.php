<?php

declare(strict_types=1);

namespace App\Core\Filament\Resources\RoleMappings;

use App\Core\Access\CorePermissions;
use App\Core\Filament\Resources\RoleMappings\Pages\CreateRoleMapping;
use App\Core\Filament\Resources\RoleMappings\Pages\EditRoleMapping;
use App\Core\Filament\Resources\RoleMappings\Pages\ListRoleMappings;
use App\Core\Filament\Resources\RoleMappings\Schemas\RoleMappingForm;
use App\Core\Filament\Resources\RoleMappings\Tables\RoleMappingsTable;
use App\Core\Identity\IdentityProviderRegistry;
use App\Core\Identity\RoleMapping;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Welche externe Gruppe welche Rolle gibt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SICHTBAR NUR MIT CONNECTOR. Ist keiner eingetragen, gibt es hier nichts zu
 * entscheiden -- und ein Menuepunkt, hinter dem eine leere Seite liegt, ist eine
 * Frage an den Benutzer, die er nicht beantworten kann. Der Kern muss ohne jedes
 * Modul laufen; das gilt auch fuer seine Navigation.
 *
 * BEWUSST BEI DEN ROLLEN und nicht bei den Einstellungen: Wer hier etwas
 * eintraegt, vergibt Rechte -- an alle, die morgen in diese Gruppe geraten. Das
 * ist dieselbe Sorte Eingriff wie das Bearbeiten einer Rolle und braucht
 * dasselbe Recht.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class RoleMappingResource extends Resource
{
    protected static ?string $model = RoleMapping::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'rollenzuordnungen';

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.people');
    }

    public static function getNavigationLabel(): string
    {
        return __('identity.mapping.plural');
    }

    public static function getModelLabel(): string
    {
        return __('identity.mapping.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('identity.mapping.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return RoleMappingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RoleMappingsTable::configure($table);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return app(IdentityProviderRegistry::class)->all() !== [];
    }

    public static function canViewAny(): bool
    {
        return (auth()->user()?->can(CorePermissions::ROLES_MANAGE) ?? false)
            && app(IdentityProviderRegistry::class)->all() !== [];
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    /** @param  RoleMapping  $record */
    public static function canEdit($record): bool
    {
        return self::canViewAny();
    }

    /** @param  RoleMapping  $record */
    public static function canDelete($record): bool
    {
        return self::canViewAny();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoleMappings::route('/'),
            'create' => CreateRoleMapping::route('/neu'),
            'edit' => EditRoleMapping::route('/{record}/bearbeiten'),
        ];
    }
}
