<?php

declare(strict_types=1);

namespace App\Core\Setup;

use App\Core\Access\AccessSetup;
use App\Core\Access\CoreRoles;
use App\Core\Modules\ModuleManager;
use App\Core\Settings\Settings;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Carries out the first-run installation.
 *
 * Kept apart from the interface so the same steps can be driven from the
 * console -- which is what the Docker and LXC channels want, where the wizard
 * is answered by environment variables rather than by a person.
 *
 * The order matters and is not arbitrary: nothing may create an administrator
 * before the migrations have run, and nothing may lock the wizard before an
 * administrator exists. Locking too early would leave an installation nobody
 * can log into and no wizard to fix it.
 */
final readonly class SetupWizard
{
    public function __construct(
        private InstallationState $state,
        private AccessSetup $access,
        private ModuleManager $modules,
        private Settings $settings,
    ) {}

    /**
     * @return array{ok: bool, message: string}
     */
    public function testDatabase(): array
    {
        try {
            DB::connection()->getPdo();

            $version = DB::selectOne('SELECT VERSION() AS v')->v ?? '';

            if (! str_contains(strtolower($version), 'mariadb')) {
                return [
                    'ok' => false,
                    'message' => __('setup.db.not_mariadb', ['version' => $version]),
                ];
            }

            return ['ok' => true, 'message' => __('setup.db.ok', ['version' => $version])];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => __('setup.db.failed', ['error' => $e->getMessage()])];
        }
    }

    public function migrate(): void
    {
        Artisan::call('migrate', ['--force' => true]);
    }

    /**
     * Creates the first administrator.
     *
     * Deliberately refuses if one already exists: this runs before the wizard is
     * locked, and an installer that can add a second administrator to a working
     * system is a back door.
     */
    public function createAdministrator(string $name, string $email, string $password): User
    {
        if ($this->state->hasAdministrator()) {
            throw new RuntimeException('An administrator already exists.');
        }

        $this->access->run();

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_active' => true,
        ]);

        $user->assignRole(CoreRoles::ADMIN);

        return $user;
    }

    /**
     * @param  list<string>  $modules
     * @return array{enabled: list<string>, refused: array<string, list<string>>}
     */
    /**
     * Name und Zeitzone der Organisation -- die "Basiskonfiguration", die CLAUDE.md dem
     * Assistenten seit jeher zuschreibt und die es nie gab.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Sie steht bewusst VOR der Modulauswahl: Der Name erscheint danach in der
     * Kopfzeile und auf jedem Ausdruck, und die Zeitzone entscheidet, welches
     * Datum ein Sperrzettel traegt. Beides spaeter zu bemerken heisst, Papier
     * mit falschem Datum in der Welt zu haben.
     *
     * Geschrieben wird in die Einstellungstabelle, nicht in eine Datei -- der
     * ganze Punkt dieses Umbaus.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function configureOrganisation(string $name, string $timezone): void
    {
        $this->settings->set('organisation.name', $name);
        $this->settings->set('organisation.timezone', $timezone);
        $this->settings->applyToConfig();
    }

    public function selectModules(array $modules): array
    {
        $enabled = [];
        $refused = [];

        foreach ($modules as $name) {
            $decision = $this->modules->canEnable($name);

            if ($decision->isRefused()) {
                $refused[$name] = $decision->blockedBy;

                continue;
            }

            foreach ($this->modules->enable($name) as $switched) {
                $enabled[] = $switched;
            }
        }

        return ['enabled' => array_values(array_unique($enabled)), 'refused' => $refused];
    }

    /**
     * Ends the installation.
     *
     * The check is not ceremony: locking an installation that has no
     * administrator would leave nobody able to log in and no wizard left to
     * repair it.
     */
    public function finish(): void
    {
        if (! $this->state->hasAdministrator()) {
            throw new RuntimeException(
                'Refusing to finish without an administrator -- that would lock everyone out.'
            );
        }

        $this->state->markInstalled();
    }
}
