<?php

declare(strict_types=1);

namespace App\Modules\Fleet;

use App\Core\Access\PermissionDefinition;
use App\Core\Modules\Contracts\AeronanceModule;
use App\Core\Modules\Manifest;
use Filament\Panel;

/**
 * The fleet: aircraft, what they have done, and what is fitted to them.
 *
 * Requires nothing. A club that keeps aircraft and no store installs this alone,
 * and the warehouse is the same the other way round -- which is why neither ever
 * holds a foreign key into the other's tables.
 *
 * What it DOES declare is that it works better with the warehouse: fitting a
 * part that came from stock closes the chain certificate -> lot -> part ->
 * aircraft in both directions. Without the warehouse, an installation is entered
 * by hand and the chain simply starts at the aircraft. Hence "provides" rather
 * than "requires": the relation is worth telling somebody about, not worth
 * refusing over.
 */
final class FleetModule implements AeronanceModule
{
    public function getId(): string
    {
        return 'fleet';
    }

    public function manifest(): Manifest
    {
        return new Manifest(
            name: 'fleet',
            version: '0.1.0',
            title: __('fleet.module.title'),
            description: __('fleet.module.description'),
            provides: ['aircraft-register'],
        );
    }

    /** @return list<PermissionDefinition> */
    public function permissions(): array
    {
        return PermissionDefinition::fromGroups([
            'fleet.aircraft' => [
                Permissions::FLEET_VIEW,
                Permissions::FLEET_MANAGE,
                Permissions::COUNTERS_RECORD,
                Permissions::COMPONENTS_MANAGE,
            ],
            'fleet.airworthiness' => [
                Permissions::PROGRAMME_MANAGE,
                Permissions::REVIEWS_RECORD,
                Permissions::EXTERNAL_WORK_MANAGE,
                Permissions::EXTERNAL_WORK_ACCEPT,
            ],
        ]);
    }

    public function register(Panel $panel): void
    {
        $panel
            ->discoverResources(
                in: __DIR__.'/Filament/Resources',
                for: 'App\\Modules\\Fleet\\Filament\\Resources',
            )
            ->discoverPages(
                in: __DIR__.'/Filament/Pages',
                for: 'App\\Modules\\Fleet\\Filament\\Pages',
            );
    }

    public function boot(Panel $panel): void {}
}
