<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\StockMovements;

use App\Modules\Warehouse\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Modules\Warehouse\Filament\Resources\StockMovements\Tables\StockMovementsTable;
use App\Modules\Warehouse\Models\StockMovement;
use App\Modules\Warehouse\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The ledger, readable at last.
 *
 * Stock is the sum of these entries (E1), and until now the only places one
 * could see them were the detail view of a single lot and the printed inventory
 * report. The question "what happened to this part in March" had no screen.
 *
 * Read-only throughout, and not as a matter of taste: a movement cannot be
 * edited or deleted -- the model itself refuses both -- because a ledger whose
 * entries can be revised is not a ledger. What CAN be done here is write a
 * counter-booking, which is the only honest way to put an entry right.
 */
final class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?int $navigationSort = 30;

    public static function table(Table $table): Table
    {
        return StockMovementsTable::configure($table);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.warehouse');
    }

    public static function getNavigationLabel(): string
    {
        return __('warehouse.movement.plural');
    }

    public static function getModelLabel(): string
    {
        return __('warehouse.movement.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('warehouse.movement.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::STOCK_VIEW) ?? false;
    }

    /**
     * A movement comes into being by booking something, never by typing it into
     * a form. Creating one here would be stock appearing without an event.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /** @param  StockMovement  $record */
    public static function canEdit($record): bool
    {
        return false;
    }

    /** @param  StockMovement  $record */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockMovements::route('/'),
        ];
    }
}
