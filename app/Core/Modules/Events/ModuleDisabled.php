<?php

declare(strict_types=1);

namespace App\Core\Modules\Events;

/**
 * A module was switched off.
 *
 * Deactivation is not an uninstall: listeners stop scheduled work and clear
 * caches, but must not delete any data.
 */
final readonly class ModuleDisabled
{
    public function __construct(public string $module) {}
}
