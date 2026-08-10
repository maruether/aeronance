<?php

declare(strict_types=1);

namespace App\Modules\Vereinsflieger\Jobs;

use App\Core\Modules\ModuleManager;
use App\Modules\Vereinsflieger\Actions\RunConnectionSync;
use App\Modules\Vereinsflieger\Models\Connection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Der Abgleich EINER Anbindung -- angestossen vom Knopf, gelaufen im Worker.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ALS JOB, NICHT IM KLICK: Gemessen dauert ein voller Abgleich mit knapp 400
 * Mitgliedern eine gute halbe Minute -- laenger, als eine Web-Anfrage leben
 * sollte, und laenger, als jemand vor einem drehenden Knopf wartet, ohne F5 zu
 * druecken. Genau das war die Rueckmeldung: "es dauert und es wird nicht
 * darauf hingewiesen."
 *
 * Das ERGEBNIS steht, wo es immer steht: an der Anbindung (letzter Lauf,
 * letzter Fehler) -- derselbe Ort, den auch der Nachtlauf beschreibt. Der
 * Knopf sagt das dazu, statt ein zweites Ergebnisfenster zu erfinden.
 *
 * KEIN RETRY: Der Nachtlauf kommt ohnehin, und ein fehlgeschlagener Abgleich
 * gegen einen mengenbegrenzten Dienst soll nicht selbsttaetig nachfassen.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SyncConnectionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Der Dienst ist langsam genug, dass 60 s (Vorgabe) zu knapp waeren. */
    public int $timeout = 600;

    public function __construct(public int $connectionId) {}

    public function handle(): void
    {
        if (! app(ModuleManager::class)->isEnabled('vereinsflieger')) {
            // Zwischen Klick und Ausfuehrung kann jemand das Modul abschalten.
            return;
        }

        $anbindung = Connection::query()->find($this->connectionId);

        if ($anbindung === null || ! $anbindung->is_active) {
            return;
        }

        try {
            app(RunConnectionSync::class)->handle($anbindung);
            $anbindung->recordRun(null);
        } catch (Throwable $e) {
            // Wie im Nachtlauf: Die Begruendung des Dienstes steht an der
            // Anbindung, wo die Liste sie zeigt -- nicht nur im Log.
            $anbindung->recordRun(mb_substr($e->getMessage(), 0, 500));
        }
    }
}
