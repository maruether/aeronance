<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Core\Demo\DemoMode;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Die Spielwiese auf Anfang stellen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIESER BEFEHL LÖSCHT EINE DATENBANK. Deshalb steht die Weigerung am Anfang
 * und nicht in der Dokumentation: Läuft er auf einer Instanz ohne Demo-Marke,
 * tut er nichts und sagt, warum.
 *
 * Geprüft wird die MARKE IM DATEIVERZEICHNIS, nicht eine Umgebungsvariable und
 * auch keine Zeile in der Datenbank. Eine Env-Variable ist eine Zeile, die
 * jemand beim Kopieren einer .env mitnimmt -- und dann löscht der nächtliche
 * Lauf die Aufzeichnungen eines Vereins. Eine Zeile in der Datenbank wäre nach
 * dem ersten Lauf selbst weg.
 *
 * GANZ ODER GAR NICHT. Vorgabe: „ganz, immer auf default". Nicht die Nutzdaten
 * herauszulöschen und den Rest zu behalten, sondern `migrate:fresh` samt Seeder
 * -- nur so weiss man hinterher, was dasteht. Der Installationsmarker bleibt
 * (er ist eine Datei): Nach dem Reset steht der Setup-Assistent NICHT wieder
 * offen im Internet, was die schlimmste Nebenwirkung dieses Befehls wäre.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class DemoResetCommand extends Command
{
    protected $signature = 'aeronance:demo-reset';

    protected $description = 'Setzt eine Demo-Instanz auf den Ausgangsstand zurück (löscht alle Daten).';

    public function handle(DemoMode $demo): int
    {
        if (! $demo->isActive()) {
            $this->error('Diese Installation läuft nicht im Demomodus -- es wird nichts gelöscht.');
            $this->line('Die Marke '.$demo->markerPath().' fehlt. Das ist die Sicherung, und sie greift.');

            return self::FAILURE;
        }

        $this->info('Demo wird zurückgesetzt …');

        try {
            // Erst die Dateien, dann die Datenbank: Bricht es dazwischen ab,
            // bleiben verwaiste Dateien liegen -- das ist harmloser als
            // Datenbankzeilen, die auf verschwundene Dateien zeigen.
            $this->clearDocuments();

            Artisan::call('migrate:fresh', ['--force' => true], $this->output);
            Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true], $this->output);
        } catch (Throwable $e) {
            $this->error('Der Reset ist gescheitert: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Fertig. Nächster Reset: '.$demo->nextReset()->format('d.m.Y H:i'));

        return self::SUCCESS;
    }

    /**
     * Die abgelegten Dateien wegräumen.
     *
     * In der Demo entstehen keine Uploads -- die sind abgeschaltet --, aber die
     * Beispieldokumente legt der Seeder jedes Mal neu an. Ohne dieses Aufräumen
     * sammelte sich mit jedem Reset ein weiterer Satz davon.
     */
    private function clearDocuments(): void
    {
        $disk = Storage::disk('documents');

        foreach ($disk->directories() as $verzeichnis) {
            $disk->deleteDirectory($verzeichnis);
        }

        foreach ($disk->files() as $datei) {
            $disk->delete($datei);
        }
    }
}
