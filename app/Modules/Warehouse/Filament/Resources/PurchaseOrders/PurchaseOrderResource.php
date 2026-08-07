<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\PurchaseOrders;

use App\Modules\Warehouse\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Modules\Warehouse\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Modules\Warehouse\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Modules\Warehouse\Filament\Resources\PurchaseOrders\Schemas\PurchaseOrderForm;
use App\Modules\Warehouse\Filament\Resources\PurchaseOrders\Tables\PurchaseOrdersTable;
use App\Modules\Warehouse\Models\PurchaseOrder;
use App\Modules\Warehouse\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Bestellungen — was unterwegs ist, und ob es noch kommt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER ZWECK IST DIE ERINNERUNG, nicht die Beschaffung. Vorgabe: „Es geht bei
 * den bestellungen nicht darum über aeronance bestellungen auszuführen oder
 * die Kosten zu führen sondern nur darum einen reminder zu bekommen."
 *
 * Deshalb steht hier kein Preis, keine Rechnung, keine Kondition. Bestellt
 * wird weiterhin am Telefon, per Mail oder im Webshop; hier wird nur
 * festgehalten, worauf jemand wartet — und beim Eintreffen ueber die
 * bestehende Lageraktion eingebucht, also mit Los, Form 1 und Etikett.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE ZAHL IN DER NAVIGATION ZAEHLT UEBERFAELLIGE, nicht offene. Offene
 * Bestellungen sind der Normalzustand -- eine Zahl, die immer dasteht, liest
 * nach einer Woche niemand mehr. Ueberfaellige sind die Ausnahme, und genau
 * die soll auffallen.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?int $navigationSort = 15;

    protected static ?string $slug = 'bestellungen';

    public static function form(Schema $schema): Schema
    {
        return PurchaseOrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PurchaseOrdersTable::configure($table);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.warehouse');
    }

    public static function getNavigationLabel(): string
    {
        return __('warehouse.order.plural');
    }

    public static function getModelLabel(): string
    {
        return __('warehouse.order.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('warehouse.order.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $ueberfaellig = PurchaseOrder::query()->overdue()->count();

        return $ueberfaellig > 0 ? (string) $ueberfaellig : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::ORDERS_MANAGE) ?? false;
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    /** @param  PurchaseOrder  $record */
    public static function canEdit($record): bool
    {
        return self::canViewAny();
    }

    /**
     * Bestellungen werden storniert, nicht geloescht.
     *
     * „Es kann vorkommen das material nicht kommt" -- was dann bleibt, ist die
     * Frage, worauf jemand monatelang gewartet hat. Ein geloeschter Vorgang
     * beantwortet sie nicht.
     *
     * @param  PurchaseOrder  $record
     */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseOrders::route('/'),
            'create' => CreatePurchaseOrder::route('/neu'),
            'edit' => EditPurchaseOrder::route('/{record}/bearbeiten'),
        ];
    }
}
