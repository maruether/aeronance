<?php

declare(strict_types=1);

namespace App\Core\Modules\Events;

/**
 * A module was switched on.
 *
 * Modules listen for this to set up whatever they need on first activation --
 * seeding their permissions, for instance. Fired once per module, including
 * the ones pulled in as dependencies.
 */
final readonly class ModuleEnabled
{
    public function __construct(public string $module) {}
}
