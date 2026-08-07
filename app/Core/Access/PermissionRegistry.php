<?php

declare(strict_types=1);

namespace App\Core\Access;

use App\Core\Modules\ModuleManager;
use Spatie\Permission\Models\Permission;

/**
 * Collects the permissions of the core and of the active modules, and keeps
 * the database in step with them.
 *
 * Synchronising only ever ADDS. Permissions of a module that has been switched
 * off are left alone: the assignments belong to the roles, and switching a
 * module back on must not mean handing out the rights again by hand. That is
 * the same principle as "deactivating is not uninstalling", applied to access.
 */
final readonly class PermissionRegistry
{
    public function __construct(private ModuleManager $modules) {}

    /**
     * Everything currently in force: core plus active modules.
     *
     * @return list<PermissionDefinition>
     */
    public function active(): array
    {
        $definitions = CorePermissions::all();

        foreach ($this->modules->enabledModules() as $module) {
            foreach ($module->permissions() as $definition) {
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * Grouped for display, in the order the groups were declared.
     *
     * @return array<string, list<PermissionDefinition>>
     */
    public function grouped(): array
    {
        $grouped = [];

        foreach ($this->active() as $definition) {
            $grouped[$definition->group][] = $definition;
        }

        return $grouped;
    }

    /**
     * Create any permissions that do not exist yet.
     *
     * Idempotent, so it can run after every migration and on every module
     * activation without a second thought.
     *
     * @return list<string> the permissions actually created
     */
    public function sync(): array
    {
        $existing = Permission::query()->pluck('name')->all();
        $created = [];

        foreach ($this->active() as $definition) {
            if (in_array($definition->name, $existing, strict: true)) {
                continue;
            }

            Permission::create(['name' => $definition->name, 'guard_name' => 'web']);
            $created[] = $definition->name;
        }

        return $created;
    }
}
