<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\RepairDispatches;

use App\Modules\Warehouse\Filament\Resources\RepairDispatches\Pages\ListRepairDispatches;
use App\Modules\Warehouse\Filament\Resources\RepairDispatches\Tables\RepairDispatchesTable;
use App\Modules\Warehouse\Models\RepairDispatch;
use App\Modules\Warehouse\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * What is away being repaired.
 *
 * The answer to "where is the tow release?" -- a question the warehouse could
 * not answer at all before, because a part sent away simply left the books.
 *
 * There is no create form here: a dispatch comes into being by sending a part
 * away on the repair screen, where the stock booking happens with it. A record
 * typed straight into this table would claim a part left the shelf without the
 * shelf knowing.
 */
final class RepairDispatchResource extends Resource
{
    protected static ?string $model = RepairDispatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 14;

    public static function table(Table $table): Table
    {
        return RepairDispatchesTable::configure($table);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.warehouse');
    }

    public static function getNavigationLabel(): string
    {
        return __('warehouse.repair.plural');
    }

    public static function getModelLabel(): string
    {
        return __('warehouse.repair.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('warehouse.repair.plural');
    }

    /**
     * How many parts are away right now, on the menu item itself.
     *
     * Deliberately the open ones only: the count is there to be noticed when it
     * grows, and a total that includes everything ever returned never changes
     * meaningfully again.
     */
    public static function getNavigationBadge(): ?string
    {
        $open = RepairDispatch::open()->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return RepairDispatch::overdue()->exists() ? 'warning' : null;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::STOCK_VIEW) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** @param  RepairDispatch  $record */
    public static function canEdit($record): bool
    {
        return false;
    }

    /** @param  RepairDispatch  $record */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRepairDispatches::route('/'),
        ];
    }
}
