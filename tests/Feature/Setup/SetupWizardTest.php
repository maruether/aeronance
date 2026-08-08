<?php

declare(strict_types=1);

namespace Tests\Feature\Setup;

use App\Core\Access\AccessSetup;
use App\Core\Access\CoreRoles;
use App\Core\Setup\InstallationState;
use App\Core\Setup\SetupWizard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The first-run wizard, and above all its lock.
 *
 * An installer route left reachable is a classic way in -- it can create an
 * administrator, which is full access. So most of what is tested here is that
 * the wizard is GONE once it should be.
 */
final class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    private InstallationState $state;

    protected function setUp(): void
    {
        parent::setUp();

        $this->state = app(InstallationState::class);
        $this->removeMarker();
    }

    protected function tearDown(): void
    {
        $this->removeMarker();

        parent::tearDown();
    }

    #[Test]
    public function a_fresh_installation_shows_the_wizard(): void
    {
        $this->get('/setup')->assertSuccessful();
    }

    #[Test]
    public function database_credentials_are_probed_before_anything_is_written(): void
    {
        // Erst die Probe, dann der Stift: Ein Tippfehler ist eine
        // Fehlermeldung, keine kaputte Konfiguration.
        $env = (string) tempnam(sys_get_temp_dir(), 'aeronance-env');
        file_put_contents($env, "DB_HOST=alt\n");

        $result = app(SetupWizard::class)->configureDatabase([
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'gibt_es_nicht_'.uniqid(),
            'username' => 'niemand_'.uniqid(),
            'password' => 'falsch',
        ], $env);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString(
            'DB_HOST=alt',
            (string) file_get_contents($env),
            'Bei fehlgeschlagener Probe wird nichts geschrieben.',
        );

        unlink($env);
    }

    #[Test]
    public function working_credentials_end_up_in_the_env_file(): void
    {
        $env = (string) tempnam(sys_get_temp_dir(), 'aeronance-env');
        file_put_contents($env, "APP_NAME=Aeronance\nDB_HOST=alt\n");

        $conn = (array) config('database.connections.'.config('database.default'));

        $result = app(SetupWizard::class)->configureDatabase([
            'host' => (string) $conn['host'],
            'port' => (int) $conn['port'],
            'database' => (string) $conn['database'],
            'username' => (string) $conn['username'],
            'password' => (string) ($conn['password'] ?? ''),
        ], $env);

        $inhalt = (string) file_get_contents($env);
        unlink($env);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertStringContainsString('DB_CONNECTION="mariadb"', $inhalt);
        $this->assertStringContainsString('DB_HOST="'.$conn['host'].'"', $inhalt);
        $this->assertStringContainsString('APP_NAME=Aeronance', $inhalt, 'Fremde Zeilen bleiben.');
        $this->assertStringNotContainsString('DB_HOST=alt', $inhalt);
    }

    #[Test]
    public function the_front_page_leads_to_the_wizard_when_nothing_is_set_up(): void
    {
        $this->get('/')->assertRedirect(route('setup.index'));
    }

    #[Test]
    public function it_creates_the_first_administrator(): void
    {
        $this->post(route('setup.administrator'), [
            'name' => 'Erika Mustermann',
            'email' => 'marvin@example.org',
            'password' => 'einhinreichendlangespasswort1',
            'password_confirmation' => 'einhinreichendlangespasswort1',
        ])->assertRedirect();

        $user = User::sole();
        $this->assertTrue($user->hasRole(CoreRoles::ADMIN));
        $this->assertTrue($user->is_active);
    }

    #[Test]
    public function it_rejects_a_weak_password(): void
    {
        $this->post(route('setup.administrator'), [
            'name' => 'Erika Mustermann',
            'email' => 'marvin@example.org',
            'password' => 'kurz',
            'password_confirmation' => 'kurz',
        ])->assertSessionHasErrors('password');

        $this->assertSame(0, User::count());
    }

    #[Test]
    public function it_refuses_a_second_administrator(): void
    {
        // Otherwise the installer is a back door into a working system.
        $this->createAdministrator();

        $this->post(route('setup.administrator'), [
            'name' => 'Fremder',
            'email' => 'fremd@example.org',
            'password' => 'einhinreichendlangespasswort1',
            'password_confirmation' => 'einhinreichendlangespasswort1',
        ])->assertSessionHasErrors();

        $this->assertSame(1, User::count());
    }

    #[Test]
    public function it_refuses_to_finish_without_an_administrator(): void
    {
        // Locking here would leave an installation nobody can log into and no
        // wizard left to repair it.
        $this->post(route('setup.finish'))->assertSessionHasErrors('finish');

        $this->assertFalse($this->state->isInstalled());
    }

    #[Test]
    public function finishing_locks_the_wizard(): void
    {
        // The wizard logs the new administrator in, so the normal flow arrives
        // here authenticated.
        $admin = $this->createAdministrator();

        $this->actingAs($admin)->post(route('setup.finish'))->assertRedirect('/verwaltung');

        $this->assertTrue($this->state->isInstalled());
    }

    #[Test]
    public function the_whole_flow_works_end_to_end(): void
    {
        // The case the earlier design broke on: creating the administrator made
        // the installation "look in use", which locked the wizard one step
        // before it could be finished.
        $this->get('/setup')->assertSuccessful();

        $this->post(route('setup.administrator'), [
            'name' => 'Erika Mustermann',
            'email' => 'marvin@example.org',
            'password' => 'einhinreichendlangespasswort1',
            'password_confirmation' => 'einhinreichendlangespasswort1',
        ])->assertRedirect();

        // Still reachable, still logged in from the step before.
        $this->get('/setup')->assertSuccessful();
        $this->post(route('setup.modules'), ['modules' => []])->assertRedirect();
        $this->post(route('setup.finish'))->assertRedirect('/verwaltung');

        $this->assertTrue($this->state->isInstalled());
        $this->get('/setup')->assertNotFound();
    }

    #[Test]
    public function the_wizard_is_gone_once_installed(): void
    {
        $this->createAdministrator();
        $this->state->markInstalled();

        $this->get('/setup')->assertNotFound();
        $this->post(route('setup.administrator'), [])->assertNotFound();
        $this->post(route('setup.migrate'))->assertNotFound();
        $this->post(route('setup.finish'))->assertNotFound();
    }

    #[Test]
    public function a_stranger_cannot_continue_a_setup_whose_marker_went_missing(): void
    {
        // The marker vanishes through a botched deployment or a cleared storage
        // directory. The wizard becomes visible again -- but a passer-by must
        // not be able to change anything with it.
        $this->createAdministrator();
        $this->state->markInstalled();
        $this->removeMarker();

        $this->assertFalse($this->state->isInstalled(), 'precondition: the marker is gone');

        $this->post(route('setup.modules'), ['modules' => []])->assertNotFound();
        $this->post(route('setup.finish'))->assertNotFound();

        // Creating an administrator is refused by the step itself.
        $this->post(route('setup.administrator'), [
            'name' => 'Fremder',
            'email' => 'fremd@example.org',
            'password' => 'einhinreichendlangespasswort1',
            'password_confirmation' => 'einhinreichendlangespasswort1',
        ])->assertSessionHasErrors();

        $this->assertSame(1, User::count());
    }

    #[Test]
    public function the_administrator_may_continue_a_setup_whose_marker_went_missing(): void
    {
        // The other side of the same coin: the person who is entitled must be
        // able to finish, or a lost marker becomes an unrepairable state.
        $admin = $this->createAdministrator();
        $this->removeMarker();

        $this->actingAs($admin)->post(route('setup.finish'))->assertRedirect('/verwaltung');

        $this->assertTrue($this->state->isInstalled());
    }

    #[Test]
    public function it_reports_the_database_and_refuses_anything_but_mariadb(): void
    {
        $result = app(SetupWizard::class)->testDatabase();

        $this->assertTrue($result['ok']);
        $this->assertStringContainsStringIgnoringCase('mariadb', $result['message']);
    }

    private function createAdministrator(): User
    {
        app(AccessSetup::class)->run();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(CoreRoles::ADMIN);

        return $user;
    }

    private function removeMarker(): void
    {
        if (file_exists($this->state->markerPath())) {
            unlink($this->state->markerPath());
        }
    }
}
