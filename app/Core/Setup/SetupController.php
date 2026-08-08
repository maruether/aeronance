<?php

declare(strict_types=1);

namespace App\Core\Setup;

use App\Core\Modules\ModuleRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Throwable;

/**
 * The first-run wizard.
 *
 * Every route here sits behind BlockSetupWhenInstalled, so none of this exists
 * once the installation is done.
 *
 * The database credentials CAN be entered here -- a deliberate reversal of an
 * earlier decision. The concern (a web form rewriting the application's own
 * configuration) stands, so the form is fenced in instead of avoided: it only
 * exists while the connection does not work, it only writes what a throwaway
 * probe has verified, and the writer refuses control characters outright
 * (EnvFileWriter). In the Docker and LXC channels the credentials still come
 * from the environment and the step stays skipped.
 */
final class SetupController
{
    public function __construct(
        private readonly InstallationState $state,
        private readonly SetupWizard $wizard,
    ) {}

    public function index(): View
    {
        return view('core.setup.index', [
            'database' => $this->wizard->testDatabase(),
            'migrated' => $this->state->isMigrated(),
            'hasAdministrator' => $this->state->hasAdministrator(),
            'preconfigured' => $this->state->databaseIsPreconfigured(),
            'modules' => $this->availableModules(),
        ]);
    }

    public function configureDatabase(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'db_host' => ['required', 'string', 'max:255'],
            'db_port' => ['required', 'integer', 'between:1,65535'],
            'db_database' => ['required', 'string', 'max:64'],
            'db_username' => ['required', 'string', 'max:64'],
            // Leer ist zulaessig -- eine frische lokale MariaDB kann ohne
            // Passwort laufen; ob das klug ist, entscheidet der Betreiber.
            'db_password' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->wizard->configureDatabase([
            'host' => $data['db_host'],
            'port' => (int) $data['db_port'],
            'database' => $data['db_database'],
            'username' => $data['db_username'],
            'password' => (string) ($data['db_password'] ?? ''),
        ]);

        if (! $result['ok']) {
            return back()->withErrors(['database' => $result['message']])->withInput();
        }

        return back()->with('status', $result['message']);
    }

    public function migrate(): RedirectResponse
    {
        $check = $this->wizard->testDatabase();

        if (! $check['ok']) {
            return back()->withErrors(['database' => $check['message']]);
        }

        try {
            $this->wizard->migrate();
        } catch (Throwable $e) {
            return back()->withErrors(['database' => $e->getMessage()]);
        }

        return back()->with('status', __('setup.migrate.done'));
    }

    public function createAdministrator(Request $request): RedirectResponse
    {
        if ($this->state->hasAdministrator()) {
            return back()->withErrors(['email' => __('setup.admin.exists')]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);

        /*
         * HIER STAND EINMAL 'club_name'. Es wurde geprueft und danach nie
         * benutzt -- wer den Namen im Administratorschritt eintippte, verlor ihn
         * stillschweigend. Aufgefallen ist das erst, als der eigene Schritt fuer
         * die Organisation dazukam, den CLAUDE.md seit jeher vorsieht.
         */

        try {
            $user = $this->wizard->createAdministrator(
                $data['name'],
                $data['email'],
                $data['password'],
            );
        } catch (Throwable $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        Auth::login($user);

        return back()->with('status', __('setup.admin.created'));
    }

    public function configureOrganisation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'organisation_name' => ['required', 'string', 'max:120'],
            // Gegen die Liste des Systems geprueft und nicht gegen ein Muster:
            // "Europe/Freiburg" sieht richtig aus und existiert nicht.
            'organisation_timezone' => ['required', 'string', 'timezone'],
        ]);

        $this->wizard->configureOrganisation(
            $data['organisation_name'],
            $data['organisation_timezone'],
        );

        return back()->with('status', __('setup.organisation.saved'));
    }

    public function selectModules(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'modules' => ['array'],
            'modules.*' => ['string'],
        ]);

        $result = $this->wizard->selectModules($data['modules'] ?? []);

        if ($result['refused'] !== []) {
            $reasons = [];

            foreach ($result['refused'] as $module => $why) {
                $reasons[] = $module.': '.implode(' ', $why);
            }

            return back()->withErrors(['modules' => implode(' | ', $reasons)]);
        }

        return back()->with('status', __('setup.modules.saved'));
    }

    public function finish(): RedirectResponse
    {
        try {
            $this->wizard->finish();
        } catch (Throwable $e) {
            return back()->withErrors(['finish' => $e->getMessage()]);
        }

        return redirect('/verwaltung');
    }

    /**
     * @return list<array{name: string, title: string, description: string, requires: list<string>}>
     */
    private function availableModules(): array
    {
        if (! $this->state->isMigrated()) {
            return [];
        }

        $registry = app(ModuleRegistry::class);
        $modules = [];

        foreach ($registry->all() as $name => $module) {
            $manifest = $module->manifest();

            $modules[] = [
                'name' => $name,
                'title' => $manifest->title,
                'description' => $manifest->description,
                'requires' => array_map(
                    static fn (string $r): string => $registry->manifest($r)->title,
                    $manifest->requires,
                ),
            ];
        }

        return $modules;
    }
}
