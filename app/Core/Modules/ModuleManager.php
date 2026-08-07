<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Modules\Contracts\AeronanceModule;
use App\Core\Modules\Events\ModuleDisabled;
use App\Core\Modules\Events\ModuleEnabled;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Throwable;

/**
 * Which modules are active, and switching them.
 *
 * State lives in the database rather than a file on disk: the setup wizard
 * writes the initial selection, it is instance configuration, it is covered by
 * the ordinary backup, and it avoids a file the web server must be able to
 * write to inside the application directory.
 *
 * That creates one condition worth naming: before the first migration the
 * table does not exist yet. Every read handles that and reports "nothing
 * active" instead of dying, because otherwise the setup wizard -- which runs
 * precisely in that state -- could never be reached.
 */
final class ModuleManager
{
    private const TABLE = 'modules';

    /** @var list<string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly DependencyResolver $resolver,
    ) {}

    /**
     * Names of the active modules.
     *
     * @return list<string>
     */
    public function enabled(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        try {
            $names = DB::table(self::TABLE)
                ->whereNotNull('enabled_at')
                ->pluck('name')
                ->all();
        } catch (QueryException) {
            // Table not migrated yet -- the state before the setup wizard has run.
            return $this->cache = [];
        }

        // A module may have been dropped from the release while its row remains.
        return $this->cache = array_values(array_filter(
            $names,
            fn (string $name): bool => $this->registry->has($name),
        ));
    }

    public function isEnabled(string $name): bool
    {
        return in_array($name, $this->enabled(), strict: true);
    }

    /** @return list<AeronanceModule> */
    public function enabledModules(): array
    {
        return array_map(
            fn (string $name): AeronanceModule => $this->registry->get($name),
            $this->enabled(),
        );
    }

    /**
     * May this module be switched on, and what comes along?
     * Ask before acting when the answer should be shown to the operator.
     */
    public function canEnable(string $name): Decision
    {
        return $this->resolver->canEnable($name, $this->enabled());
    }

    public function canDisable(string $name): Decision
    {
        return $this->resolver->canDisable($name, $this->enabled());
    }

    /**
     * Switch a module on, together with everything it requires.
     *
     * Refusals are exceptions rather than a silent false: switching a module on
     * against its dependencies is a programming error, and the caller that
     * wants to show reasons asks canEnable() first.
     *
     * @return list<string> the modules actually switched on
     */
    public function enable(string $name): array
    {
        $decision = $this->canEnable($name);

        if ($decision->isRefused()) {
            throw new RuntimeException(sprintf(
                'Module "%s" cannot be enabled: %s',
                $name,
                implode(' ', $decision->blockedBy),
            ));
        }

        $toEnable = array_values(array_diff(
            $this->resolver->requirementClosure($name),
            $this->enabled(),
        ));

        if ($toEnable === []) {
            return [];
        }

        DB::transaction(function () use ($toEnable): void {
            foreach ($toEnable as $module) {
                DB::table(self::TABLE)->updateOrInsert(
                    ['name' => $module],
                    [
                        'version' => $this->registry->manifest($module)->version,
                        'enabled_at' => now(),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        });

        $this->forgetCache();

        foreach ($toEnable as $module) {
            Event::dispatch(new ModuleEnabled($module));
        }

        return $toEnable;
    }

    /**
     * Switch a module off. Data is kept -- this is not an uninstall.
     */
    public function disable(string $name): void
    {
        $decision = $this->canDisable($name);

        if ($decision->isRefused()) {
            throw new RuntimeException(sprintf(
                'Module "%s" cannot be disabled: %s',
                $name,
                implode(' ', $decision->blockedBy),
            ));
        }

        if (! $this->isEnabled($name)) {
            return;
        }

        DB::table(self::TABLE)
            ->where('name', $name)
            ->update(['enabled_at' => null, 'updated_at' => now()]);

        $this->forgetCache();

        Event::dispatch(new ModuleDisabled($name));
    }

    /**
     * Whether the module table is reachable at all.
     *
     * Used by the setup wizard to tell "not installed yet" from "installed,
     * nothing selected".
     */
    public function isInstalled(): bool
    {
        try {
            DB::table(self::TABLE)->limit(1)->exists();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function forgetCache(): void
    {
        $this->cache = null;
    }
}
