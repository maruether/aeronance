<?php

declare(strict_types=1);

namespace App\Core\Identity;

use SensitiveParameter;

/**
 * Was ein Connector können muss -- und mehr nicht.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * BEWUSST SCHMAL. Ein Provider beantwortet zwei Fragen: "ist das dieser
 * Benutzer mit diesem Passwort?" und "wer gehoert alles dazu?". Alles Weitere
 * -- Rollen, Rechte, Konten anlegen, deaktivieren -- macht der Kern, weil es
 * fuer alle Provider gleich ablaufen muss.
 *
 * OIDC PASST TROTZDEM HINEIN, obwohl dort kein Passwort fliesst: Der Connector
 * fuehrt die Weiterleitung selbst und meldet danach ueber dieselbe
 * ExternalSubject-Struktur, wer zurueckkam. authenticate() bleibt dort
 * ungenutzt und gibt null zurueck -- deshalb steht supportsPassword() daneben,
 * damit die Anmeldemaske gar nicht erst ein Formular anbietet, das niemand
 * beantworten kann.
 * ─────────────────────────────────────────────────────────────────────────────
 */
interface IdentityProvider
{
    /** Kurzname, wie er in external_identities.provider steht. */
    public function name(): string;

    /** Wie er in der Oberfläche heisst. */
    public function label(): string;

    /**
     * Ob dieser Provider Benutzername und Passwort entgegennimmt.
     *
     * LDAP und Vereinsflieger: ja. OIDC: nein -- dort leitet der Connector
     * weiter, und das Passwort erreicht diese Anwendung nie.
     */
    public function supportsPassword(): bool;

    /**
     * Prüft Zugangsdaten und liefert das Subjekt -- oder null.
     *
     * NULL HEISST "falsche Zugangsdaten", nicht "Fehler". Ist der Provider
     * nicht erreichbar, gehört eine Ausnahme geworfen: Ein Ausfall, der wie ein
     * falsches Passwort aussieht, schickt einen ganzen Verein auf die Suche
     * nach seinem Tippfehler.
     */
    public function authenticate(
        string $username,
        #[SensitiveParameter] string $password,
    ): ?ExternalSubject;

    /**
     * Alle Subjekte, für den Abgleich.
     *
     * @return iterable<ExternalSubject>
     */
    public function members(): iterable;
}
