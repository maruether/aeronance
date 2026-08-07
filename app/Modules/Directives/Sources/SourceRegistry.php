<?php

declare(strict_types=1);

namespace App\Modules\Directives\Sources;

use RuntimeException;

/**
 * The manufacturer sub-modules, and the two sources that always exist.
 *
 * Registered rather than hard-coded so a manufacturer adapter is an addition, not
 * an edit: it registers itself in its own boot() and appears in the import screen
 * without anything here changing. Same shape as the permission registry and the
 * airworthiness contributors -- a pattern this project already relies on twice.
 *
 * Bound as a singleton in ModuleServiceProvider, and that binding is not optional:
 * without it every app() call hands out a fresh empty registry, so boot()
 * registers into one instance and the import screen reads another. The first
 * version of this class documented the singleton without binding it, and every
 * import failed with "no such source".
 */
final class SourceRegistry
{
    /** @var array<string, DirectiveSource> */
    private array $sources = [];

    public function register(DirectiveSource $source): void
    {
        $this->sources[$source->name()] = $source;
    }

    public function get(string $name): DirectiveSource
    {
        return $this->sources[$name]
            ?? throw new RuntimeException(sprintf('No directive source named "%s" is registered.', $name));
    }

    public function has(string $name): bool
    {
        return isset($this->sources[$name]);
    }

    /** @return array<string, DirectiveSource> */
    public function all(): array
    {
        return $this->sources;
    }

    /**
     * Sources that can run unattended.
     *
     * What a scheduled refresh would iterate over. Empty until the first
     * manufacturer adapter exists, and a command over an empty list is a no-op
     * rather than an error.
     *
     * @return array<string, DirectiveSource>
     */
    public function automatic(): array
    {
        return array_filter($this->sources, fn (DirectiveSource $s): bool => $s->isAutomatic());
    }

    /** @return array<string, string> name => label, for a select */
    public function options(): array
    {
        return array_map(fn (DirectiveSource $s): string => $s->label(), $this->sources);
    }
}
