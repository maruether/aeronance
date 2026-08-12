<?php

declare(strict_types=1);

namespace App\Modules\Warehouse;

use App\Core\Access\PermissionDefinition;
use App\Core\Modules\Contracts\AeronanceModule;
use App\Core\Modules\Manifest;
use Filament\Panel;

/**
 * The warehouse: what the club has in stock and where it came from.
 *
 * Stands alone -- it requires nothing and conflicts with nothing. A club that
 * only wants to keep track of its spare parts installs this and nothing else,
 * which is the whole point of the modular arrangement.
 *
 * What the module is NOT is merchandise management: no delivery notes, no
 * orders, no invoices, no purchasing history. The reference point is the
 * airworthiness record, not the commercial transaction. See decision E6.
 */
final class WarehouseModule implements AeronanceModule
{
    public function getId(): string
    {
        return 'warehouse';
    }

    public function manifest(): Manifest
    {
        return new Manifest(
            name: 'warehouse',
            version: '0.1.0',
            title: __('warehouse.module.title'),
            description: __('warehouse.module.description'),
        );
    }

    /**
     * Verbs, not screens.
     *
     * Creating part types is deliberately separate from booking goods in: one
     * is a master-data decision with regulatory weight -- which class does this
     * part belong to, and therefore what evidence does it need -- and the other
     * is a routine act at the shelf. See decision E5.
     *
     * @return list<PermissionDefinition>
     */
    public function permissions(): array
    {
        return PermissionDefinition::fromGroups([
            'warehouse.stock' => [
                Permissions::STOCK_VIEW,
                Permissions::STOCK_RECEIVE,
                Permissions::STOCK_ISSUE,
                Permissions::STOCK_QUARANTINE,
                Permissions::STOCK_QUARANTINE_CERTIFY,
                Permissions::STOCK_QUARANTINE_RELEASE,
                Permissions::STOCK_SCRAP,
                Permissions::STOCK_TRANSFER,
                Permissions::STOCK_CORRECT,
                Permissions::STOCK_REPAIR,
                Permissions::STOCK_REPORT,
                Permissions::ORDERS_MANAGE,
            ],
            'warehouse.master_data' => [
                Permissions::PART_TYPES_MANAGE,
                Permissions::LOCATIONS_MANAGE,
                Permissions::SUPPLIERS_MANAGE,
            ],
        ]);
    }

    public function register(Panel $panel): void
    {
        $panel
            ->discoverResources(
                in: __DIR__.'/Filament/Resources',
                for: 'App\\Modules\\Warehouse\\Filament\\Resources',
            )
            ->discoverPages(
                in: __DIR__.'/Filament/Pages',
                for: 'App\\Modules\\Warehouse\\Filament\\Pages',
            )
            /*
             * Der Hinweis auf ueberfaellige Lieferungen gehoert auf die
             * Startseite, nicht in eine Liste, die man aufsuchen muss. Er ist
             * die Rueckfallebene der Erinnerung: Die Mail kann ausfallen -- kein
             * Mailserver eingerichtet, Postfach voll, Zugang abgelaufen --, die
             * Startseite nicht.
             */
            ->discoverWidgets(
                in: __DIR__.'/Filament/Widgets',
                for: 'App\\Modules\\Warehouse\\Filament\\Widgets',
            );
    }

    public function boot(Panel $panel): void {}
}
