<?php

declare(strict_types=1);

namespace App\Modules\TaskCards;

use App\Core\Access\PermissionDefinition;
use App\Core\Modules\Contracts\AeronanceModule;
use App\Core\Modules\Manifest;
use Filament\Panel;

/**
 * Work orders, task cards, findings and hours.
 *
 * REQUIRES THE FLEET, and this is the project's first hard dependency. The
 * reason is not convenience: a card records work on an aircraft, and without
 * somewhere for aircraft to live there is nothing to record it against. Cards
 * floating free of a fleet would be notes.
 *
 * Which is also why this module may hold a real foreign key to aircraft, where
 * the warehouse deliberately keeps a plain string -- the warehouse does NOT
 * require the fleet, so its reference has to survive the table not existing.
 *
 * The warehouse is a "provides" relationship rather than a requirement: parts
 * come out of stock when there is stock, and are simply not recorded when there
 * is not. CLAUDE.md puts it as "Teileentnahme nur, wenn das Lagermodul aktiv
 * ist" -- a capability, not a precondition.
 */
final class TaskCardsModule implements AeronanceModule
{
    public function getId(): string
    {
        return 'taskcards';
    }

    public function manifest(): Manifest
    {
        return new Manifest(
            name: 'taskcards',
            version: '0.1.0',
            title: __('taskcards.module.title'),
            description: __('taskcards.module.description'),
            requires: ['fleet'],
            provides: ['maintenance-record'],
        );
    }

    /** @return list<PermissionDefinition> */
    public function permissions(): array
    {
        return PermissionDefinition::fromGroups([
            'taskcards.work' => [
                Permissions::WORK_ORDERS_VIEW,
                Permissions::WORK_ORDERS_MANAGE,
                Permissions::CARDS_WORK,
                /*
                 * Die unabhaengige Kontrolle steht bei der ARBEIT, nicht bei
                 * der Freigabe -- sie ist kein Freigaberecht, sondern ein
                 * zweites Augenpaar, und wer sie an die Freigabegruppe haengt,
                 * macht sie in kleinen Vereinen unmoeglich. Siehe Permissions.
                 */
                Permissions::CARDS_INSPECT,
            ],
            'taskcards.certify' => [
                Permissions::CARDS_CERTIFY,
                Permissions::RELEASES_RECORD_EXTERNAL,
                Permissions::FINDINGS_RECORD,
                Permissions::FINDINGS_DEFER,
            ],

            /*
             * Eigene Gruppe, damit ein Verein sie GROB verteilen kann: Das
             * Melden geht an "jeden P/O oder höher" -- also an Rollen, die
             * sonst nichts aus der Werkstatt tragen sollen.
             */
            'taskcards.report' => [
                Permissions::FINDINGS_REPORT,
            ],
        ]);
    }

    public function register(Panel $panel): void
    {
        $panel
            ->discoverResources(
                in: __DIR__.'/Filament/Resources',
                for: 'App\\Modules\\TaskCards\\Filament\\Resources',
            )
            ->discoverPages(
                in: __DIR__.'/Filament/Pages',
                for: 'App\\Modules\\TaskCards\\Filament\\Pages',
            );
    }

    /**
     * Nothing to do at panel boot.
     *
     * The airworthiness contribution used to be registered here and is now in
     * the container: a Filament plugin's boot() runs for a browser request and
     * for nothing else, so an aircraft asked outside a request reported no open
     * findings at all.
     */
    public function boot(Panel $panel): void
    {
        //
    }
}
