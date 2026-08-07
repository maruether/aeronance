<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Access\AccessSetup;
use App\Core\Access\CorePermissions;
use App\Core\Filament\Pages\SettingsPage;
use App\Core\Settings\Settings;
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
