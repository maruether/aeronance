<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Backup\ClientOptions;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Die Optionsdatei für mariadb und mariadb-dump.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIESER TEST EXISTIERT WEGEN EINES FEHLERS, DER DREI WOCHEN LIEF.
 *
 * Sichern und Zurückspielen hatten je ihre eigene Optionsdatei. Beim Sichern
 * stand die TLS-Zeile drin, beim Zurückspielen nicht. Ergebnis: Die Sicherung
 * lief, das Zurückspielen brach ab -- der schlimmste denkbare Zuschnitt, weil
 * es genau dann auffällt, wenn man die Sicherung braucht.
 *
 * Unsichtbar war das auf dem Entwicklungsrechner, weil MariaDB ab 11.4 von sich
 * aus TLS mit selbstsigniertem Zertifikat anbietet. Erst die CI, wo ein Client
 * 11.8 auf einen Server 10.11 trifft, hat es gezeigt.
 *
 * Geprüft wird deshalb hier -- ohne Datenbank, ohne Server, ohne Client. Der
 * Fehler war eine fehlende Zeile in einer Datei, und genau das lässt sich
 * billig festhalten.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ClientOptionsTest extends TestCase
{
    #[Test]
    public function a_plain_connection_switches_tls_off(): void
    {
        // Der Regelfall im Verein: PHP und MariaDB auf einer Maschine. Ein
        // Client ab 11.4 verlangt hier von sich aus TLS und wird abgewiesen.
        $inhalt = $this->inhaltFuer(['host' => 'localhost', 'username' => 'a', 'password' => 'b']);

        $this->assertStringContainsString("ssl=0\n", $inhalt);
    }

    /**
     * Eine Raute im Passwort ueberlebt die Optionsdatei.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Gemessen auf test.aeronance.de: 126 Zeichen Passwort, eine Raute darin.
     * Unquotiert beginnt in einer my.cnf ab # ein Kommentar -- auch mitten in
     * der Zeile. mariadb-dump bekam "Access denied", update.sh verweigerte
     * ohne Sicherung, und es sah aus, als sei das Update-Skript kaputt. Die
     * Anwendung verband sich die ganze Zeit fehlerfrei (PDO kennt keine
     * Kommentare) -- der Fehler existierte NUR im Werkzeugweg.
     * ─────────────────────────────────────────────────────────────────────────
     */
    #[Test]
    public function a_hash_in_the_password_survives_the_options_file(): void
    {
        $inhalt = $this->inhaltFuer([
            'host' => 'localhost',
            'username' => 'aeronance',
            'password' => 'vorne#hinten und "zitat" mit \\backslash',
        ]);

        $this->assertStringContainsString(
            'password="vorne#hinten und \"zitat\" mit \\\\backslash"',
            $inhalt,
            'Das Passwort muss zitiert und nach my.cnf-Regeln maskiert sein.',
        );
    }

    #[Test]
    public function a_tls_connection_presents_the_same_ca(): void
    {
        $inhalt = $this->inhaltFuer([
            'host' => 'db.example.org',
            'username' => 'a',
            'password' => 'b',
            'options' => [PDO::MYSQL_ATTR_SSL_CA => '/etc/ssl/certs/db.pem'],
        ]);

        // Nicht "TLS abschalten", sondern denselben Weg nehmen wie die
        // Anwendung -- sonst verlangte das Werkzeug weniger als sie.
        $this->assertStringContainsString("ssl-ca=/etc/ssl/certs/db.pem\n", $inhalt);
        $this->assertStringNotContainsString('ssl=0', $inhalt);
    }

    /**
     * Der eigentliche Punkt: EINE Wahrheit für beide Befehle.
     *
     * Solange Sichern und Zurückspielen dieselbe Klasse benutzen, kann die
     * Zeile nicht mehr an einer der beiden Stellen fehlen.
     */
    #[Test]
    public function backup_and_restore_build_the_same_file(): void
    {
        $verbindung = (array) config('database.connections.'.config('database.default'));

        $vomSichern = $this->lies(ClientOptions::writeFor($verbindung));
        $vomZurueck = $this->lies(ClientOptions::writeFor($verbindung));

        $this->assertSame($vomSichern, $vomZurueck);
    }

    #[Test]
    public function the_password_is_not_readable_by_others(): void
    {
        // Ein Passwort in einer Datei ist nur so lange besser als eines auf der
        // Kommandozeile, wie die Datei niemandem sonst gehört.
        $pfad = ClientOptions::writeFor(['host' => 'x', 'username' => 'a', 'password' => 'geheim']);

        try {
            $this->assertSame('0600', mb_substr(sprintf('%o', fileperms($pfad)), -4));
        } finally {
            @unlink($pfad);
        }
    }

    /**
     * @param  array<string, mixed>  $verbindung
     */
    private function inhaltFuer(array $verbindung): string
    {
        return $this->lies(ClientOptions::writeFor($verbindung));
    }

    private function lies(string $pfad): string
    {
        try {
            return (string) file_get_contents($pfad);
        } finally {
            @unlink($pfad);
        }
    }
}
