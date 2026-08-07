<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Modules\Contracts\AeronanceModule;
use InvalidArgumentException;

/**
 * Knows every module that ships with this release.
 *
 * The list is explicit, from config/aeronance.php -- no directory scan. What
 * ships is a fixed, reviewable set, and an explicit list is the most direct
 * expression of the guardrail "no loading of code at runtime". It also shows
 * up in the diff when it changes, which a scan never would.
 *
 * Being in the registry says nothing about being active. That is
 * ModuleManager's business.
 */
final class ModuleRegistry
{
    /** @var array<string, AeronanceModule> */
    private array $modules = [];

    /**
     * Accepts class names -- how config/aeronance.php lists them -- or ready-made
     * instances, which is what tests hand in.
     *
     * @param  list<class-string<AeronanceModule>|AeronanceModule>  $modules
     */
    public function __construct(array $modules = [])
    {
        foreach ($modules as $entry) {
            $module = is_string($entry) ? new $entry : $entry;

            if (! $module instanceof AeronanceModule) {
                throw new InvalidArgumentException(sprintf(
                    '%s is listed as a module but does not implement %s.',
                    is_string($entry) ? $entry : $entry::class,
                    AeronanceModule::class,
                ));
            }

            $name = $module->manifest()->name;

            if (isset($this->modules[$name])) {
                throw new InvalidArgumentException(
                    sprintf('Two modules claim the name "%s". Names must be unique.', $name)
                );
            }

            $this->modules[$name] = $module;
        }

        $this->assertReferencesExist();
    }

    /** @return array<string, AeronanceModule> */
    public function all(): array
    {
        return $this->modules;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->modules);
    }

    public function has(string $name): bool
    {
        return isset($this->modules[$name]);
    }

    public function get(string $name): AeronanceModule
    {
        return $this->modules[$name]
            ?? throw new InvalidArgumentException(sprintf('Unknown module "%s".', $name));
    }

    public function manifest(string $name): Manifest
    {
        return $this->get($name)->manifest();
    }

    /**
     * Names of all modules that hard-depend on the given one.
     *
     * @return list<string>
     */
    public function dependentsOf(string $name): array
    {
        $dependents = [];

        foreach ($this->modules as $candidate => $module) {
            if (in_array($name, $module->manifest()->requires, strict: true)) {
                $dependents[] = $candidate;
            }
        }

        return $dependents;
    }

    /**
     * A manifest referring to a module that does not ship is a packaging error,
     * not a runtime condition -- so it fails loudly and immediately rather than
     * surfacing later as a puzzling dependency check.
     */
    private function assertReferencesExist(): void
    {
        foreach ($this->modules as $name => $module) {
            $manifest = $module->manifest();

            foreach ([...$manifest->requires, ...$manifest->conflicts] as $referenced) {
                if (! $this->has($referenced)) {
                    throw new InvalidArgumentException(sprintf(
                        'Module "%s" refers to "%s", which is not part of this release.',
                        $name,
                        $referenced,
                    ));
                }
            }

            if (in_array($name, $manifest->requires, strict: true)) {
                throw new InvalidArgumentException(sprintf('Module "%s" requires itself.', $name));
            }
        }
    }
}
