<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Core\Updates\ReleaseCheck;
use App\Core\Version;
use Illuminate\Console\Command;

/**
 * „Läuft hier die neueste Fassung?" — als Befehl.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Gedacht für zwei Gelegenheiten: von Hand, bevor man ein Update anfasst, und
 * als geplanter Lauf, damit die Antwort schon zwischengespeichert ist, wenn
 * jemand die Oberfläche öffnet.
 *
 * DER RÜCKGABEWERT SAGT ETWAS: 0 = aktuell oder keine Auskunft, 1 = es gibt
 * Neueres. Damit lässt sich der Befehl in ein eigenes Skript hängen, ohne die
 * Ausgabe zu lesen. Ein Fehlschlag der Prüfung ist dabei ausdrücklich KEIN
 * Fehler-Rückgabewert -- kein Internet ist ein normaler Betriebszustand, und
 * ein Skript, das daran scheitert, weckt jemanden ohne Grund.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class UpdateCheckCommand extends Command
{
    protected $signature = 'aeronance:update-check
        {--fresh : Zwischenspeicher übergehen}
        {--tag : Nur die Fassung ausgeben, auf die zu aktualisieren wäre -- sonst nichts}';

    protected $description = 'Prüft, ob eine neuere Fassung veröffentlicht wurde.';

    public function handle(ReleaseCheck $check): int
    {
        if ($this->option('tag')) {
            return $this->justTheTag($check);
        }

        $eigene = Version::current();

        $this->components->twoColumnDetail(
            __('updates.current'),
            $eigene ?? __('updates.development_build'),
        );

        if (! $check->enabled()) {
            $this->components->warn(__('updates.disabled'));

            return self::SUCCESS;
        }

        $neueste = $check->latest(fresh: (bool) $this->option('fresh'));

        if ($neueste === null) {
            $this->components->twoColumnDetail(__('updates.latest'), __('updates.unknown'));
            $this->components->info(__('updates.no_answer', [
                'repository' => (string) config('aeronance.updates.repository'),
            ]));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail(__('updates.latest'), $neueste);

        if ($eigene === null) {
            $this->components->warn(__('updates.no_version'));

            return self::SUCCESS;
        }

        if (! $check->updateAvailable()) {
            $this->components->info(__('updates.up_to_date'));

            return self::SUCCESS;
        }

        $this->components->warn(__('updates.available', ['version' => $neueste]));
        $this->line('  '.__('updates.how_to'));

        if ($check->releasesUrl() !== null) {
            $this->line('  '.$check->releasesUrl());
        }

        return self::FAILURE;
    }

    /**
     * Nur die Fassung, auf die zu aktualisieren wäre — für Skripte.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * SCHWEIGEN HEISST „NICHTS ZU TUN", und das ist die ganze Schnittstelle.
     *
     * Gibt es etwas Neueres, steht der Tag auf der Standardausgabe und sonst
     * nichts — kein Rahmen, keine Farbe, keine Überschrift. Gibt es nichts,
     * kommt keine Zeile. Ein Skript, das `[ -n "$AUSGABE" ]` prüft, ist damit
     * fertig; alles andere wäre eine Textausgabe, die man parsen müsste, und
     * geparste Textausgaben brechen beim ersten Übersetzungslauf.
     *
     * Der Rückgabewert bleibt SUCCESS, auch wenn nichts zu tun ist: Ein
     * Fehlschlag wäre gelogen, und ein systemd-Timer schriebe dafür jede Nacht
     * eine Fehlermeldung ins Journal.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private function justTheTag(ReleaseCheck $check): int
    {
        if (! $check->enabled() || Version::current() === null) {
            return self::SUCCESS;
        }

        $neueste = $check->latest(fresh: (bool) $this->option('fresh'));

        if ($neueste !== null && $check->updateAvailable()) {
            $this->output->writeln($neueste);
        }

        return self::SUCCESS;
    }
}
