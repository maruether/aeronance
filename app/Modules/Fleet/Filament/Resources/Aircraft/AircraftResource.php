<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\Aircraft;

use App\Modules\Fleet\Filament\Resources\Aircraft\Pages\ListAircraft;
use App\Modules\Fleet\Filament\Resources\Aircraft\Pages\ViewAircraft;
use App\Modules\Fleet\Filament\Resources\Aircraft\Schemas\AircraftForm;
use App\Modules\Fleet\Filament\Resources\Aircraft\Schemas\AircraftInfolist;
use App\Modules\Fleet\Filament\Resources\Aircraft\Tables\AircraftTable;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

final class AircraftResource extends Resource
{
    protected static ?string $model = Aircraft::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return AircraftForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AircraftInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AircraftTable::configure($table);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.fleet');
    }

    public static function getNavigationLabel(): string
    {
        return __('fleet.aircraft.plural');
    }

    public static function getModelLabel(): string
    {
        return __('fleet.aircraft.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fleet.aircraft.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::FLEET_VIEW) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(Permissions::FLEET_MANAGE) ?? false;
    }

    /** @param  Aircraft  $record */
    public static function canEdit($record): bool
    {
        return auth()->user()?->can(Permissions::FLEET_MANAGE) ?? false;
    }

    /**
     * Aircraft are taken out of service, not deleted.
     *
     * Their life record is the point of keeping them: what was fitted, what was
     * signed, what ran out. Deleting one takes that with it.
     */
    /** @param  Aircraft  $record */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAircraft::route('/'),
            'view' => ViewAircraft::route('/{record}'),
        ];
    }
}
