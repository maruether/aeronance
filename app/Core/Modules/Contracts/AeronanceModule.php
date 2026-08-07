<?php

declare(strict_types=1);

namespace App\Core\Modules\Contracts;

use App\Core\Access\PermissionDefinition;
use App\Core\Modules\Manifest;
use Filament\Contracts\Plugin;

/**
 * A Aeronance module.
 *
 * Extends Filament's Plugin contract on purpose: a module IS the unit Filament
 * already knows how to mount into a panel. A disabled module simply never gets
 * mounted, so its screens do not exist for the application -- no hidden routes,
 * no navigation entries to filter out afterwards.
 *
 * This covers visibility only. Access and background work are switched off
 * separately; see docs/INFRASTRUKTUR.md, D3.
 */
interface AeronanceModule extends Plugin
{
    public function manifest(): Manifest;

    /**
     * The permissions this module owns.
     *
     * Declared rather than seeded by the module itself, so that synchronising
     * happens in one place and stays idempotent -- and so the role editor can
     * group permissions by the module they belong to. When a module is switched
     * off its permissions vanish from the interface; the assignments survive in
     * the database, because deactivating is not uninstalling.
     *
     * @return list<PermissionDefinition>
     */
    public function permissions(): array;
}
