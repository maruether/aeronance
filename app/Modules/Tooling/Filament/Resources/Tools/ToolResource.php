<?php

declare(strict_types=1);

namespace App\Modules\Tooling\Filament\Resources\Tools;

use App\Modules\Tooling\Filament\Resources\Tools\Pages\CreateTool;
use App\Modules\Tooling\Filament\Resources\Tools\Pages\EditTool;
use App\Modules\Tooling\Filament\Resources\Tools\Pages\ListTools;
use App\Modules\Tooling\Filament\Resources\Tools\RelationManagers\CalibrationsRelationManager;
use App\Modules\Tooling\Filament\Resources\Tools\RelationManagers\IssuesRelationManager;
use App\Modules\Tooling\Filament\Resources\Tools\Schemas\ToolForm;
use App\Modules\Tooling\Filament\Resources\Tools\Tables\ToolsTable;
use App\Modules\Tooling\Models\Tool;
use App\Modules\Tooling\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Werkzeuge.
 *
 * Die Zahl in der Navigation zählt ueberfaellige Kalibrierungen. Nicht die
 * Werkzeuge insgesamt -- eine Zahl, die immer dasteht, wird nach einer Woche
 * nicht mehr gelesen.
 */
final class ToolResource extends Resource
{
    protected static ?string $model = Tool::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'werkzeuge';

    public static function form(Schema $schema): Schema
    {
        return ToolForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ToolsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [CalibrationsRelationManager::class, IssuesRelationManager::class];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.tooling');
    }

    public static function getNavigationLabel(): string
    {
        return __('tooling.plural');
    }

    public static function getModelLabel(): string
    {
        return __('tooling.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('tooling.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $faellig = Tool::query()->overdue()->count();

        return $faellig > 0 ? (string) $faellig : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::TOOLS_VIEW) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(Permissions::TOOLS_MANAGE) ?? false;
    }

    /** @param  Tool  $record */
    public static function canEdit($record): bool
    {
        return self::canCreate();
    }

    /**
     * Ausgesondert wird ueber den Zustand, nicht geloescht.
     *
     * Ein geloeschtes Werkzeug nimmt seine Kalibrierhistorie mit -- und mit ihr
     * die Antwort auf die Frage, ob damit einmal ohne belegte Genauigkeit
     * gearbeitet wurde.
     *
     * @param  Tool  $record
     */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTools::route('/'),
            'create' => CreateTool::route('/neu'),
            'edit' => EditTool::route('/{record}/bearbeiten'),
        ];
    }
}
