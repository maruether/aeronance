<?php

declare(strict_types=1);

namespace App\Core\Identity;

/**
 * Wer jemand BEIM PROVIDER ist -- die Aussenseite, und nichts darüber hinaus.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Was hier steht, kann jeder der drei geplanten Connectoren liefern:
 *
 *   Vereinsflieger   UID, Benutzername, Name, E-Mail, Vereinsfunktionen
 *   LDAP / Samba AD  objectGUID, sAMAccountName, cn, mail, memberOf
 *   OIDC             sub, preferred_username, name, email, groups
 *
 * Was hier NICHT steht, ist genauso wichtig: keine Rollen, keine Rechte, keine
 * Qualifikationen. Ein Provider sagt, WER jemand ist; was das im Betrieb
 * bedeutet, entscheidet der Kern -- E4.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class ExternalSubject
{
    /**
     * @param  string  $id  die Kennung beim Provider (VF-UID, objectGUID, sub)
     * @param  list<string>  $groups  externe Gruppen: VF-Funktionen, AD-Gruppen, OIDC-Claims
     */
    public function __construct(
        public string $id,
        public string $username,
        public string $name,
        public ?string $email = null,
        public array $groups = [],

        /**
         * Ob das Subjekt beim Provider noch aktiv ist.
         *
         * Ein ausgetretenes Mitglied wird hier NICHT geloescht, sondern als
         * inaktiv gemeldet -- der Kern deaktiviert daraufhin das Konto und
         * behaelt es. Geloeschte Konten reissen Loecher in die Nachweiskette,
         * und CLAUDE.md verbietet hartes Loeschen ohnehin.
         */
        public bool $active = true,
    ) {}
}
