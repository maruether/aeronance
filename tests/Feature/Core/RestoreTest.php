<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Modules\Fleet\Models\AircraftType;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Der getestete Restore, den CLAUDE.md verlangt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * "Automatisierte Backups (DB + Dokumente) mit GETESTETEM Restore." Den
 * Sicherungsbefehl gab es lange, den Rückweg nicht -- und eine Sicherung, aus
 * der noch nie jemand zurückgekommen ist, ist ein Gefühl und kein Backup.
 *
 * KEIN RefreshDatabase HIER, und das ist der Kern der Sache.
 *
 * Dieser Test ruft einen EXTERNEN Prozess auf, der die Tabellen neu anlegt.
 * RefreshDatabase hält jeden Test in einer Transaktion; der fremde Prozess
 * wartet dann ewig auf die Metadaten-Sperre. Der erste Entwurf lief in den
 * Timeout -- und ein Test, der hängt statt zu scheitern, ist das Schlimmste von
 * beidem.
 *
 * Deshalb hier migrate:fresh statt einer Transaktion. Langsamer, aber es ist
 * der einzige Weg, den Restore so zu prüfen, wie er im Ernstfall läuft: gegen
 * eine Datenbank, die niemand festhält.
 *
 * (Eine Wegwerf-Datenbank je Test wäre der sauberere Schnitt gewesen und
 * scheitert an den Rechten: der Anwendungsbenutzer darf keine Datenbank
 * anlegen -- richtigerweise.)
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class RestoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ohne Transaktion, siehe Klassenkommentar.
        $this->artisan('migrate:fresh', ['--force' => true])->run();
    }

    /**
     * Hinter sich aufräumen -- und zwar zwingend.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Ohne Transaktion bleibt alles stehen, was dieser Test einspielt. Beim
     * ersten Versuch kippte deshalb die halbe Suite hinterher um ("Duplicate
     * entry 'ASK 21'"), weil die folgenden Tests eine leere Datenbank erwarten
     * und RefreshDatabase nur eine Transaktion aufmacht, statt aufzuräumen.
     *
     * Ein Test, der andere Tests umbringt, ist schlimmer als kein Test: Der
     * Fehler taucht woanders auf, und dort sucht ihn niemand.
     * ─────────────────────────────────────────────────────────────────────────
     */
    protected function tearDown(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->run();

        parent::tearDown();
    }

    #[Test]
    public function a_backup_can_actually_be_restored(): void
    {
        AircraftType::create([
            'designation' => 'ASK 21',
            'manufacturer' => 'Alexander Schleicher',
            'type_certificate' => 'EASA.A.221',
        ]);

        $verzeichnis = $this->sichern();
        $wieder = $this->stelleWiederHer($this->neuesteDatei($verzeichnis));

        $this->assertSame(1, (int) $wieder->table('aircraft_types')->count());
        $this->assertSame('EASA.A.221', $wieder->table('aircraft_types')->value('type_certificate'));

        $this->aufraeumen($verzeichnis);
    }

    #[Test]
    public function an_encrypted_backup_can_be_restored_with_its_passphrase(): void
    {
        $this->mitPasswort();

        AircraftType::create(['designation' => 'DG-300', 'type_certificate' => 'EASA.A.106']);

        $verzeichnis = $this->sichern();
        $dump = $this->neuesteDatei($verzeichnis);

        $this->assertStringEndsWith('.enc', $dump);

        $wieder = $this->stelleWiederHer($dump, ['--passphrase' => 'ein sehr langes Passwort']);

        $this->assertSame('EASA.A.106', $wieder->table('aircraft_types')->value('type_certificate'));

        $this->aufraeumen($verzeichnis);
    }

    #[Test]
    public function a_restore_without_the_passphrase_says_so_instead_of_failing_obscurely(): void
    {
        /*
         * Der Moment, in dem jemand einen Restore fährt, ist der schlechteste
         * für ein Rätsel. Der Befehl sieht der Datei an, was ihr fehlt.
         */
        $this->mitPasswort();

        $verzeichnis = $this->sichern();

        $this->artisan('aeronance:restore', ['dump' => $this->neuesteDatei($verzeichnis)])
            ->expectsOutputToContain('Passwort')
            ->assertFailed();

        $this->aufraeumen($verzeichnis);
    }

    #[Test]
    public function no_decrypted_copy_is_left_lying_around(): void
    {
        /*
         * Bliebe die entschlüsselte Zwischendatei liegen, hätte ein Restore
         * genau das im Temp-Verzeichnis hinterlassen, wovor die Verschlüsselung
         * schützen soll.
         */
        $this->mitPasswort();

        AircraftType::create(['designation' => 'ASK 21', 'type_certificate' => 'EASA.A.221']);

        $verzeichnis = $this->sichern();
        $vorher = glob(sys_get_temp_dir().'/aeronance-restore-*') ?: [];

        $this->stelleWiederHer(
            $this->neuesteDatei($verzeichnis),
            ['--passphrase' => 'ein sehr langes Passwort'],
        );

        $this->assertSame($vorher, glob(sys_get_temp_dir().'/aeronance-restore-*') ?: []);

        $this->aufraeumen($verzeichnis);
    }

    // ── Hilfsmittel ─────────────────────────────────────────────────────────

    private function mitPasswort(): void
    {
        config()->set('aeronance.backup.encryption.mode', 'passphrase');
        config()->set('aeronance.backup.encryption.passphrase', 'ein sehr langes Passwort');
    }

    private function sichern(): string
    {
        $verzeichnis = storage_path('app/restore-test-'.bin2hex(random_bytes(4)));

        $this->artisan('aeronance:backup', ['--path' => $verzeichnis, '--database-only' => true])
            ->assertSuccessful();

        return $verzeichnis;
    }

    /**
     * Wirft die Daten weg und holt sie aus der Sicherung zurück.
     *
     * Das Wegwerfen ist der Punkt: Ohne es prüfte der Test nur, ob ein Datensatz
     * überlebt, den niemand angefasst hat.
     *
     * @param  array<string, string>  $optionen
     */
    private function stelleWiederHer(string $dump, array $optionen = []): Connection
    {
        DB::table('aircraft_types')->delete();
        $this->assertSame(0, (int) DB::table('aircraft_types')->count(), 'Vorher muss wirklich leer sein.');

        /*
         * ─────────────────────────────────────────────────────────────────────
         * DIE BEGRUENDUNG GEHOERT IN DIE FEHLERMELDUNG.
         *
         * Vorher stand hier ->assertSuccessful(), und das meldete genau
         * "Expected status code 0 but received 1" -- den Grund schluckte es.
         * Der Befehl faengt seine Ausnahmen ab und schreibt sie in die Ausgabe;
         * die war damit unerreichbar.
         *
         * Gekostet hat das Wochen: In der CI ist dieser Test rot, seit es ihn
         * gibt, und die Meldung liess nicht erkennen, dass der Client TLS
         * verlangte. Ein Test, der "ging nicht" sagt und nicht "warum", ist
         * kaum besser als keiner.
         * ─────────────────────────────────────────────────────────────────────
         */
        $code = $this->artisan('aeronance:restore', ['dump' => $dump] + $optionen)->run();

        $this->assertSame(0, $code, 'Das Zurückspielen schlug fehl: '.Artisan::output());

        DB::purge();

        return DB::connection();
    }

    private function neuesteDatei(string $verzeichnis): string
    {
        $dateien = glob($verzeichnis.'/*') ?: [];
        usort($dateien, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $dateien[0];
    }

    private function aufraeumen(string $verzeichnis): void
    {
        array_map('unlink', glob($verzeichnis.'/*') ?: []);
        @rmdir($verzeichnis);
    }
}
