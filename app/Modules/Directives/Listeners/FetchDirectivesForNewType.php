<?php

declare(strict_types=1);

namespace App\Modules\Directives\Listeners;

use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Directives\Jobs\ImportForNewTypeJob;
use App\Modules\Directives\Permissions;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\SourceRegistry;
use App\Modules\Fleet\Events\AircraftTypeCreated;
use Filament\Notifications\Notification;
use Throwable;

/**
 * Ein neues Muster zieht seine Herstellerlisten an -- ohne Klick.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Feldtest: "Liste sollte automatisch zu einem angelegten Muster gezogen
 * werden, ohne user interaktion. Wenn ein login benoetigt wird sollte darauf
 * direkt hingewiesen werden." Freigegebene Strategie: AN DEN HERSTELLER
 * GEBUNDEN, nicht alle 48 Quellen anproben -- und die Bindung ist bewusst
 * weich (Namensvergleich in beide Richtungen), damit "Robin" die
 * C.E.A.P.R.-Quelle trifft: Der Musterhersteller heisst im Blauen Buch
 * anders als die Firma, die heute die Betreuung fuehrt.
 *
 * Der Abruf selbst laeuft als Job (fremde Server); hier passiert nur die
 * Zuordnung und der sofortige Hinweis, wenn einer Quelle die Zugangsdaten
 * fehlen. Leise Ablehnungen wie bei jeder Naht: kein Modul, kein Hersteller,
 * kein angemeldeter Benutzer -- nichts zu tun, nie ein Fehler.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class FetchDirectivesForNewType
{
    public function __construct(private ModuleManager $modules) {}

    public function handle(AircraftTypeCreated $event): void
    {
        if (! $this->modules->isEnabled('directives')) {
            return;
        }

        if (blank($event->manufacturer) || $event->userId === null) {
            // Ohne Hersteller keine Zuordnung; ohne Person niemand, unter
            // dessen Namen der Import laufen und der den Hinweis lesen koennte
            // -- der Sonntagslauf deckt beide Faelle ab.
            return;
        }

        $user = User::query()->find($event->userId);

        if ($user === null) {
            return;
        }

        foreach (app(SourceRegistry::class)->automatic() as $source) {
            if (! $source instanceof ConfiguredSource) {
                continue;
            }

            if (! $this->matchesManufacturer($source, (string) $event->manufacturer)) {
                continue;
            }

            if (! $source->isUsable()) {
                $this->pointAtCredentials($source);

                continue;
            }

            if (! $user->can(Permissions::DIRECTIVES_MANAGE)) {
                // Die Quelle passt, aber der Import liefe unter einem Namen,
                // der ihn nicht ausfuehren duerfte. Sagen statt schweigen --
                // der Sonntagslauf holt die Liste ohnehin.
                $this->announceDeferred($source);

                continue;
            }

            ImportForNewTypeJob::dispatch(
                $source->name(),
                $event->designation,
                $event->userId,
            );
        }
    }

    /**
     * Weicher Namensvergleich in beide Richtungen.
     *
     * "Robin" (Musterhersteller) muss "C.E.A.P.R. (Robin)" treffen und
     * "Schempp-Hirth Flugzeugbau" den Eintrag "Schempp-Hirth". Ein exakter
     * Vergleich waere am ersten Umfirmieren gescheitert.
     */
    private function matchesManufacturer(ConfiguredSource $source, string $manufacturer): bool
    {
        $hersteller = $this->normalise($manufacturer);

        if ($hersteller === '') {
            return false;
        }

        foreach ([$source->label(), (string) ($source->spec()->issuer ?? '')] as $kandidat) {
            $kandidat = $this->normalise($kandidat);

            if ($kandidat === '') {
                continue;
            }

            if (str_contains($kandidat, $hersteller) || str_contains($hersteller, $kandidat)) {
                return true;
            }
        }

        return false;
    }

    private function pointAtCredentials(ConfiguredSource $source): void
    {
        $this->notify(
            Notification::make()
                ->warning()
                ->title(__('directives.auto_fetch.needs_credentials', ['source' => $source->label()]))
                ->body(__('directives.auto_fetch.needs_credentials_hint'))
                ->persistent(),
        );
    }

    private function announceDeferred(ConfiguredSource $source): void
    {
        $this->notify(
            Notification::make()
                ->info()
                ->title(__('directives.auto_fetch.deferred', ['source' => $source->label()])),
        );
    }

    private function notify(Notification $notification): void
    {
        try {
            $notification->send();
        } catch (Throwable) {
            // Ausserhalb einer Web-Sitzung (Konsole, Import) gibt es keinen
            // Empfaenger -- und dieser Zuhoerer laeuft hinter fremder Arbeit.
        }
    }

    private function normalise(string $value): string
    {
        return mb_strtolower(preg_replace('/[^a-z0-9]/i', '', $value) ?? '');
    }
}
