<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Core\Filament\Auth\EditProfile;
use App\Core\Filament\Auth\RequestPasswordReset;
use App\Core\Filament\InitialsAvatarProvider;
use App\Core\Mail\Postman;
use App\Core\Modules\Contracts\AeronanceModule;
use App\Core\Modules\ModuleManager;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The single panel of the application.
 *
 * Two things happen here that matter architecturally:
 *
 * 1. Only the CORE registers its screens directly. Everything else arrives as
 *    a module plugin -- and only if that module is active. A disabled module is
 *    not hidden, it is never mounted, so it has no routes and no navigation
 *    entries to filter afterwards. That is the first of the three layers from
 *    D3; policies and background work are switched off separately.
 *
 * 2. Reading the active modules touches the database while the panel is being
 *    built. In a fresh installation that table does not exist yet -- the state
 *    the setup wizard runs in -- and ModuleManager answers "nothing active"
 *    rather than throwing, so the panel still boots.
 */
final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('verwaltung')
            ->login()

            /*
             * ─────────────────────────────────────────────────────────────────
             * „PASSWORT VERGESSEN" NUR, WENN AUCH MAIL RAUSGEHT.
             *
             * Ein Link, der ins Leere fuehrt, ist schlimmer als keiner: Wer ihn
             * drueckt, bekommt eine Bestaetigung, wartet auf eine Mail, die nie
             * kommt, und ruft dann den Werkstattleiter an. Ohne SMTP-Zugang
             * erscheint er deshalb gar nicht -- dann vergibt der Administrator
             * Passwoerter von Hand, und das ist auch eine Antwort.
             *
             * Die Pruefung liest die EINSTELLUNGEN, nicht die .env: Ein Verein
             * traegt den Zugang in der Oberflaeche ein, und der Link soll danach
             * da sein, ohne dass jemand etwas neu startet.
             * ─────────────────────────────────────────────────────────────────
             */
            ->passwordReset(Postman::canSend() ? RequestPasswordReset::class : null)

            /*
             * ─────────────────────────────────────────────────────────────────
             * ZWEI-FAKTOR-ANMELDUNG, als Angebot und nicht als Zwang.
             *
             * CLAUDE.md nennt sie als Kernbestandteil ("2FA als Option im
             * Kern"). Filament bringt sie mit; hier fehlte nur die Zeile.
             *
             * NICHT ERZWUNGEN (isRequired bleibt aus): Ein Verein, der das für
             * alle scharfschaltet, sperrt beim Erstkontakt jeden aus, der kein
             * Smartphone dabei hat -- und der Break-glass-Zugang ist für den
             * Notfall gedacht, nicht für den Dienstagabend. Wer sie will,
             * schaltet sie im eigenen Profil ein; ein Betrieb, der sie
             * vorschreiben muss, setzt hier isRequired: true.
             *
             * Mit Wiederherstellungscodes, weil ein verlorenes Telefon sonst ein
             * verlorenes Konto ist. Beides liegt verschlüsselt -- siehe die
             * Migration und die casts am User.
             * ─────────────────────────────────────────────────────────────────
             */
            ->multiFactorAuthentication(
                AppAuthentication::make()->recoverable(),
            )

            /*
             * Ohne Profilseite gäbe es keinen Ort, an dem jemand die
             * Zwei-Faktor-Anmeldung einschaltet -- oder sein Passwort ändert.
             *
             * Die eigene Fassung sperrt Name und Adresse bei Konten aus einem
             * Provider: Der nächtliche Abgleich setzt sie ohnehin neu, und ein
             * Mitglied, das seine neue Adresse einträgt und eine Bestätigung
             * bekommt, würde erst Wochen später merken, dass nichts ankommt.
             */
            ->profile(EditProfile::class, isSimple: false)
            ->brandName(config('aeronance.organisation.name'))

            /*
             * Das Logo der Organisation, wenn eines hinterlegt ist. Ueber die
             * Route und nicht aus public/ -- siehe LogoController.
             */
            ->brandLogo(fn (): ?string => filled(config('aeronance.organisation.logo'))
                ? route('organisation.logo')
                : null)
            ->brandLogoHeight('2rem')

            /*
             * Initialen statt ui-avatars.com: Filaments Vorgabe laedt die
             * Platzhalter von einem Fremddienst -- die CSP blockt das (zu
             * Recht), und neben jedem Konto stand ein kaputtes Bild. Siehe
             * InitialsAvatarProvider.
             */
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->colors([
                'primary' => Color::Sky,
            ])

            /*
             * The panel's own stylesheet, and it is not cosmetic.
             *
             * Filament otherwise serves its prebuilt CSS, which carries its own
             * fi-* classes and no utilities -- it cannot contain classes for
             * views it has never seen. Without this line every Tailwind class in
             * this project's panel views does nothing: thirteen views across five
             * modules, roughly three hundred and sixty classes, all inert. They
             * rendered as unstyled stacks, which reads as plain rather than
             * broken, and that is why nobody noticed.
             *
             * See resources/css/filament/admin/theme.css for which paths are
             * scanned. A missing @source there brings the same failure back,
             * quietly.
             */
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->discoverResources(in: app_path('Core/Filament/Resources'), for: 'App\Core\Filament\Resources')
            ->discoverPages(in: app_path('Core/Filament/Pages'), for: 'App\Core\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Core/Filament/Widgets'), for: 'App\Core\Filament\Widgets')
            ->plugins($this->activeModules())
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /**
     * @return list<AeronanceModule>
     */
    private function activeModules(): array
    {
        return app(ModuleManager::class)->enabledModules();
    }
}
