<?php

declare(strict_types=1);

namespace App\Modules\Inspection;

use App\Core\Access\PermissionDefinition;
use App\Core\Modules\Contracts\AeronanceModule;
use App\Core\Modules\Manifest;
use Filament\Panel;

/**
 * Eingangsprüfung — the first of the Part-145 building blocks.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT IT BUYS: goods cannot go from the delivery van to the aircraft without
 * somebody having looked at the paperwork and said so in writing.
 *
 * Without it, the moment a part is booked in it is available, and the only
 * record that anybody checked the certificate is that everyone remembers being
 * careful. 145.A.42 asks for the classification of arriving components to be
 * established and recorded; this is where that happens.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * REQUIRES THE WAREHOUSE, obviously -- there is nothing to inspect without
 * goods arriving. It is a hard requirement, not an optional one: every single
 * thing this module does hangs off a stock receipt.
 *
 * It touches the warehouse in exactly two places, both of them the warehouse's
 * own front door: it listens for StockReceived, and it moves lots through
 * ChangeLotState. No warehouse table is written by this module, which is what
 * makes turning it off a genuine no-op rather than a hopeful one.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class InspectionModule implements AeronanceModule
{
    public function getId(): string
    {
        return 'inspection';
    }

    public function manifest(): Manifest
    {
        return new Manifest(
            name: 'inspection',
            version: '0.1.0',
            title: __('inspection.module.title'),
            description: __('inspection.module.description'),
            requires: ['warehouse'],
        );
    }

    /** @return list<PermissionDefinition> */
    public function permissions(): array
    {
        return PermissionDefinition::fromGroups([
            'inspection' => [
                Permissions::INSPECTION_VIEW,
                Permissions::INSPECTION_PERFORM,
            ],
        ]);
    }

    public function register(Panel $panel): void
    {
        $panel
            ->discoverResources(
                in: __DIR__.'/Filament/Resources',
                for: 'App\\Modules\\Inspection\\Filament\\Resources',
            );
    }

    public function boot(Panel $panel): void
    {
        // The listener is wired in ModuleServiceProvider, where every other
        // cross-module wire lives: boot() here runs for a browser request and
        // for nothing else, and goods get booked in by console commands too.
    }
}
