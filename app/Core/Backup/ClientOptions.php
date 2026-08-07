<?php

declare(strict_types=1);

namespace App\Core\Backup;

use PDO;
use RuntimeException;

/**
 * Die Optionsdatei, mit der sich mariadb und mariadb-dump verbinden.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WARUM DAS EINE EIGENE KLASSE IST UND KEINE METHODE JE COMMAND.
 *
 * Sie war eine -- in BackupCommand. RestoreCommand hatte seine eigene, ohne die
 * TLS-Zeile, und damit einen Fehler mit der denkbar schlechtesten Ausprägung:
 *
 *   Die Sicherung lief. Das Zurückspielen nicht.
 *
 * Genau davor warnt der Kommentar in BackupCommand ("the club finds out at
 * restore time") -- und dann ist es an der zweiten Stelle doch passiert.
 * Aufgefallen ist es in der CI, weil dort ein Client 11.8 gegen einen Server
 * 10.11 läuft; auf dem Entwicklungsrechner blieb es unsichtbar, weil ein
 * MariaDB ab 11.4 von sich aus TLS mit selbstsigniertem Zertifikat anbietet und
 * der Client deshalb zufrieden ist.
 *
 * Zwei Wahrheiten für dieselbe Verbindung sind eine zu viel. Deshalb steht hier
 * die einzige.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE TLS-REGEL, unverändert aus BackupCommand:
 *
 * mariadb-dump und mariadb VERLANGEN ab 11.4 von sich aus TLS. Zeigt man sie
 * auf einen gewöhnlichen lokalen Server -- und genau das ist die Installation
 * eines Vereins, PHP und MariaDB auf einer Maschine --, verweigern sie:
 *
 *   TLS/SSL error: SSL is required, but the server does not support it
 *
 * Die Regel ist NICHT "TLS abschalten". Sie ist: denselben Weg nehmen, den die
 * Anwendung nimmt. Verbindet Laravel über TLS (MYSQL_ATTR_SSL_CA gesetzt),
 * legt das Werkzeug dieselbe CA vor. Verbindet die Anwendung im Klartext --
 * lokaler Socket, Containernetz --, tut das Werkzeug dasselbe, statt vom Server
 * mehr zu verlangen als die Anwendung selbst.
 *
 * Geschrieben in die Optionsdatei und nicht auf die Kommandozeile, damit ein
 * Betreiber es in ~/.my.cnf noch übersteuern kann.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ClientOptions
{
    /**
     * Legt eine 0600-Optionsdatei an und gibt ihren Pfad zurück.
     *
     * Das Passwort gehört in eine Datei, nie auf die Kommandozeile: Argumente
     * sind in /proc für jeden Benutzer der Maschine lesbar, und dieses Projekt
     * hat auf diesem Weg schon einmal ein Passwort verloren.
     *
     * Der Aufrufer ist fürs Löschen zuständig -- in einem finally, damit die
     * Datei auch nach einem Fehlschlag verschwindet.
     *
     * @param  array<string, mixed>  $connection
     *
     * @throws RuntimeException
     */
    public static function writeFor(array $connection): string
    {
        $pfad = tempnam(sys_get_temp_dir(), 'aeronance-my');

        if ($pfad === false) {
            throw new RuntimeException('Es liess sich keine temporäre Datei anlegen.');
        }

        // Erst die Rechte, dann der Inhalt. Andersherum stünde das Passwort für
        // einen Augenblick mit 0644 da -- kurz, aber lang genug.
        chmod($pfad, 0600);

        file_put_contents($pfad, sprintf(
            "[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n%s",
            $connection['host'] ?? '127.0.0.1',
            $connection['port'] ?? 3306,
            $connection['username'] ?? '',
            $connection['password'] ?? '',
            self::tls($connection),
        ));

        return $pfad;
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    public static function tls(array $connection): string
    {
        /** @var array<int, mixed> $options */
        $options = (array) ($connection['options'] ?? []);

        $ca = $options[PDO::MYSQL_ATTR_SSL_CA] ?? null;

        if (filled($ca)) {
            return sprintf("ssl-ca=%s\n", $ca);
        }

        return "ssl=0\n";
    }
}
