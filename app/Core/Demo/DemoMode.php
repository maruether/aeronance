<?php

declare(strict_types=1);

namespace App\Core\Demo;

use App\Core\Access\CoreRoles;
use Illuminate\Support\Carbon;

/**
 * Ob diese Installation eine Spielwiese ist.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE MARKE IST EINE DATEI, wie bei der Installation selbst -- und aus einem
 * Grund, der erst beim Zurücksetzen auffällt: Der tägliche Reset wirft die
 * Datenbank weg (`migrate:fresh`). Stünde die Marke in einer Tabelle, wäre sie
 * beim ersten Reset mit weg -- und der zweite Reset fände eine Instanz vor, die
 * sich für eine Live-Installation hält. Das Löschkommando würde sich zu Recht
 * weigern, und die Demo bliebe für immer beim Stand von gestern stehen.
 *
 * EINE WAHL, KEINE EINSTELLUNG. Vorgabe: „auswahl bei der installation ob demo
 * oder live, kein nachträgliches umstellen." Deshalb schreibt nur der
 * Setup-Assistent die Marke, und es gibt keinen Weg, sie über die Oberfläche
 * wieder loszuwerden. Eine Umgebungsvariable ALLEIN wäre zu wenig gewesen: Eine
 * Zeile in der `.env` macht aus einer Demo eine Live-Instanz und umgekehrt --
 * und aus dem täglichen Reset ein Löschkommando auf echten Daten.
 *
 * Die Umgebungsvariable gibt es trotzdem, aber nur als VORAUSWAHL für den
 * Assistenten: Im Docker-Kanal beantwortet der Betreiber die Frage über die
 * Env, statt sie im Browser noch einmal zu bekommen.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class DemoMode
{
    private const MARKER = 'demo';

    /**
     * Die festen Konten der Demo -- Benutzername ist der Rollenname.
     *
     * Vorgabe: „es gibt standard user für die standardrollen, alle mit
     * Benutername=Rollenname und Passwort=demo dies ist nicht änderbar."
     *
     * Deutsch, weil die Oberfläche deutsch ist und diese Namen auf der
     * Anmeldeseite stehen; die Rolle daneben ist der interne Bezeichner. Der
     * Halter ist der sechste: ohne ihn liesse sich der ganze
     * Pilot-Owner-Zweig nicht vorführen.
     *
     * @var array<string, array{name: string, role: string, description: string}>
     */
    public const ACCOUNTS = [
        'admin' => [
            'name' => 'Alex Admin',
            'role' => CoreRoles::ADMIN,
            'description' => 'Einstellungen, Module, Benutzer',
        ],
        'werkstattleiter' => [
            'name' => 'Wanda Werkstatt',
            'role' => CoreRoles::WORKSHOP_MANAGER,
            'description' => 'Vorgänge eröffnen und schliessen, Lager, Bestellungen',
        ],
        'freigabeberechtigter' => [
            'name' => 'Karl Kluge',
            'role' => CoreRoles::CERTIFYING_STAFF,
            'description' => 'Arbeitskarten abzeichnen, Freigaben erteilen (Part-66)',
        ],
        'mechaniker' => [
            'name' => 'Hilde Hobel',
            'role' => CoreRoles::MECHANIC,
            'description' => 'Arbeitskarten bearbeiten, Zeiten und Befunde erfassen',
        ],
        'halter' => [
            'name' => 'Petra Pilot',
            'role' => CoreRoles::MEMBER,
            'description' => 'Pilot-Owner für zwei der drei Luftfahrzeuge',
        ],
        'mitglied' => [
            'name' => 'Mia Mitglied',
            'role' => CoreRoles::MEMBER,
            'description' => 'nur lesen',
        ],
    ];

    /** Die Mailadresse, mit der man sich anmeldet -- Anmeldung läuft über sie. */
    public static function email(string $account): string
    {
        return $account.'@demo.aeronance.de';
    }

    /** Das Passwort aller Demokonten. Steht auf der Anmeldeseite; das ist der Zweck. */
    public const PASSWORD = 'demo';

    public function isActive(): bool
    {
        return file_exists($this->markerPath());
    }

    /**
     * Ob der Betreiber die Frage schon in der Umgebung beantwortet hat.
     *
     * Nur eine Voreinstellung für den Assistenten -- entschieden wird dort.
     */
    public function preselected(): bool
    {
        return (bool) config('aeronance.demo.preselect');
    }

    /**
     * Setzt die Marke. Nur der Setup-Assistent ruft das auf.
     *
     * Ohne Gegenstück: Es gibt bewusst kein deactivate(). „Kein nachträgliches
     * Umstellen" ist keine Bitte an den Benutzer, sondern eine fehlende
     * Funktion.
     */
    public function activate(): void
    {
        $verzeichnis = dirname($this->markerPath());

        if (! is_dir($verzeichnis)) {
            mkdir($verzeichnis, 0o750, recursive: true);
        }

        file_put_contents($this->markerPath(), implode("\n", [
            'Aeronance läuft im Demomodus, gewählt am '.now()->toIso8601String(),
            '',
            'Diese Datei entscheidet, ob diese Installation eine Spielwiese ist:',
            'Es gibt feste Demokonten mit bekannten Passwörtern, Uploads und',
            'Mailversand sind abgeschaltet, und der Datenbestand wird TÄGLICH',
            'vollständig gelöscht und neu aufgesetzt.',
            '',
            'Wird sie entfernt, hält sich die Instanz für eine Live-Installation --',
            'mit den offenen Demokonten darin. Wird sie auf einer Live-Installation',
            'angelegt, löscht der nächste nächtliche Lauf deren Datenbank.',
            'Beides nur tun, wenn genau das beabsichtigt ist.',
            '',
        ]));
    }

    public function markerPath(): string
    {
        return storage_path(self::MARKER);
    }

    /** Wann der nächste Reset läuft -- fürs Anzeigen, nicht fürs Auslösen. */
    public function nextReset(): Carbon
    {
        $uhrzeit = (string) config('aeronance.demo.reset_at', '03:00');

        [$stunde, $minute] = array_pad(array_map('intval', explode(':', $uhrzeit)), 2, 0);

        $naechster = now()->setTime($stunde, $minute);

        return $naechster->isPast() ? $naechster->addDay() : $naechster;
    }

    /**
     * Ob dieses Konto zu den festen Demokonten gehört.
     *
     * Sie sind unveränderlich: kein neues Passwort, keine andere Rolle, kein
     * Deaktivieren, kein Löschen. Sonst wäre die Demo nach dem ersten Besucher,
     * der „admin" das Passwort ändert, für alle anderen zu.
     */
    public function isProtectedAccount(?string $email): bool
    {
        if ($email === null || ! $this->isActive()) {
            return false;
        }

        foreach (array_keys(self::ACCOUNTS) as $konto) {
            if (strcasecmp($email, self::email($konto)) === 0) {
                return true;
            }
        }

        return false;
    }
}
