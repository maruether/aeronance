<?php

declare(strict_types=1);

namespace App\Core\Access;

/**
 * One permission, as declared by the core or by a module.
 *
 * The name is a verb, not a screen: "stock.receive", not "may see the stock
 * page". That is the granularity a policy needs, and it is what the legacy
 * system was reaching for with its four undifferentiated warehouse rights.
 *
 * The prefix is the owning module, which is what lets the role editor group
 * permissions and lets a module's permissions disappear from the interface
 * when it is switched off -- without the assignments being lost.
 *
 * The human-readable label is not carried here: it lives in lang/de/permissions.php
 * under the permission name, because the interface is translatable and this
 * object is not.
 */
final readonly class PermissionDefinition
{
    /**
     * @param  list<string>  $defaultRoles  roles that should hold this from the start
     */
    public function __construct(
        public string $name,
        public string $group,
        public array $defaultRoles = [],
    ) {}

    /**
     * @param  array<string, list<string>>  $groups  group => permission names
     * @return list<self>
     */
    public static function fromGroups(array $groups): array
    {
        $definitions = [];

        foreach ($groups as $group => $names) {
            foreach ($names as $name) {
                $definitions[] = new self($name, $group);
            }
        }

        return $definitions;
    }

    /**
     * Declares which roles should hold a permission out of the box.
     *
     * Only a suggestion for the moment the permission comes into being. Once it
     * exists, who holds it is the club's business -- see AccessSetup, which never
     * re-syncs an existing assignment.
     *
     * @param  array<string, array<string, list<string>>>  $groups  group => role => permissions
     * @return list<self>
     */
    public static function fromGroupsWithRoles(array $groups): array
    {
        $byName = [];

        foreach ($groups as $group => $roles) {
            foreach ($roles as $role => $names) {
                foreach ($names as $name) {
                    $byName[$name] ??= ['group' => $group, 'roles' => []];
                    $byName[$name]['roles'][] = $role;
                }
            }
        }

        return array_values(array_map(
            fn (array $spec, string $name): self => new self($name, $spec['group'], $spec['roles']),
            $byName,
            array_keys($byName),
        ));
    }
}
