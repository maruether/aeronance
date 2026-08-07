<?php

declare(strict_types=1);

namespace App\Modules\Directives\Filament\Resources\Directives;

use App\Modules\Directives\Filament\Resources\Directives\Pages\ListDirectives;
use App\Modules\Directives\Filament\Resources\Directives\Pages\ViewDirective;
use App\Modules\Directives\Models\Directive;
use App\Modules\Directives\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class DirectiveResource extends Resource
{
    protected static ?string $model = Directive::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.fleet');
    }

    public static function getNavigationLabel(): string
    {
        return __('directives.plural');
    }

    public static function getModelLabel(): string
    {
        return __('directives.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('directives.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::DIRECTIVES_VIEW) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(Permissions::DIRECTIVES_MANAGE) ?? false;
    }

    /** @param  Directive  $record */
    public static function canEdit($record): bool
    {
        return auth()->user()?->can(Permissions::DIRECTIVES_MANAGE) ?? false;
    }

    /**
     * A line is never deleted from the screen.
     *
     * "Die Übersichtsliste ändert sich herstellerseitig nicht oder wird länger."
     * A directive that no longer applies is superseded or assessed as not
     * applicable -- both of which leave a readable trace. Deleting leaves none,
     * and takes the assessments with it.
     *
     * @param  Directive  $record
     */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDirectives::route('/'),
            'view' => ViewDirective::route('/{record}'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
