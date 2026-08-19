<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Demo\DemoMode;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

/**
 * Was in einer Demo NICHT geht -- an einer Stelle.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE EINSCHRÄNKUNGEN STEHEN HIER UND NICHT VERSTREUT IN DEN MASKEN. Ein
 * Demomodus, der an dreissig Stellen „wenn Demo, dann anders" sagt, ist nach
 * dem dritten neuen Formular löchrig -- und ein Loch in einer öffentlich
 * erreichbaren Instanz ist keine Schönheitsfrage.
 *
 * Deshalb greifen die Sperren so weit oben wie möglich:
 *
 *   UPLOADS -- über configureUsing an den Upload-Bausteinen selbst. Jedes
 *   Uploadfeld dieser Anwendung, auch das nächste, ist damit abgeschaltet und
 *   erklärt sich. Die Tür dahinter (Livewires Upload-Endpunkt) verschliesst
 *   zusätzlich eine Middleware -- was nur im Formular versteckt ist, gilt als
 *   nicht vorhanden.
 *
 *   MAIL -- durch Umlegen des Mailers auf „log". Damit sagt Postman::canSend()
 *   von selbst nein, und alles, was daran hängt (die Passwort-vergessen-Route,
 *   Erinnerungen, Testmails), verschwindet ohne eigene Abfrage. Vorgabe:
 *   „ausgehende mailserver sind in der demo nicht verfügbar und nicht
 *   einrichtbar."
 *
 *   SUCHMASCHINEN -- eine Demo darf nicht als Vereinsverwaltung in
 *   Suchergebnissen landen.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class DemoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DemoMode::class);

        /*
         * DER MAILER MUSS SCHON HIER UMGELEGT SEIN, nicht erst in boot():
         * Filament baut sein Panel im register() des Panel-Providers, und dort
         * entscheidet Postman::canSend(), ob es „Passwort vergessen" überhaupt
         * anbietet. Eine Zeile später wäre der Link schon gebaut -- und er
         * führte ins Leere, weil nie eine Mail hinausgeht.
         *
         * isActive() liest eine Datei; das ist der einzige Weg, der ohne
         * Datenbank auskommt, und genau das braucht es an dieser Stelle.
         */
        if ($this->app->make(DemoMode::class)->isActive()) {
            $this->stopMail();
        }
    }

    public function boot(): void
    {
        if (! $this->app->make(DemoMode::class)->isActive()) {
            return;
        }

        $this->stopUploads();
        $this->markAsDemo();
    }

    /**
     * Kein Mailversand, und keine Möglichkeit, einen einzurichten.
     *
     * Das Umlegen des Mailers ist die halbe Miete; die andere Hälfte ist, dass
     * die Mail-Einstellungen in der Oberfläche gesperrt sind -- siehe
     * SettingsCatalogue. Ohne beides bekäme jemand ein Formular, dessen Werte
     * folgenlos bleiben, und das ist schlimmer als ein fehlendes Formular.
     */
    private function stopMail(): void
    {
        Config::set('mail.default', 'log');
    }

    private function stopUploads(): void
    {
        $sperren = static function (FileUpload|SpatieMediaLibraryFileUpload $upload): void {
            $upload->disabled()->helperText(__('demo.uploads_disabled'));
        };

        FileUpload::configureUsing($sperren);
        SpatieMediaLibraryFileUpload::configureUsing($sperren);
    }

    /**
     * Der Hinweis im Panel: was das hier ist, und wann es verschwindet.
     *
     * Ohne ihn trägt jemand eine halbe Wartungsakte ein und findet sie am
     * nächsten Morgen nicht wieder.
     */
    private function markAsDemo(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::TOPBAR_START,
            static fn (): string => view('core.demo.banner')->render(),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
            static fn (): string => view('core.demo.accounts')->render(),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            static fn (): string => '<meta name="robots" content="noindex, nofollow">',
        );
    }
}
