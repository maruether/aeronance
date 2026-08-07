<?php

declare(strict_types=1);

namespace App\Core\Modules;

/**
 * The answer to "may this module be switched on or off, and what follows?"
 *
 * Deliberately more than a boolean. CLAUDE.md requires the module management
 * to explain to the operator *why* something has to come along or cannot be
 * combined -- so the reasons travel with the verdict instead of being
 * reconstructed at the call site.
 */
final readonly class Decision
{
    /**
     * @param  list<string>  $blockedBy  human-readable reasons the action is refused
     * @param  list<string>  $alsoAffects  modules that would be switched along with it
     * @param  list<CapabilityWarning>  $warnings  allowed, but the operator should know
     */
    private function __construct(
        public bool $allowed,
        public array $blockedBy = [],
        public array $alsoAffects = [],
        public array $warnings = [],
    ) {}

    /**
     * @param  list<string>  $alsoAffects
     * @param  list<CapabilityWarning>  $warnings
     */
    public static function allow(array $alsoAffects = [], array $warnings = []): self
    {
        return new self(true, alsoAffects: $alsoAffects, warnings: $warnings);
    }

    /**
     * @param  list<string>  $reasons
     */
    public static function refuse(array $reasons): self
    {
        return new self(false, blockedBy: $reasons);
    }

    public function isRefused(): bool
    {
        return ! $this->allowed;
    }
}
