<?php

declare(strict_types=1);

namespace App\Core\Modules;

use InvalidArgumentException;

/**
 * Answers whether a module may be switched on or off, and what comes along.
 *
 * Pure: it is handed the current set of active modules and returns a verdict.
 * No database, no state of its own -- which is what makes the awkward cases
 * (diamond dependencies, cycles, conflicts arriving through a dependency)
 * straightforward to test.
 */
final readonly class DependencyResolver
{
    public function __construct(private ModuleRegistry $registry) {}

    /**
     * @param  list<string>  $active  currently active modules
     */
    public function canEnable(string $name, array $active): Decision
    {
        if (! $this->registry->has($name)) {
            throw new InvalidArgumentException(sprintf('Unknown module "%s".', $name));
        }

        if (in_array($name, $active, strict: true)) {
            return Decision::allow();
        }

        // Everything that would have to come along, the module itself included.
        $toEnable = $this->requirementClosure($name);
        $additions = array_values(array_diff($toEnable, $active));
        $resulting = array_values(array_unique([...$active, ...$toEnable]));

        $blocked = [];

        foreach ($resulting as $candidate) {
            $manifest = $this->registry->manifest($candidate);

            foreach ($manifest->conflicts as $conflict) {
                if (! in_array($conflict, $resulting, strict: true)) {
                    continue;
                }

                $other = $this->registry->manifest($conflict);

                $blocked[] = $candidate === $name || $conflict === $name
                    ? sprintf('"%s" cannot be combined with "%s".', $manifest->title, $other->title)
                    : sprintf(
                        '"%s" would pull in "%s", which cannot be combined with "%s".',
                        $this->registry->manifest($name)->title,
                        $manifest->title,
                        $other->title,
                    );
            }
        }

        if ($blocked !== []) {
            return Decision::refuse(array_values(array_unique($blocked)));
        }

        return Decision::allow(
            alsoAffects: array_values(array_diff($additions, [$name])),
            warnings: $this->capabilityWarnings($resulting),
        );
    }

    /**
     * @param  list<string>  $active
     */
    public function canDisable(string $name, array $active): Decision
    {
        if (! $this->registry->has($name)) {
            throw new InvalidArgumentException(sprintf('Unknown module "%s".', $name));
        }

        if (! in_array($name, $active, strict: true)) {
            return Decision::allow();
        }

        $blockers = array_values(array_filter(
            $this->registry->dependentsOf($name),
            static fn (string $dependent): bool => in_array($dependent, $active, strict: true),
        ));

        if ($blockers !== []) {
            $title = $this->registry->manifest($name)->title;

            return Decision::refuse(array_map(
                fn (string $dependent): string => sprintf(
                    '"%s" needs "%s" and is still active.',
                    $this->registry->manifest($dependent)->title,
                    $title,
                ),
                $blockers,
            ));
        }

        return Decision::allow();
    }

    /**
     * The module plus every module it transitively requires.
     *
     * @return list<string>
     */
    public function requirementClosure(string $name): array
    {
        $seen = [];
        $this->walkRequirements($name, $seen, []);

        return array_keys($seen);
    }

    /**
     * @param  array<string, true>  $seen
     * @param  list<string>  $path  the chain that led here, for cycle reporting
     */
    private function walkRequirements(string $name, array &$seen, array $path): void
    {
        if (in_array($name, $path, strict: true)) {
            throw new InvalidArgumentException(sprintf(
                'Circular dependency between modules: %s.',
                implode(' -> ', [...$path, $name]),
            ));
        }

        if (isset($seen[$name])) {
            return;
        }

        $seen[$name] = true;

        foreach ($this->registry->manifest($name)->requires as $required) {
            $this->walkRequirements($required, $seen, [...$path, $name]);
        }
    }

    /**
     * Capabilities offered by more than one active module.
     *
     * Not an error -- two identity providers side by side are a legitimate
     * arrangement, and forbidding it would turn a migration from Vereinsflieger
     * to Active Directory into a hard cut instead of a transition. But member
     * records then arrive from two sources and the same person can end up with
     * two accounts, so the operator gets told.
     *
     * @param  list<string>  $active
     * @return list<CapabilityWarning>
     */
    private function capabilityWarnings(array $active): array
    {
        /** @var array<string, list<string>> $byCapability */
        $byCapability = [];

        foreach ($active as $name) {
            foreach ($this->registry->manifest($name)->provides as $capability) {
                $byCapability[$capability][] = $this->registry->manifest($name)->title;
            }
        }

        $warnings = [];

        foreach ($byCapability as $capability => $titles) {
            if (count($titles) < 2) {
                continue;
            }

            $warnings[] = new CapabilityWarning($capability, $titles);
        }

        return $warnings;
    }
}
