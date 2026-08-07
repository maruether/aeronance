<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\MaintenanceManuals;

use App\Modules\Fleet\Filament\Resources\MaintenanceManuals\Pages\CreateMaintenanceManual;
use App\Modules\Fleet\Filament\Resources\MaintenanceManuals\Pages\EditMaintenanceManual;
use App\Modules\Fleet\Filament\Resources\MaintenanceManuals\Pages\ListMaintenanceManuals;
use App\Modules\Fleet\Filament\Resources\MaintenanceManuals\Schemas\MaintenanceManualForm;
use App\Modules\Fleet\Filament\Resources\MaintenanceManuals\Tables\MaintenanceManualsTable;
use App\Modules\Fleet\Models\MaintenanceManual;
use App\Modules\Fleet\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Wartungsunterlagen — „arbeiten wir nach dem aktuellen Handbuch?"
 *
 * Keine Zahl in der Navigation: Eine abgelaufene Unterlage gibt es nicht, es
 * gibt nur eine, von der man nicht weiß, ob sie noch die neueste ist — und das
 * kann kein System selbst feststellen. Eine erfundene Zahl wäre hier eine
 * Beruhigung ohne Deckung.
 */
final class MaintenanceManualResource extends Resource
{
    protected static ?string $model = MaintenanceManual::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 35;

    protected static ?string $slug = 'wartungsunterlagen';

    public static function form(Schema $schema): Schema
    {
        return MaintenanceManualForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MaintenanceManualsTable::configure($table);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.fleet');
    }

    public static function getNavigationLabel(): string
    {
        return __('fleet.manual.plural');
    }

    public static function getModelLabel(): string
    {
        return __('fleet.manual.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fleet.manual.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::FLEET_VIEW) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(Permissions::FLEET_MANAGE) ?? false;
    }

    /** @param  MaintenanceManual  $record */
    public static function canEdit($record): bool
    {
        return self::canCreate();
    }

    /**
     * Nicht löschen.
     *
     * Ein abgelöster Stand ist der Nachweis, wonach im Mai gearbeitet wurde.
     * Wer ihn wegräumen kann, räumt genau die Antwort weg, für die es diese
     * Liste gibt. Zurückziehen ist der Weg, etwas aus dem Verkehr zu nehmen.
     *
     * @param  MaintenanceManual  $record
     */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMaintenanceManuals::route('/'),
            'create' => CreateMaintenanceManual::route('/neu'),
            'edit' => EditMaintenanceManual::route('/{record}/bearbeiten'),
        ];
    }
}
