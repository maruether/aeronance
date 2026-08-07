<?php

declare(strict_types=1);

namespace App\Core\Access;

use Spatie\Permission\Models\Role;

/**
 * Brings roles and permissions to the state the current installation expects.
 *
 * Idempotent and additive: it creates what is missing and never removes or
 * reassigns anything. A club that has tailored its roles keeps that tailoring
 * across updates, and a permission that a module brought along survives the
 * module being switched off -- the assignment belongs to the role.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * There is one apparent exception, and it is not one: a permission that has just
 * been CREATED is granted to the roles its module named as defaults.
 *
 * That does not contradict "never reassigns", because nobody could have had an
 * opinion about a permission that did not exist a moment ago. There is no
 * tailoring to preserve. What stays untouched is every permission that was
 * already there -- the case the rule is actually about, where an installation may
 * deliberately have taken something away.
 *
 * Without this the defaults would be useless in practice: roles are created
 * during setup, modules are enabled months later, and a default that only
 * applied to installations where both happened in the same second would apply to
 * almost nobody.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class AccessSetup
{
    public function __construct(private PermissionRegistry $permissions) {}

    /**
     * @return array{permissions: list<string>, roles: list<string>, granted: array<string, list<string>>}
     */
    public function run(): array
    {
        $createdPermissions = $this->permissions->sync();
        $createdRoles = [];

        foreach (CoreRoles::defaultCorePermissions() as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

            if ($role !== null) {
                // Existing role: leave its permissions alone. They may have been
                // adjusted deliberately, and this runs on every update.
                continue;
            }

            $role = Role::create(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($permissions);
            $createdRoles[] = $roleName;
        }

        return [
            'permissions' => $createdPermissions,
            'roles' => $createdRoles,
            'granted' => $this->grantNewDefaults($createdPermissions),
        ];
    }

    /**
     * Hands brand-new permissions to the roles their module named.
     *
     * Only the ones just created -- see the class comment. Reported back rather
     * than done silently, because widening what a role may do is worth saying out
     * loud even when it is the intended behaviour.
     *
     * @param  list<string>  $createdPermissions
     * @return array<string, list<string>> role => permissions granted
     */
    private function grantNewDefaults(array $createdPermissions): array
    {
        if ($createdPermissions === []) {
            return [];
        }

        $granted = [];

        foreach ($this->permissions->active() as $definition) {
            if ($definition->defaultRoles === [] || ! in_array($definition->name, $createdPermissions, true)) {
                continue;
            }

            foreach ($definition->defaultRoles as $roleName) {
                $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

                if ($role === null) {
                    // The role comes into being above with its own defaults; a
                    // module naming a role that does not exist is simply ignored
                    // rather than inventing one.
                    continue;
                }

                if ($role->hasPermissionTo($definition->name)) {
                    continue;
                }

                $role->givePermissionTo($definition->name);
                $granted[$roleName][] = $definition->name;
            }
        }

        return $granted;
    }
}
