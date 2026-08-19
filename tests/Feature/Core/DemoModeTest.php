<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Access\AccessSetup;
use App\Core\Access\CoreRoles;
use App\Core\Demo\DemoLimits;
use App\Core\Demo\DemoMode;
use App\Core\Setup\SetupWizard;
use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Models\Finding;
use App\Modules\TaskCards\Models\ReleaseToService;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\Vereinsflieger\Models\Connection;
use App\Providers\DemoServiceProvider;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Der Demomodus — und vor allem: was er auf einer Live-Instanz NICHT tut.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Der wichtigste Test dieser Datei ist der erste. `aeronance:demo-reset` löscht
 * eine Datenbank; die einzige Sicherung dagegen, dass er das auf den
 * Aufzeichnungen eines Vereins tut, ist die Marke im Dateiverzeichnis. Ein Test
 * dafür ist billiger als der Vorfall.
 *
 * Die Marke wird in jedem Test von Hand gesetzt und danach entfernt: Sie ist
 * eine Datei im storage-Verzeichnis, und ein vergessener Rest liesse jeden
 * folgenden Testlauf in einer Demo laufen.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class DemoModeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->stopDemo();

        parent::tearDown();
    }

    #[Test]
    public function the_reset_refuses_where_there_is_no_demo(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $code = Artisan::call('aeronance:demo-reset');

        $this->assertSame(1, $code, 'Ohne Demo-Marke darf der Reset nicht laufen.');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    #[Test]
    public function the_marker_decides_and_nothing_else(): void
    {
        $demo = app(DemoMode::class);

        $this->assertFalse($demo->isActive());

        // Auch eine gesetzte Umgebungsvariable macht noch keine Demo -- sie ist
        // nur die Vorauswahl fuer den Assistenten.
        config(['aeronance.demo.preselect' => true]);

        $this->assertTrue($demo->preselected());
        $this->assertFalse($demo->isActive());

        $this->startDemo();

        $this->assertTrue($demo->isActive());
    }

    #[Test]
    public function the_demo_accounts_are_locked(): void
    {
        $this->startDemo();

        $konto = User::factory()->create([
            'email' => DemoMode::email('admin'),
            'is_active' => true,
        ]);

        try {
            $konto->update(['password' => 'etwas-anderes']);
            $this->fail('Das Passwort eines Demokontos darf sich nicht ändern lassen.');
        } catch (RuntimeException) {
            // erwartet
        }

        try {
            $konto->fresh()->delete();
            $this->fail('Ein Demokonto darf sich nicht löschen lassen.');
        } catch (RuntimeException) {
            // erwartet
        }

        $this->assertDatabaseHas('users', ['email' => DemoMode::email('admin')]);
    }

    #[Test]
    public function an_ordinary_account_stays_editable_in_the_demo(): void
    {
        // Sonst waere die Benutzerverwaltung nicht vorfuehrbar -- und genau die
        // soll man ausprobieren duerfen.
        $this->startDemo();

        $user = User::factory()->create(['email' => 'gast@example.org', 'is_active' => true]);

        $user->update(['is_active' => false]);

        $this->assertFalse($user->fresh()->is_active);
    }

    #[Test]
    public function outside_the_demo_the_same_account_is_ordinary(): void
    {
        $konto = User::factory()->create([
            'email' => DemoMode::email('admin'),
            'is_active' => true,
        ]);

        $konto->update(['is_active' => false]);

        $this->assertFalse($konto->fresh()->is_active);
    }

    #[Test]
    public function the_upload_endpoint_is_shut(): void
    {
        $this->startDemo();

        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->post('/livewire/upload-file')
            ->assertForbidden();
    }

    #[Test]
    public function no_mailer_and_no_credentials(): void
    {
        $this->startDemo();

        // Der Provider legt den Mailer im register() um -- hier von Hand
        // ausgeloest, weil die Anwendung des Tests vorher gebootet hat.
        $provider = new DemoServiceProvider($this->app);
        $provider->register();

        $this->assertSame('log', config('mail.default'));

        $anbindung = Connection::create([
            'name' => 'Beispiel',
            'username' => 'demo',
            'password' => 'geheim',
            'app_key' => 'auch-geheim',
            'cid' => '0',
        ]);

        $this->assertSame('', $anbindung->fresh()->password);
        $this->assertSame('', $anbindung->fresh()->app_key);
    }

    #[Test]
    public function the_manual_fetch_runs_out(): void
    {
        $this->startDemo();

        config(['aeronance.demo.fetch_per_hour' => 2]);
        RateLimiter::clear('demo:directive-fetch');

        $limits = app(DemoLimits::class);

        $limits->guardDirectiveFetch();
        $limits->guardDirectiveFetch();

        // Die Handeingabe zaehlt nicht mit: Sie geht nicht ins Netz.
        $limits->guardDirectiveFetch(reachesOut: false);

        $this->expectException(RuntimeException::class);

        $limits->guardDirectiveFetch();
    }

    #[Test]
    public function the_seeder_builds_an_instance_worth_showing(): void
    {
        /*
         * Der Bestand ist das Produkt: Eine Demo mit leeren Listen führt
         * nichts vor. Dieser Test hält fest, dass jeder Teil davon entsteht --
         * er ist der Grund, warum ein stillschweigend abgebrochener Unterseeder
         * auffällt, statt eine halbe Demo zu hinterlassen.
         */
        $this->seed(DemoSeeder::class);

        $this->assertCount(6, User::all(), 'Sechs feste Konten.');
        $this->assertSame(3, Aircraft::count(), 'Drei Luftfahrzeuge, drei Wägeblätter.');
        $this->assertSame(2, WorkOrder::count());
        $this->assertSame(4, Finding::count());
        $this->assertSame(1, ReleaseToService::count(), 'Eine erteilte Freigabe zum Ansehen.');
        $this->assertSame(2, Connection::count(), 'Zwei Vereinsflieger-Anbindungen.');

        $this->assertSame(
            1,
            Connection::where('provides_identities', true)->count(),
            'Genau eine liefert Mitglieder, die andere nur Betriebszeiten.',
        );

        foreach (array_keys(DemoMode::ACCOUNTS) as $konto) {
            $this->assertDatabaseHas('users', ['email' => DemoMode::email($konto)]);
        }
    }

    #[Test]
    public function the_demo_installation_refuses_on_a_database_in_use(): void
    {
        /*
         * Der teuerste Fehlgriff, den dieses Programm anbietet: Auf einer
         * laufenden Installation, deren Marker verlorenging, würde der Demoweg
         * einen Beispielbestand anlegen UND die Instanz unter tägliches Löschen
         * stellen. Deshalb fragt er, ob hier schon jemand arbeitet.
         */
        app(AccessSetup::class)->run();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(CoreRoles::ADMIN);

        try {
            app(SetupWizard::class)->installDemo();
            $this->fail('Auf einer benutzten Datenbank darf keine Demo eingerichtet werden.');
        } catch (RuntimeException) {
            // erwartet
        }

        $this->assertFalse(
            app(DemoMode::class)->isActive(),
            'Und die Marke darf dabei nicht schon geschrieben worden sein.',
        );
    }

    private function startDemo(): void
    {
        file_put_contents(app(DemoMode::class)->markerPath(), 'test');
    }

    private function stopDemo(): void
    {
        $pfad = app(DemoMode::class)->markerPath();

        if (file_exists($pfad)) {
            unlink($pfad);
        }
    }
}
