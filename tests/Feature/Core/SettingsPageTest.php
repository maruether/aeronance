<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Access\AccessSetup;
use App\Core\Access\CorePermissions;
use App\Core\Documents\ClamAvScanner;
use App\Core\Documents\VirusScanner;
use App\Core\Filament\Pages\SettingsPage;
use App\Core\Settings\SettingOptions;
use App\Core\Settings\Settings;
use App\Core\Settings\SettingsCatalogue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Die Einstellungsseite -- der Ort, der die Konsole überflüssig macht.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Geprüft wird nicht, dass die Seite hübsch ist, sondern die drei Dinge, an
 * denen sie lügen könnte: wer sie sehen darf, ob ein Geheimnis beim Speichern
 * versehentlich gelöscht wird, und ob das Gespeicherte tatsächlich in der
 * Konfiguration ankommt.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();
    }

    #[Test]
    public function only_somebody_with_the_permission_gets_in(): void
    {
        /*
         * Eine Seite, die sich nur versteckt, ist nicht abgeschaltet -- dieselbe
         * Prüfung wie bei der Modulverwaltung (D3).
         */
        $this->actingAs($this->userWithout());

        $this->assertFalse(SettingsPage::canAccess());

        $this->actingAs($this->userWith());

        $this->assertTrue(SettingsPage::canAccess());
    }

    /**
     * Eine vom Modul gemeldete Auswahlliste macht aus dem Feld einen Select.
     *
     * Der Anlass: "kann nicht ohne auswahlliste nach der nummer der kategorie
     * gefragt werden" -- die Einstellung "Kategorie" verlangte eine nackte
     * Nummer, obwohl das Vereinsflieger-Modul die Liste laengst kennt. Der
     * Kern darf die Modultabelle nicht lesen; das Modul meldet die Liste an
     * (SettingOptions), und genau diese Naht prueft der Test.
     */
    #[Test]
    public function a_module_supplied_option_list_turns_the_field_into_a_select(): void
    {
        app(SettingOptions::class)->provide(
            'vereinsflieger.workhours.category',
            static fn (): array => ['7265' => 'Wartung/Werkstatt (7265)'],
        );

        Livewire::actingAs($this->userWith())
            ->test(SettingsPage::class)
            ->assertSee('Wartung/Werkstatt (7265)');
    }

    /**
     * Und ein gespeicherter Wert, den die Liste nicht (mehr) kennt, bleibt
     * sichtbar -- gekennzeichnet statt stumm verschluckt.
     */
    #[Test]
    public function a_stored_value_missing_from_the_list_stays_visible(): void
    {
        app(Settings::class)->set('vereinsflieger.workhours.category', '9999');

        app(SettingOptions::class)->provide(
            'vereinsflieger.workhours.category',
            static fn (): array => ['7265' => 'Wartung/Werkstatt (7265)'],
        );

        Livewire::actingAs($this->userWith())
            ->test(SettingsPage::class)
            ->assertSee('9999');
    }

    /**
     * Jede Gruppe hat Titel UND Beschreibung -- als Text, nicht als Schluessel.
     *
     * Auf test.aeronance.de stand "settings.group_help.mail" mitten auf der
     * Seite: Die Beschreibung der Mail-Gruppe fehlte in der Sprachdatei, und
     * __() liefert dann den rohen Schluessel. Ein Mensch sieht das sofort,
     * ein Test nur, wenn er ALLE Gruppen durchgeht -- deshalb hier die
     * Schleife statt einer Stichprobe.
     */
    #[Test]
    public function every_settings_group_has_its_texts(): void
    {
        foreach (array_keys(SettingsCatalogue::byGroup()) as $gruppe) {
            $this->assertTrue(
                trans()->has('settings.group.'.$gruppe),
                sprintf('Der Gruppe "%s" fehlt der Titel in lang/de/settings.php.', $gruppe),
            );
            $this->assertTrue(
                trans()->has('settings.group_help.'.$gruppe),
                sprintf('Der Gruppe "%s" fehlt die Beschreibung in lang/de/settings.php.', $gruppe),
            );
        }
    }

    /**
     * Die Auslagerung zeigt nur die Felder ihres Ziels -- und ist ohne
     * Backup-Verschluesselung gesperrt.
     *
     * Beides Rueckmeldung aus dem Betrieb: Dreizehn Felder nebeneinander,
     * obwohl immer nur ein Ziel gilt; und ein Ziel-Feld, das sich ausfuellen
     * laesst, obwohl der Lauf ohne Verschluesselung ohnehin scheitert.
     */
    #[Test]
    public function the_offsite_target_is_locked_without_backup_encryption(): void
    {
        Livewire::actingAs($this->userWith())
            ->test(SettingsPage::class)
            ->assertSee(__('settings.offsite_locked'));
    }

    #[Test]
    public function offsite_fields_follow_the_chosen_target(): void
    {
        app(Settings::class)->set('backup.encryption.mode', 'passphrase');
        app(Settings::class)->set('backup.offsite.disk', 'offsite_sftp');

        Livewire::actingAs($this->userWith())
            ->test(SettingsPage::class)
            ->assertDontSee(__('settings.offsite_locked'))
            ->assertSee(__('settings.catalogue.backup.sftp.host.label'))
            ->assertDontSee(__('settings.catalogue.backup.s3.bucket.label'))
            ->assertDontSee(__('settings.catalogue.backup.offsite.path.label'));
    }

    #[Test]
    public function a_value_saved_here_reaches_the_configuration(): void
    {
        Livewire::actingAs($this->userWith())
            ->test(SettingsPage::class)
            ->set('data.organisation__name', 'Akaflieg Freiburg')
            ->set('data.retention__activity_log__enabled', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Akaflieg Freiburg', app(Settings::class)->get('organisation.name'));
        $this->assertSame('Akaflieg Freiburg', config('aeronance.organisation.name'));
        $this->assertTrue(config('aeronance.retention.activity_log.enabled'));
    }

    /**
     * ─────────────────────────────────────────────────────────────────────────
     * DER VIRENSCANNER LIESS SICH EINSCHALTEN, OHNE ANZUSPRINGEN.
     *
     * Die vier ClamAV-Felder zeigten auf „aeronance.clamav.*", gelesen wird
     * „aeronance.documents.clamav.*". Gespeichert wurde brav, gewirkt hat
     * nichts -- ausgerechnet an einer Sicherheitsfunktion, und niemandem
     * gefallen, weil vor 0.1.9 überhaupt keine Einstellung in der Konfiguration
     * ankam.
     * ─────────────────────────────────────────────────────────────────────────
     */
    #[Test]
    public function switching_the_scanner_on_here_actually_switches_it_on(): void
    {
        Livewire::actingAs($this->userWith())
            ->test(SettingsPage::class)
            ->set('data.virus_scanner', 'clamav')
            ->set('data.clamav__transport', 'socket')
            ->set('data.clamav__socket', '/var/run/clamav/clamd.ctl')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('clamav', config('aeronance.documents.scanner'));
        $this->assertSame('/var/run/clamav/clamd.ctl', config('aeronance.documents.clamav.socket'));

        // Und der Container baut daraufhin wirklich den richtigen Scanner.
        app()->forgetInstance(VirusScanner::class);
        $this->assertInstanceOf(ClamAvScanner::class, app(VirusScanner::class));
    }

    #[Test]
    public function testing_the_connection_without_a_scanner_says_so_instead_of_knocking(): void
    {
        Livewire::actingAs($this->userWith())
            ->test(SettingsPage::class)
            ->set('data.virus_scanner', 'none')
            ->callAction('testScanner')
            ->assertNotified(__('settings.scanner_test.switched_off'));
    }

    #[Test]
    public function an_empty_secret_field_leaves_the_stored_secret_alone(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * DER FEHLER, DER TEUER GEWESEN WÄRE.
         *
         * Geheimnisse werden nie zurückgezeigt, das Feld ist also immer leer.
         * Läse man das als "löschen", würde jedes Speichern einer beliebigen
         * anderen Einstellung das Backup-Passwort entfernen -- und auffallen
         * würde das erst beim nächsten nächtlichen Lauf, der dann nichts mehr
         * auslagern darf.
         * ─────────────────────────────────────────────────────────────────────
         */
        app(Settings::class)->set('backup.encryption.passphrase', 'ein sehr langes Passwort');

        Livewire::actingAs($this->userWith())
            ->test(SettingsPage::class)
            ->set('data.organisation__name', 'Irgendein Verein')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            'ein sehr langes Passwort',
            (new Settings)->get('backup.encryption.passphrase'),
        );
    }

    #[Test]
    public function the_form_never_hands_a_secret_back_to_the_browser(): void
    {
        app(Settings::class)->set('backup.encryption.passphrase', 'ein sehr langes Passwort');

        $antwort = Livewire::actingAs($this->userWith())->test(SettingsPage::class);

        $this->assertNull($antwort->get('data.backup__encryption__passphrase'));
        $antwort->assertDontSee('ein sehr langes Passwort');
    }

    #[Test]
    public function what_is_stored_stays_encrypted(): void
    {
        Livewire::actingAs($this->userWith())
            ->test(SettingsPage::class)
            ->set('data.backup__sftp__password', 'geheimes SFTP Passwort')
            ->call('save');

        $roh = (string) DB::table('settings')->where('key', 'backup.sftp.password')->value('value');

        $this->assertStringNotContainsString('geheimes SFTP Passwort', $roh);
    }

    #[Test]
    public function a_value_can_be_reset_to_the_environment_again(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * OHNE DIESEN WEG GÄBE ES KEINEN ZURÜCK.
         *
         * Ein einmal gesetzter Wert gewinnt für immer gegen die Umgebung — das
         * ist die Entscheidung. Wer ihn wieder aus der docker-compose.yml
         * beziehen will, müsste sonst die Zeile in der Tabelle von Hand
         * löschen, also doch wieder auf die Konsole. Genau das sollte dieser
         * Umbau abschaffen.
         * ─────────────────────────────────────────────────────────────────────
         */
        Env::getRepository()->set('ORGANISATION_NAME', 'Aus der Umgebung');

        $settings = app(Settings::class);
        $settings->set('organisation.name', 'Von Hand');

        $this->assertSame('datenbank', $settings->sourceOf('organisation.name'));

        Livewire::actingAs($this->userWith())
            ->test(SettingsPage::class)
            ->call('resetSetting', 'organisation.name');

        $this->assertSame('Aus der Umgebung', (new Settings)->get('organisation.name'));
        $this->assertSame('umgebung', (new Settings)->sourceOf('organisation.name'));

        Env::getRepository()->clear('ORGANISATION_NAME');
    }

    #[Test]
    public function resetting_is_guarded_on_both_layers(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * ZWEI EBENEN, WEIL EINE NICHT REICHT.
         *
         * Ohne Recht laedt die Seite schon nicht -- das faengt den Weg ueber die
         * Navigation. Es faengt aber NICHT jemanden, der den Livewire-Endpunkt
         * direkt anspricht; eine Aktion, die nur im Formular versteckt ist, ist
         * nicht geschuetzt (D3). Deshalb prueft die Methode noch einmal selbst.
         * ─────────────────────────────────────────────────────────────────────
         */
        app(Settings::class)->set('organisation.name', 'Von Hand');

        $this->actingAs($this->userWithout());

        // Erste Ebene: die Seite ist nicht erreichbar.
        $this->assertFalse(SettingsPage::canAccess());

        // Zweite Ebene: die Methode selbst weist ab.
        $seite = new SettingsPage;

        try {
            $seite->resetSetting('organisation.name');
            $this->fail('resetSetting haette abweisen muessen.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame('Von Hand', (new Settings)->get('organisation.name'));
    }

    #[Test]
    public function a_logo_is_stored_privately_and_served_without_a_login(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * OHNE ANMELDUNG, und das ist Absicht: Das Logo steht auf der
         * Anmeldeseite, die naturgemäss niemand angemeldet aufruft. Es ist das
         * Wappen einer Organisation, kein Geheimnis.
         *
         * Abgelegt wird es trotzdem auf der PRIVATEN Disk und über eine Route
         * ausgeliefert -- nicht in public/. Der übliche Weg dorthin wäre
         * storage:link, ein Symlink, den im Docker-Kanal jeder neue Container
         * neu bräuchte.
         * ─────────────────────────────────────────────────────────────────────
         */
        Storage::fake('local');

        $bild = UploadedFile::fake()->image('logo.png', 200, 200);
        $pfad = $bild->store('branding', 'local');

        app(Settings::class)->set('organisation.logo', $pfad);
        app(Settings::class)->applyToConfig();

        $this->get('/logo')
            ->assertSuccessful()
            ->assertHeader('Content-Type', 'image/png');
    }

    #[Test]
    public function without_a_logo_the_route_answers_plainly(): void
    {
        Storage::fake('local');
        app(Settings::class)->set('organisation.logo', '');
        app(Settings::class)->applyToConfig();

        $this->get('/logo')->assertNotFound();
    }

    #[Test]
    public function something_that_is_not_an_image_is_never_served(): void
    {
        /*
         * Die Route liefert ohne Anmeldung aus. Käme dort etwas anderes als ein
         * Bild heraus -- ein SVG mit Skript, eine hochgeladene HTML-Datei --,
         * wäre das genau die Lücke, die die CSP sonst schliesst. Geprüft wird
         * der Typ der GESPEICHERTEN Datei, nicht der Dateiname.
         */
        Storage::fake('local');
        Storage::disk('local')->put('branding/boese.png', '<svg onload="alert(1)"></svg>');

        app(Settings::class)->set('organisation.logo', 'branding/boese.png');
        app(Settings::class)->applyToConfig();

        $this->get('/logo')->assertNotFound();
    }

    private function userWith(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(CorePermissions::SETTINGS_MANAGE);

        return $user->fresh();
    }

    private function userWithout(): User
    {
        return User::factory()->create(['is_active' => true])->fresh();
    }
}
