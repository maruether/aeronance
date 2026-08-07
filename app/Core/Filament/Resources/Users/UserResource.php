<?php

declare(strict_types=1);

namespace App\Core\Filament\Resources\Users;

use App\Core\Access\CorePermissions;
use App\Core\Filament\Resources\Users\Pages\CreateUser;
use App\Core\Filament\Resources\Users\Pages\EditUser;
use App\Core\Filament\Resources\Users\Pages\ListUsers;
use App\Core\Filament\Resources\Users\RelationManagers\QualificationsRelationManager;
use App\Core\Filament\Resources\Users\Schemas\UserForm;
use App\Core\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * People.
 *
 * Two kinds of authority are administered from here, and the resource keeps
 * them apart on purpose. Roles say what someone may operate; qualifications --
 * on the tab of their own -- say what they may answer for, and they are the
 * only thing that makes the certifying acts possible at all.
 */
final class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'benutzer';

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.people');
    }

    public static function getNavigationLabel(): string
    {
        return __('users.plural');
    }

    public static function getModelLabel(): string
    {
        return __('users.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('users.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [QualificationsRelationManager::class];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(CorePermissions::USERS_VIEW) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(CorePermissions::USERS_MANAGE) ?? false;
    }

    /** @param  User  $record */
    public static function canEdit($record): bool
    {
        return auth()->user()?->can(CorePermissions::USERS_MANAGE) ?? false;
    }

    /**
     * Wer den Not-Aus drücken darf — und bei wem.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * NIEMAND BEI SICH SELBST. Das ist kein Misstrauen, sondern der Schutz vor
     * dem naheliegendsten Unfall: Der einzige Administrator sperrt sich aus,
     * und danach kann niemand die Sperre mehr aufheben — denn das Aufheben
     * braucht genau das Recht, das er sich gerade genommen hat. Die Anwendung
     * wäre zu, ohne dass jemand etwas falsch gemacht hätte.
     *
     * Hier und nicht in der Tabelle, damit die Regel prüfbar ist und nicht nur
     * im Formular steht: Ein Recht, das bloß die Oberfläche versteckt, gilt
     * nach den Leitplanken als nicht vorhanden.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @param  User  $record
     */
    public static function canLock($record): bool
    {
        $handelnder = auth()->user();

        if (! $handelnder instanceof User || $handelnder->is($record)) {
            return false;
        }

        return $handelnder->can(CorePermissions::USERS_MANAGE);
    }

    /**
     * Accounts are deactivated, never deleted.
     *
     * A member who leaves keeps their trace in the records -- their name may
     * appear in a release, and that entry has to stay readable years later.
     */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/neu'),
            'edit' => EditUser::route('/{record}/bearbeiten'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
