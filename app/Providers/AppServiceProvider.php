<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Auth\RecordSignInAttempts;
use App\Core\Settings\SettingOptions;
use App\Core\Setup\InstallationState;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Als Singleton, damit der Zuhoerer sich innerhalb eines Aufrufs
         * merken kann, was er schon erfasst hat -- ein Anmeldeversuch loest
         * unter Umstaenden zwei Ereignisse aus. Siehe RecordSignInAttempts.
         */
        $this->app->singleton(RecordSignInAttempts::class);

        // Singleton zwingend: Module melden ihre Auswahllisten beim
        // Registrieren an, die Einstellungsseite fragt spaeter nach --
        // zwei Instanzen waeren zwei Gedaechtnisse.
        $this->app->singleton(SettingOptions::class);
    }

    public function boot(): void
    {
        $this->fallBackToFileDriversDuringSetup();
        $this->denyEverythingToDeactivatedAccounts();
        $this->recordSignInAttempts();
    }

    /**
     * Vor der Installation laufen Session und Cache über Dateien.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * DAS HENNE-EI, GEMESSEN AM ERSTEN DOCKER-START: SESSION_DRIVER=database
     * heisst, jede Web-Anfrage liest die sessions-Tabelle -- die erst durch
     * die Migrationen entsteht, die der Setup-Assistent ausfuehren soll, der
     * ohne Session-Tabelle mit 500 stirbt. Der Assistent verspricht selbst,
     * ohne erreichbare Datenbank zu funktionieren (InstallationState); mit
     * database-Treibern hielt er das nicht.
     *
     * Deshalb: Solange die Installation weder abgeschlossen ist (Marker) noch
     * benutzt aussieht (migriert + Admin), weichen database-Treiber auf file
     * aus. Nur database wird umgebogen -- wer redis konfiguriert, hat kein
     * Tabellenproblem, und die Tests fahren array. Nach dem Abschluss gilt
     * wieder die Konfiguration; die eine Folge ist gewollt und billig: Nach
     * dem letzten Setup-Schritt meldet man sich einmal regulaer an, weil die
     * Datei-Session nicht in die Datenbank umzieht.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private function fallBackToFileDriversDuringSetup(): void
    {
        $state = $this->app->make(InstallationState::class);

        if ($state->isInstalled() || $state->looksInUse()) {
            return;
        }

        if (config('session.driver') === 'database') {
            config(['session.driver' => 'file']);
        }

        if (config('cache.default') === 'database') {
            config(['cache.default' => 'file']);
        }
    }

    /**
     * Anmeldeversuche ins Protokoll.
     *
     * Ueber die Laravel-Ereignisse und nicht ueber eine eigene Anmeldeseite:
     * Damit haengt es nicht daran, WELCHE Seite anmeldet -- kuenftige
     * Identity-Provider-Module bringen eigene Wege mit, und die feuern
     * dieselben Ereignisse. Siehe RecordSignInAttempts.
     */
    private function recordSignInAttempts(): void
    {
        Event::listen(Failed::class, [RecordSignInAttempts::class, 'failed']);
        Event::listen(Login::class, [RecordSignInAttempts::class, 'succeeded']);
    }

    /**
     * A deactivated account can do nothing at all.
     *
     * Registered as a gate check rather than repeated in every screen, because
     * repeating it is how it gets forgotten. An adversarial test found exactly
     * that: the panel turned a deactivated account away, but an individual page
     * still answered yes to its own permission question -- so the account could
     * not log in, yet any code path that asked only "may they?" would have said
     * yes.
     *
     * Returning false denies outright; returning null lets the ordinary checks
     * proceed.
     */
    private function denyEverythingToDeactivatedAccounts(): void
    {
        Gate::before(function ($user): ?bool {
            if ($user instanceof User && ! $user->hasAccess()) {
                return false;
            }

            return null;
        });
    }
}
