<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Auth\RecordSignInAttempts;
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
    }

    public function boot(): void
    {
        $this->denyEverythingToDeactivatedAccounts();
        $this->recordSignInAttempts();
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
