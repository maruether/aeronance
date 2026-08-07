<?php

declare(strict_types=1);

namespace App\Core\Modules;

/**
 * Several active modules offer the same capability.
 *
 * Carried as data, not as a finished sentence: the resolver is pure domain
 * logic and has no business knowing about the translation layer. The interface
 * turns this into German text -- see lang/de/modules.php.
 */
final readonly class CapabilityWarning
{
    /**
     * @param  list<string>  $moduleTitles
     */
    public function __construct(
        public string $capability,
        public array $moduleTitles,
    ) {}
}
