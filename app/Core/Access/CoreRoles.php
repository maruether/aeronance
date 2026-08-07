<?php

declare(strict_types=1);

namespace App\Core\Access;

/**
 * The roles every installation starts with.
 *
 * Identifiers are English, following the guardrail that code and database are
 * English while the interface is German -- the German names live in
 * lang/de/roles.php. CLAUDE.md names them in German prose; these are the same
 * five roles.
 *
 * Roles are a starting point, not a fixed set: a club may add its own, and the
 * permissions attached here are only the sensible default. What a role may do
 * is administered, not compiled in.
 *
 * Note that no role carries every permission. "Someone has to be able to do
 * everything" is answered by the break-glass access from E2, which runs through
 * the console and is logged -- not by a role that quietly outranks the
 * permission system, as the legacy is_admin flag did.
 */
final class CoreRoles
{
    public const ADMIN = 'admin';

    public const WORKSHOP_MANAGER = 'workshop_manager';

    public const CERTIFYING_STAFF = 'certifying_staff';

    public const MECHANIC = 'mechanic';

    public const MEMBER = 'member';

    /**
     * Rollen, die ein Identity-Provider NIEMALS vergeben darf.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * DIE EINE STELLE, AN DER DIE ZUORDNUNG STRUKTURELL AUFHOERT.
     *
     * Aus der Analyse (§6.4): Vereinsfunktion und Werkstattqualifikation sind
     * zwei verschiedene Dinge. Wer im Verein "Werkstattleiter" heisst, ist eine
     * Organisationsaussage. Ob jemand freigabeberechtigt ist, ist eine
     * QUALIFIKATIONSAUSSAGE -- mit Lizenznachweis, Recency-Anforderung (66.A.20)
     * und Haftungsfolge. Vereinsflieger kennt diese Kategorie nicht, ein AD
     * ebenso wenig.
     *
     * Eine automatische Ableitung wuerde also genau dort versagen, wo
     * Korrektheit am meisten zaehlt -- und ein spaeteres Audit fragt bei genau
     * dieser Rolle nach dem Nachweis. Die Zuordnung verweigert sie deshalb, statt
     * es dem Administrator zu ueberlassen, daran zu denken.
     *
     * Dass diese Liste existiert, ist der Mechanismus. Sie zu leeren waere die
     * Entscheidung, Freigabeberechtigung aus einer Gruppenmitgliedschaft
     * abzuleiten.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @return list<string>
     */
    public static function neverFromProvider(): array
    {
        return [self::CERTIFYING_STAFF];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::ADMIN,
            self::WORKSHOP_MANAGER,
            self::CERTIFYING_STAFF,
            self::MECHANIC,
            self::MEMBER,
        ];
    }

    /**
     * Default core permissions per role.
     *
     * Module permissions are not listed here: a module that is not installed
     * has none, and handing them out is the club's decision once the module is
     * switched on.
     *
     * @return array<string, list<string>>
     */
    public static function defaultCorePermissions(): array
    {
        return [
            self::ADMIN => [
                CorePermissions::USERS_VIEW,
                CorePermissions::USERS_MANAGE,
                CorePermissions::ROLES_MANAGE,
                CorePermissions::QUALIFICATIONS_MANAGE,
                CorePermissions::AUDIT_VIEW,
                CorePermissions::AUDIT_PSEUDONYMISE,
                CorePermissions::MODULES_MANAGE,
                CorePermissions::SETTINGS_MANAGE,
            ],
            self::WORKSHOP_MANAGER => [
                CorePermissions::USERS_VIEW,
                CorePermissions::QUALIFICATIONS_MANAGE,
                CorePermissions::AUDIT_VIEW,
            ],
            self::CERTIFYING_STAFF => [
                CorePermissions::USERS_VIEW,
                CorePermissions::AUDIT_VIEW,
            ],
            self::MECHANIC => [
                CorePermissions::USERS_VIEW,
            ],
            self::MEMBER => [],
        ];
    }
}
