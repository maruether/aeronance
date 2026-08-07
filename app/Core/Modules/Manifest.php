<?php

declare(strict_types=1);

namespace App\Core\Modules;

/**
 * What a module declares about itself.
 *
 * Three kinds of relation between modules, deliberately distinct:
 *
 *  - requires:  hard dependency. The module cannot run without it, so enabling
 *               pulls the dependency in and disabling is refused while a
 *               dependent module is still active.
 *  - conflicts: mutual exclusion. Never active at the same time.
 *  - provides:  a capability several modules may offer. Not an error, but the
 *               module management warns when a capability is provided more
 *               than once -- two identity providers, for instance, can both be
 *               active, and the operator should know that member records may
 *               then arrive from two sources.
 *
 * Optional collaboration between modules is deliberately NOT expressed here:
 * it runs through events, which simply go unheard when the other module is
 * absent. See docs/INFRASTRUKTUR.md, D6.
 */
final readonly class Manifest
{
    /**
     * @param  string  $name  stable identifier, lower-case, used in config and database
     * @param  list<string>  $requires  names of modules that must be active
     * @param  list<string>  $conflicts  names of modules that must not be active
     * @param  list<string>  $provides  capabilities this module offers
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $title,
        public string $description,
        public array $requires = [],
        public array $conflicts = [],
        public array $provides = [],
    ) {}
}
