<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Backup\ArchiveCipher;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Verschlüsselte Sicherungen -- beide Wege, und was scheitern muss.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Bei Verschlüsselung ist der Rundlauf der leichte Teil. Was zählt, sind die
 * Fälle, in denen etwas NICHT aufgehen darf: falsches Passwort, fremder
 * Schlüssel, veränderte Datei, abgeschnittene Datei, vertauschte Blöcke. Jeder
 * davon hat hier seine eigene Zusicherung, weil selbstgebautes Rahmenwerk genau
 * dort schiefgeht und nicht beim Entschlüsseln des eigenen Ergebnisses.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ArchiveCipherTest extends TestCase
{
    private string $verzeichnis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verzeichnis = sys_get_temp_dir().'/aeronance-cipher-'.bin2hex(random_bytes(6));
        mkdir($this->verzeichnis, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->verzeichnis.'/*') ?: [] as $datei) {
            @unlink($datei);
        }

        @rmdir($this->verzeichnis);

        parent::tearDown();
    }

    #[Test]
    public function a_backup_survives_the_round_trip_with_a_passphrase(): void
    {
        $inhalt = $this->grosseDatei();
        $klar = $this->schreibe('dump.sql', $inhalt);

        $cipher = new ArchiveCipher;
        $cipher->encryptWithPassphrase($klar, $this->pfad('dump.enc'), 'ein sehr langes Passwort');

        $this->assertTrue($cipher->isEncrypted($this->pfad('dump.enc')));
        $this->assertSame(ArchiveCipher::MODE_PASSPHRASE, $cipher->modeOf($this->pfad('dump.enc')));

        $cipher->decryptWithPassphrase(
            $this->pfad('dump.enc'),
            $this->pfad('zurueck.sql'),
            'ein sehr langes Passwort',
        );

        $this->assertSame($inhalt, file_get_contents($this->pfad('zurueck.sql')));
    }

    #[Test]
    public function a_backup_survives_the_round_trip_with_a_key(): void
    {
        [$privat, $oeffentlich] = $this->schluesselpaar();

        $inhalt = $this->grosseDatei();
        $klar = $this->schreibe('dump.sql', $inhalt);

        $cipher = new ArchiveCipher;
        $cipher->encryptForRecipient($klar, $this->pfad('dump.enc'), $oeffentlich);

        $this->assertSame(ArchiveCipher::MODE_RECIPIENT, $cipher->modeOf($this->pfad('dump.enc')));

        $cipher->decryptWithKey($this->pfad('dump.enc'), $this->pfad('zurueck.sql'), $privat);

        $this->assertSame($inhalt, file_get_contents($this->pfad('zurueck.sql')));
    }

    #[Test]
    public function the_plaintext_is_nowhere_in_the_encrypted_file(): void
    {
        /*
         * Klingt selbstverständlich und ist die Zusicherung, die einen falsch
         * verdrahteten Datenstrom fängt -- etwa wenn ein Block versehentlich
         * unverschlüsselt durchgereicht wird.
         */
        $klar = $this->schreibe('dump.sql', str_repeat('GEHEIMER-INHALT-DER-DATENBANK ', 5000));

        (new ArchiveCipher)->encryptWithPassphrase($klar, $this->pfad('dump.enc'), 'ein sehr langes Passwort');

        $this->assertStringNotContainsString(
            'GEHEIMER-INHALT',
            (string) file_get_contents($this->pfad('dump.enc')),
        );
    }

    #[Test]
    public function a_wrong_passphrase_is_refused(): void
    {
        $klar = $this->schreibe('dump.sql', 'Inhalt');
        $cipher = new ArchiveCipher;
        $cipher->encryptWithPassphrase($klar, $this->pfad('dump.enc'), 'das richtige Passwort');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/passt nicht|beschädigt/');

        $cipher->decryptWithPassphrase($this->pfad('dump.enc'), $this->pfad('x.sql'), 'das falsche Passwort');
    }

    #[Test]
    public function a_foreign_key_cannot_open_it(): void
    {
        [, $oeffentlich] = $this->schluesselpaar();
        [$fremderPrivat] = $this->schluesselpaar();

        $klar = $this->schreibe('dump.sql', 'Inhalt');
        $cipher = new ArchiveCipher;
        $cipher->encryptForRecipient($klar, $this->pfad('dump.enc'), $oeffentlich);

        $this->expectException(RuntimeException::class);

        $cipher->decryptWithKey($this->pfad('dump.enc'), $this->pfad('x.sql'), $fremderPrivat);
    }

    #[Test]
    public function a_truncated_backup_is_refused_rather_than_half_restored(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * DER GEFÄHRLICHSTE FALL. Ohne die Schlussmarkierung liesse sich eine
         * Sicherung hinten abschneiden, und was übrig bleibt, entschlüsselt
         * sauber: eine halbe Datenbank, die wie eine ganze aussieht. Niemand
         * merkt es, bis jemand einen Datensatz sucht, den es nicht mehr gibt.
         * ─────────────────────────────────────────────────────────────────────
         */
        $klar = $this->schreibe('dump.sql', $this->grosseDatei());
        $cipher = new ArchiveCipher;
        $cipher->encryptWithPassphrase($klar, $this->pfad('dump.enc'), 'ein sehr langes Passwort');

        $roh = (string) file_get_contents($this->pfad('dump.enc'));
        file_put_contents($this->pfad('halb.enc'), substr($roh, 0, (int) (strlen($roh) * 0.6)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/endet vorzeitig|Schlussblock|beschädigt/');

        $cipher->decryptWithPassphrase($this->pfad('halb.enc'), $this->pfad('x.sql'), 'ein sehr langes Passwort');
    }

    #[Test]
    public function a_changed_byte_is_noticed(): void
    {
        $klar = $this->schreibe('dump.sql', $this->grosseDatei());
        $cipher = new ArchiveCipher;
        $cipher->encryptWithPassphrase($klar, $this->pfad('dump.enc'), 'ein sehr langes Passwort');

        $roh = (string) file_get_contents($this->pfad('dump.enc'));
        $mitte = (int) (strlen($roh) / 2);
        $roh[$mitte] = $roh[$mitte] === 'A' ? 'B' : 'A';
        file_put_contents($this->pfad('kaputt.enc'), $roh);

        $this->expectException(RuntimeException::class);

        $cipher->decryptWithPassphrase($this->pfad('kaputt.enc'), $this->pfad('x.sql'), 'ein sehr langes Passwort');
    }

    #[Test]
    public function the_header_cannot_be_rewritten(): void
    {
        /*
         * Der Kopf sagt, welches Verfahren gilt. Liesse er sich ändern, könnte
         * jemand eine an einen Schlüssel gerichtete Sicherung als
         * passwortgeschützte ausgeben und das Passwort selbst wählen. Er geht
         * deshalb als Hash in jeden Block ein.
         */
        $klar = $this->schreibe('dump.sql', 'Inhalt');
        $cipher = new ArchiveCipher;
        $cipher->encryptWithPassphrase($klar, $this->pfad('dump.enc'), 'ein sehr langes Passwort');

        $roh = (string) file_get_contents($this->pfad('dump.enc'));
        $verbogen = str_replace('"iterations":210000', '"iterations":210001', $roh);

        $this->assertNotSame($roh, $verbogen, 'Der Kopf muss auffindbar sein, sonst prüft dieser Test nichts.');

        file_put_contents($this->pfad('verbogen.enc'), $verbogen);

        $this->expectException(RuntimeException::class);

        $cipher->decryptWithPassphrase($this->pfad('verbogen.enc'), $this->pfad('x.sql'), 'ein sehr langes Passwort');
    }

    #[Test]
    public function a_short_passphrase_is_refused(): void
    {
        $klar = $this->schreibe('dump.sql', 'Inhalt');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/12 Zeichen/');

        (new ArchiveCipher)->encryptWithPassphrase($klar, $this->pfad('dump.enc'), 'kurz');
    }

    #[Test]
    public function the_wrong_method_is_named_rather_than_guessed(): void
    {
        [$privat] = $this->schluesselpaar();

        $klar = $this->schreibe('dump.sql', 'Inhalt');
        $cipher = new ArchiveCipher;
        $cipher->encryptWithPassphrase($klar, $this->pfad('dump.enc'), 'ein sehr langes Passwort');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Passwort verschlüsselt/');

        $cipher->decryptWithKey($this->pfad('dump.enc'), $this->pfad('x.sql'), $privat);
    }

    #[Test]
    public function a_foreign_file_is_not_mistaken_for_a_backup(): void
    {
        file_put_contents($this->pfad('fremd.bin'), 'irgendwas');

        $cipher = new ArchiveCipher;

        $this->assertFalse($cipher->isEncrypted($this->pfad('fremd.bin')));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/keine verschlüsselte Aeronance-Sicherung/');

        $cipher->decryptWithPassphrase($this->pfad('fremd.bin'), $this->pfad('x'), 'ein sehr langes Passwort');
    }

    // ── Hilfsmittel ─────────────────────────────────────────────────────────

    /** Mehr als ein Block, damit die Blocklogik überhaupt geprüft wird. */
    private function grosseDatei(): string
    {
        return str_repeat('Zeile mit Inhalt einer Vereinsdatenbank. ', 80_000);
    }

    private function schreibe(string $name, string $inhalt): string
    {
        file_put_contents($pfad = $this->pfad($name), $inhalt);

        return $pfad;
    }

    private function pfad(string $name): string
    {
        return $this->verzeichnis.'/'.$name;
    }

    /** @return array{0: string, 1: string} privat, öffentlich */
    private function schluesselpaar(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($key, $privat);

        return [$privat, (string) openssl_pkey_get_details($key)['key']];
    }
}
