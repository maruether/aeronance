<?php

declare(strict_types=1);

namespace App\Core\Access;

/**
 * The permissions the core owns.
 *
 * Deliberately few. Everything to do with parts, aircraft or task cards belongs
 * to the module that owns it -- the core knows about people, roles and the
 * system itself, and nothing about the domain.
 *
 * Note what is NOT here: there is no permission to delete audit entries. That
 * is decision E3, and its absence is the mechanism, not an oversight.
 */
final class CorePermissions
{
    public const USERS_VIEW = 'core.users.view';

    public const USERS_MANAGE = 'core.users.manage';

    public const ROLES_MANAGE = 'core.roles.manage';

    /** Assigning a qualification is not the same as administering roles: it
     *  asserts that someone holds an external credential. See E8. */
    public const QUALIFICATIONS_MANAGE = 'core.qualifications.manage';

    public const AUDIT_VIEW = 'core.audit.view';

    /** Replaces personal data of former members in the activity log while
     *  leaving certificate content untouched. See E3a. */
    public const AUDIT_PSEUDONYMISE = 'core.audit.pseudonymise';

    public const MODULES_MANAGE = 'core.modules.manage';

    public const SETTINGS_MANAGE = 'core.settings.manage';

    /**
     * @return list<PermissionDefinition>
     */
    public static function all(): array
    {
        return PermissionDefinition::fromGroups([
            'core.people' => [
                self::USERS_VIEW,
                self::USERS_MANAGE,
                self::ROLES_MANAGE,
                self::QUALIFICATIONS_MANAGE,
            ],
            'core.system' => [
                self::AUDIT_VIEW,
                self::AUDIT_PSEUDONYMISE,
                self::MODULES_MANAGE,
                self::SETTINGS_MANAGE,
            ],
        ]);
    }
}
