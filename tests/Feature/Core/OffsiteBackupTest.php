<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Backup\ArchiveCipher;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Die Auslagerung -- und die Regel, die darüber steht.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: "ein backup ohne offsite storage ist nur halb soviel wert." Und, im
 * selben Atemzug: "kein export der daten ohne verschlüsselung."
 *
 * Beides zusammen ergibt die Bedingung, die dieser Test festhält: Ausgelagert
 * wird gern -- aber nur verschlüsselt, und nur, wenn die Kopie am Ziel
 * vollständig angekommen ist.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class OffsiteBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $verzeichnis;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('offsite');
        config()->set('aeronance.backup.offsite.disk', 'offsite');

        $this->verzeichnis = storage_path('app/offsite-test-'.bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->verzeichnis.'/*') ?: []);
        @rmdir($this->verzeichnis);

        parent::tearDown();
    }

    #[Test]
    public function an_unencrypted_backup_is_never_shipped_off_site(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * DIE REGEL, UND SIE IST EIN HARTER FEHLER.
         *
         * Solange eine Sicherung auf dem eigenen Server liegt, teilt sie dessen
         * Schutz. In dem Moment, in dem sie ihn verlässt, tut sie das nicht mehr
         * -- sie liegt beim Anbieter, auf dessen Platten, in dessen Sicherungen.
         *
         * Eine Warnung im Protokoll eines nächtlichen Laufs liest niemand, und
         * der Klartext wäre trotzdem unterwegs. Also lieber keine Auslagerung
         * als eine unverschlüsselte.
         * ─────────────────────────────────────────────────────────────────────
         */
        config()->set('aeronance.backup.encryption.mode', 'none');

        $this->artisan('aeronance:backup', [
            '--path' => $this->verzeichnis,
            '--database-only' => true,
        ])->assertFailed();

        $this->assertSame([], Storage::disk('offsite')->allFiles(), 'Es darf nichts hochgeladen sein.');
    }

    #[Test]
    public function an_encrypted_backup_arrives_at_the_second_place(): void
    {
        $this->mitPasswort();

        $this->artisan('aeronance:backup', [
            '--path' => $this->verzeichnis,
            '--database-only' => true,
        ])->assertSuccessful();

        $draussen = Storage::disk('offsite')->allFiles();

        $this->assertCount(1, $draussen);
        $this->assertStringEndsWith('.enc', $draussen[0]);
    }

    #[Test]
    public function what_arrives_is_still_encrypted(): void
    {
        /*
         * Klingt selbstverständlich, prüft aber die eine Verwechslung, die den
         * ganzen Zweck aushebelte: dass versehentlich die Fassung VOR der
         * Verschlüsselung hochgeladen wird.
         */
        $this->mitPasswort();

        $this->artisan('aeronance:backup', [
            '--path' => $this->verzeichnis,
            '--database-only' => true,
        ])->assertSuccessful();

        $pfad = Storage::disk('offsite')->allFiles()[0];
        $inhalt = (string) Storage::disk('offsite')->get($pfad);

        $this->assertStringStartsWith('AERONANCE-BACKUP', $inhalt);

        // Und die Datenbankstruktur darf darin nicht zu erkennen sein.
        $this->assertStringNotContainsString('CREATE TABLE', $inhalt);
        $this->assertStringNotContainsString('aircraft_types', $inhalt);
    }

    #[Test]
    public function the_copy_is_checked_and_not_merely_sent(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * Ein abgebrochener Upload hinterlässt eine Datei, die es GIBT -- nur zu
         * kurz. Sie steht im Verzeichnis, hat Namen und Datum, und niemand merkt
         * etwas, bis jemand sie braucht.
         * ─────────────────────────────────────────────────────────────────────
         */
        $this->mitPasswort();

        $this->artisan('aeronance:backup', [
            '--path' => $this->verzeichnis,
            '--database-only' => true,
        ])->assertSuccessful();

        $pfad = Storage::disk('offsite')->allFiles()[0];
        $lokal = glob($this->verzeichnis.'/*')[0];

        $this->assertSame(
            (int) filesize($lokal),
            (int) Storage::disk('offsite')->size($pfad),
            'Die Kopie am Ziel muss so gross sein wie das Original.',
        );

        $this->assertTrue((new ArchiveCipher)->isEncrypted($lokal));
    }

    #[Test]
    public function a_prefix_keeps_two_clubs_apart_on_one_storage(): void
    {
        $this->mitPasswort();
        config()->set('aeronance.backup.offsite.prefix', 'akaflieg-freiburg');

        $this->artisan('aeronance:backup', [
            '--path' => $this->verzeichnis,
            '--database-only' => true,
        ])->assertSuccessful();

        $this->assertStringStartsWith(
            'akaflieg-freiburg/',
            Storage::disk('offsite')->allFiles()[0],
        );
    }

    #[Test]
    public function both_off_site_drivers_can_actually_be_built(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * DASS DER TREIBER DA IST, MERKT MAN SONST NACHTS UM FUENF.
         *
         * Eine Disk in config/filesystems.php ist nur ein Eintrag; ob der
         * passende Flysystem-Adapter ueberhaupt installiert ist, zeigt sich erst
         * beim ersten Zugriff -- also im geplanten Lauf, dessen Fehlermeldung
         * niemand liest.
         *
         * SFTP fuer die Storage Box (league/flysystem-sftp-v3), S3 ueber
         * async-aws statt ueber das AWS-SDK: 2,3 statt 63 MB, gemessen. Beide
         * werden hier nur GEBAUT, nicht verbunden -- eine echte Verbindung
         * waere ein Test des Anbieters, nicht dieses Programms.
         * ─────────────────────────────────────────────────────────────────────
         */
        config()->set('filesystems.disks.probe_sftp', [
            'driver' => 'sftp',
            'host' => 'storage.example.org',
            'username' => 'verein',
            'password' => 'egal',
        ]);

        config()->set('filesystems.disks.probe_s3', [
            'driver' => 'async-s3',
            'key' => 'egal',
            'secret' => 'egal',
            'region' => 'eu-central-1',
            'bucket' => 'aeronance',
            'endpoint' => 'https://s3.example.org',
        ]);

        $this->assertInstanceOf(Filesystem::class, Storage::disk('probe_sftp'));
        $this->assertInstanceOf(Filesystem::class, Storage::disk('probe_s3'));
    }

    private function mitPasswort(): void
    {
        config()->set('aeronance.backup.encryption.mode', 'passphrase');
        config()->set('aeronance.backup.encryption.passphrase', 'ein sehr langes Passwort');
    }
}
