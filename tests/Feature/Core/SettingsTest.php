<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Settings\Settings;
use App\Core\Settings\SettingsCatalogue;
use App\Core\Setup\SetupWizard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Die Einstellungen -- und die Rangfolge, die das ganze Konzept trägt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: "Ziel muss es sein die Konsole nur für das Starten des fertig
 * runtergeladenen Dockers und für den Break-glass zu benötigen. Wir können den
 * Usern nicht zumuten alles mögliche in config files zu schreiben."
 *
 * Und zur Rangfolge: "db gewinnt, env nur initial."
 *
 * Praktisch heisst das: Solange in der Tabelle nichts steht, gilt die
 * Umgebungsvariable -- eine docker-compose.yml wirkt also. Sobald der Wert
 * EINMAL gesetzt wurde, gilt die Tabelle, und die Umgebung wird für diesen
 * Schlüssel nie wieder gelesen.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SettingsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function without_a_stored_value_the_environment_applies(): void
    {
        /*
         * Ueber Laravels Env-Repository und nicht ueber putenv(): env() liest
         * aus dem Repository, das beim Start aus der .env gefuellt wurde --
         * putenv() geht daran vorbei, und der Test pruefte dann die .env
         * dieses Rechners statt die eigene Vorgabe.
         */
        Env::getRepository()->set('ORGANISATION_NAME', 'Akaflieg Freiburg');

        $this->assertSame('Akaflieg Freiburg', $this->settings()->get('organisation.name'));
        $this->assertSame('umgebung', $this->settings()->sourceOf('organisation.name'));

        Env::getRepository()->clear('ORGANISATION_NAME');
    }

    #[Test]
    public function a_stored_value_beats_the_environment_from_then_on(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * DER KERN DER ENTSCHEIDUNG. Wer den Wert einmal in der Oberfläche
         * gesetzt hat, soll ihn dort auch wiederfinden -- und nicht beim
         * nächsten Container-Start von einer alten compose-Datei überschrieben
         * bekommen.
         * ─────────────────────────────────────────────────────────────────────
         */
        Env::getRepository()->set('ORGANISATION_NAME', 'Aus der Umgebung');

        $this->settings()->set('organisation.name', 'Von Hand gesetzt');

        $this->assertSame('Von Hand gesetzt', $this->settings()->get('organisation.name'));
        $this->assertSame('datenbank', $this->settings()->sourceOf('organisation.name'));

        Env::getRepository()->clear('ORGANISATION_NAME');
    }

    #[Test]
    public function forgetting_a_value_falls_back_again(): void
    {
        Env::getRepository()->set('ORGANISATION_NAME', 'Aus der Umgebung');

        $this->settings()->set('organisation.name', 'Von Hand');
        $this->settings()->forget('organisation.name');

        $this->assertSame('Aus der Umgebung', $this->settings()->get('organisation.name'));

        Env::getRepository()->clear('ORGANISATION_NAME');
    }

    #[Test]
    public function without_either_the_default_applies(): void
    {
        $this->assertSame(28, $this->settings()->get('retention.pseudonymise_former_members.days'));
        $this->assertSame('vorgabe', $this->settings()->sourceOf('retention.pseudonymise_former_members.days'));
    }

    #[Test]
    public function the_retention_rules_are_reachable_without_touching_a_file(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * DER SCHLIMMSTE FALL DER ALTEN LAGE, festgehalten.
         *
         * Die Aufbewahrungsfristen standen nicht einmal in der .env, sondern in
         * config/aeronance.php -- einer Datei, die im Docker-Kanal IM IMAGE
         * liegt und bei jedem Update verlorengeht. Retention war dort faktisch
         * nicht einschaltbar, und das ist niemandem aufgefallen, weil der
         * Schalter ja existierte.
         * ─────────────────────────────────────────────────────────────────────
         */
        $this->settings()->set('retention.activity_log.enabled', true);
        $this->settings()->set('retention.activity_log.days', 1095);
        $this->settings()->applyToConfig();

        $this->assertTrue(config('aeronance.retention.activity_log.enabled'));
        $this->assertSame(1095, config('aeronance.retention.activity_log.days'));
    }

    #[Test]
    public function a_setting_reaches_the_configuration_it_belongs_to(): void
    {
        // Auch die Disks: Ohne das müsste ein Verein für den SFTP-Zugang doch
        // wieder eine Datei anfassen.
        $this->settings()->set('backup.sftp.host', 'u123456.your-storagebox.de');
        $this->settings()->set('backup.offsite.disk', 'offsite_sftp');
        $this->settings()->applyToConfig();

        $this->assertSame('u123456.your-storagebox.de', config('filesystems.disks.offsite_sftp.host'));
        $this->assertSame('offsite_sftp', config('aeronance.backup.offsite.disk'));
    }

    /**
     * ─────────────────────────────────────────────────────────────────────────
     * LESBAR, BEVOR ELOQUENT EINE VERBINDUNG HAT.
     *
     * Der Feldtest sagte nur: „der namen in der Kopfzeile aktualisiert sich
     * nicht bei änderung in den einstellungen". Dahinter steckte das hier: Die
     * Einstellungen werden im register() der Provider gebraucht -- Filament
     * baut dort sein Panel -- und in dieser Phase ist Eloquent noch nicht mit
     * der Datenbank verbunden. Jeder Model-Zugriff endete in „Call to a member
     * function connection() on null", wurde gefangen, und die Einstellungen
     * galten still nicht.
     *
     * Dieser Test stellt genau diesen Zustand her. Er ist der Grund, warum
     * unten der Query Builder steht und nicht das Model -- ohne ihn baut das
     * jemand in einem Jahr aus Ordnungsliebe zurück.
     * ─────────────────────────────────────────────────────────────────────────
     */
    #[Test]
    public function the_values_are_readable_before_eloquent_is_connected(): void
    {
        $this->settings()->set('organisation.name', 'Akaflieg Freiburg');

        $resolver = Model::getConnectionResolver();
        Model::unsetConnectionResolver();

        try {
            $frisch = new Settings;

            $this->assertSame('Akaflieg Freiburg', $frisch->get('organisation.name'));

            $frisch->applyToConfig();
            $this->assertSame('Akaflieg Freiburg', config('aeronance.organisation.name'));
        } finally {
            Model::setConnectionResolver($resolver);
        }
    }

    /**
     * ─────────────────────────────────────────────────────────────────────────
     * WENN DIE DATENBANK WEG IST: einmal versuchen, einmal melden, weiterlaufen.
     *
     * Drei Anforderungen in einem Test, weil sie zusammengehören:
     *
     *  - Die Anwendung stirbt nicht, sondern fällt auf Umgebung und Vorgabe
     *    zurück. Sonst wäre eine frische Installation unbedienbar, bevor sie
     *    eingerichtet ist.
     *  - Es steht im Protokoll. „Keine Einstellungen lesbar" ist nie normal,
     *    und vorher stand darüber nirgends etwas.
     *  - Es wird EINMAL versucht, nicht 44-mal. applyToConfig fragt jeden
     *    Katalogschlüssel einzeln ab; ohne dieses Merken kostet ein
     *    unerreichbarer Datenbankhost 44 Verbindungsversuche nacheinander --
     *    also genau in dem Zustand, für den der Rückfall gebaut ist.
     * ─────────────────────────────────────────────────────────────────────────
     */
    #[Test]
    public function an_unreachable_database_is_tried_once_reported_once_and_survived(): void
    {
        Log::spy();

        /*
         * DIE UMGEBUNG DIESER MASCHINE DARF NICHT MITENTSCHEIDEN.
         *
         * Die Rangfolge ist Datenbank → Umgebung → Vorgabe. Faellt das Lesen
         * aus, greift also zuerst die Umgebung -- und die .env.example, gegen
         * die die CI laeuft, setzt ORGANISATION_NAME. Ohne dieses Loeschen
         * prueft der Test, was auf dem ausfuehrenden Rechner in der .env steht:
         * lokal gruen, in der CI rot. Genau davor warnt der Kopf dieser Datei
         * schon einmal, und ich bin trotzdem hineingelaufen.
         */
        Env::getRepository()->clear('ORGANISATION_NAME');

        $frisch = new Settings;
        $versuche = 0;

        /*
         * Am ERSTEN Anfassen der Datenbank gemessen, nicht am Query Builder:
         * Ist der Host unerreichbar, scheitert schon die Frage, ob es die
         * Tabelle gibt -- und genau die kostet den Verbindungsversuch.
         */
        Schema::shouldReceive('hasTable')->andReturnUsing(function () use (&$versuche): never {
            $versuche++;

            throw new RuntimeException('Verbindung weg');
        });

        // Mehrere Abfragen, wie sie applyToConfig nacheinander stellt.
        $this->assertSame('Aeronance', $frisch->get('organisation.name'));
        $this->assertSame(28, $frisch->get('retention.pseudonymise_former_members.days'));
        $this->assertSame('Aeronance', $frisch->get('organisation.name'));

        $this->assertSame(1, $versuche, 'Die Datenbank darf nur einmal je Anfrage befragt werden.');

        Log::shouldHaveReceived('warning')->once();
    }

    #[Test]
    public function secrets_are_encrypted_at_rest(): void
    {
        /*
         * Unter diesen Schlüsseln stehen das Backup-Passwort und der private
         * SFTP-Schlüssel. Lägen sie im Klartext, wäre die Tabelle ein
         * Schlüsselbund -- und sie liegt in jeder Sicherung.
         */
        $this->settings()->set('backup.encryption.passphrase', 'ein sehr langes Passwort');

        $roh = (string) DB::table('settings')->where('key', 'backup.encryption.passphrase')->value('value');

        $this->assertStringNotContainsString('ein sehr langes Passwort', $roh);
        $this->assertSame('ein sehr langes Passwort', $this->settings()->get('backup.encryption.passphrase'));
        $this->assertTrue((bool) DB::table('settings')->where('key', 'backup.encryption.passphrase')->value('is_secret'));
    }

    #[Test]
    public function the_value_never_reaches_the_audit_log(): void
    {
        $this->settings()->set('backup.encryption.passphrase', 'ein sehr langes Passwort');

        $protokoll = DB::table('activity_log')->get()->toJson();

        $this->assertStringNotContainsString('ein sehr langes Passwort', $protokoll);
    }

    #[Test]
    public function every_catalogued_setting_points_at_a_real_configuration_path(): void
    {
        /*
         * Ein Tippfehler im Konfigurationspfad wäre sonst unsichtbar: Der Wert
         * würde gespeichert, in der Oberfläche erscheinen -- und nirgends
         * wirken. Genau die stille Sorte, die dieses Konzept abschaffen soll.
         */
        /*
         * ─────────────────────────────────────────────────────────────────────
         * DER GANZE PFAD, nicht nur sein erstes Glied -- und das ist die
         * Korrektur an diesem Test selbst.
         *
         * Vorher stand hier `explode('.', $pfad)[0]`, geprüft wurde also nur,
         * ob es eine Datei config/aeronance.php gibt. Vier Einstellungen zeigten
         * jahrelang auf „aeronance.clamav.*", gelesen wurde „aeronance.
         * documents.clamav.*" -- der Test war grün, und der Virenscanner ließ
         * sich einschalten, ohne dass er ansprang.
         *
         * has() statt Vergleich mit null: Ein Pfad darf ausdrücklich null sein
         * (clamav.host ist es ab Werk). „Nicht gesetzt" und „nicht vorhanden"
         * sind zwei verschiedene Dinge, und nur das zweite ist der Tippfehler,
         * den dieser Test sucht.
         * ─────────────────────────────────────────────────────────────────────
         */
        $unbekannt = [];

        foreach (SettingsCatalogue::all() as $definition) {
            if (! config()->has($definition->configPath)) {
                $unbekannt[] = $definition->configPath;
            }
        }

        $this->assertSame([], $unbekannt);
    }

    #[Test]
    public function a_boolean_survives_the_round_trip(): void
    {
        // Aus der Tabelle kommt Text. "0" ist in PHP wahr genug, um Ärger zu
        // machen -- ein abgeschalteter Schalter, der als eingeschaltet gilt,
        // löscht hier Daten.
        $this->settings()->set('retention.activity_log.enabled', false);

        $this->assertFalse($this->settings()->get('retention.activity_log.enabled'));
    }

    #[Test]
    public function an_unknown_key_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/Unbekannte Einstellung/');

        $this->settings()->set('gibt.es.nicht', 'egal');
    }

    #[Test]
    public function the_wizard_writes_the_organisation_into_the_settings_and_not_into_a_file(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * CLAUDE.md schreibt dem Assistenten seit jeher eine "Basiskonfiguration
         * (Vereinsname, Logo)" zu -- gebaut waren nur Datenbank, Migration,
         * Administrator und Module. Der Name kam aus ORGANISATION_NAME in der .env, also
         * genau aus der Datei, die niemand anfassen sollte.
         * ─────────────────────────────────────────────────────────────────────
         */
        app(SetupWizard::class)->configureOrganisation('Akaflieg Freiburg', 'Europe/Berlin');

        $this->assertSame('Akaflieg Freiburg', (new Settings)->get('organisation.name'));
        $this->assertSame('Akaflieg Freiburg', config('aeronance.organisation.name'));
        $this->assertSame('datenbank', (new Settings)->sourceOf('organisation.name'));
    }

    private function settings(): Settings
    {
        // Frisch, damit der Zwischenspeicher zwischen den Schritten nicht lügt.
        return new Settings;
    }
}
