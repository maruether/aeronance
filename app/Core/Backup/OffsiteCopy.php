<?php

declare(strict_types=1);

namespace App\Core\Backup;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Die Sicherung an einen zweiten Ort bringen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: "ein backup ohne offsite storage ist nur halb soviel wert." Genau --
 * eine Sicherung, die neben der Datenbank auf derselben Platte liegt, überlebt
 * den einen Fall nicht, für den sie gemacht ist.
 *
 * GEGEN EINE LARAVEL-DISK, nicht gegen einen selbstgebauten Client. Damit ist
 * das Ziel reine Konfiguration -- ein gemountetes Verzeichnis, ein
 * S3-kompatibler Speicher, eine Storage Box über SFTP --, und im Code steht
 * kein einziger anbieterspezifischer Pfad. CLAUDE.md verlangt das so: "Keine
 * kanalspezifischen Codepfade."
 *
 * GESTREAMT, nicht eingelesen. Eine Vereinssicherung hat hunderte Megabyte;
 * file_get_contents darauf ist auf einem kleinen Server das Ende.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * NACH DEM HOCHLADEN WIRD NACHGESEHEN, und das ist der eigentliche Punkt dieser
 * Klasse.
 *
 * Ein abgebrochener Upload hinterlässt eine Datei, die es GIBT -- nur zu kurz.
 * Sie steht im Verzeichnis, hat einen Namen und ein Datum, und niemand merkt
 * etwas, bis jemand sie braucht. Deshalb wird die Grösse am Ziel gegen die
 * Quelle geprüft und die Kopie bei Abweichung wieder entfernt: lieber gar keine
 * Kopie als eine, auf die sich jemand verlässt.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class OffsiteCopy
{
    public function __construct(private readonly ?string $disk = null) {}

    /**
     * Ob überhaupt ein Ziel eingerichtet ist.
     */
    public function isConfigured(): bool
    {
        return $this->diskName() !== '';
    }

    /**
     * Legt eine Datei am Ziel ab und prüft, dass sie vollständig ankam.
     *
     * @return string der Pfad am Ziel
     *
     * @throws RuntimeException
     */
    public function put(string $datei): string
    {
        if (! is_readable($datei)) {
            throw new RuntimeException(sprintf('%s ist nicht lesbar.', $datei));
        }

        $ziel = $this->target();
        $name = trim($this->prefix(), '/');
        $name = ($name === '' ? '' : $name.'/').basename($datei);

        $strom = fopen($datei, 'rb');

        if ($strom === false) {
            throw new RuntimeException(sprintf('%s liess sich nicht öffnen.', $datei));
        }

        try {
            if (! $ziel->writeStream($name, $strom)) {
                throw new RuntimeException(sprintf(
                    'Die Sicherung liess sich nicht nach %s (%s) schreiben.',
                    $name,
                    $this->diskName(),
                ));
            }
        } finally {
            if (is_resource($strom)) {
                fclose($strom);
            }
        }

        $erwartet = (int) filesize($datei);
        $angekommen = (int) $ziel->size($name);

        if ($angekommen !== $erwartet) {
            // Die halbe Kopie wieder weg -- siehe Klassenkommentar.
            $ziel->delete($name);

            throw new RuntimeException(sprintf(
                'Die Kopie am Ziel ist unvollständig (%d von %d Byte) und wurde wieder '
                .'entfernt. Eine halbe Sicherung ist schlimmer als keine, weil sie '
                .'aussieht wie eine ganze.',
                $angekommen,
                $erwartet,
            ));
        }

        return $name;
    }

    /**
     * Räumt am Ziel auf: behält die neuesten $behalten Sicherungen.
     *
     * Nach demselben Muster wie lokal. Ohne das läuft ein Backup-Space voll,
     * und der Lauf, der ihn füllt, ist der, der ihn dann nicht mehr beschreiben
     * kann.
     *
     * @return list<string> was entfernt wurde
     */
    public function prune(int $behalten): array
    {
        if ($behalten <= 0) {
            return [];
        }

        $ziel = $this->target();
        $ordner = trim($this->prefix(), '/');

        $dateien = $ziel->files($ordner === '' ? '/' : $ordner);

        // Nach Namen, denn der trägt den Zeitstempel (db-20260803-081711…).
        // Verlässlicher als die Änderungszeit, die manche Anbieter beim Kopieren
        // neu setzen.
        $gruppen = [];

        foreach ($dateien as $pfad) {
            if (preg_match('/-(\d{8}-\d{6})\./', basename($pfad), $m) === 1) {
                $gruppen[$m[1]][] = $pfad;
            }
        }

        krsort($gruppen);

        $entfernt = [];

        foreach (array_slice($gruppen, $behalten, null, true) as $satz) {
            foreach ($satz as $pfad) {
                $ziel->delete($pfad);
                $entfernt[] = $pfad;
            }
        }

        return $entfernt;
    }

    public function diskName(): string
    {
        return (string) ($this->disk ?? config('aeronance.backup.offsite.disk', ''));
    }

    private function prefix(): string
    {
        return (string) config('aeronance.backup.offsite.prefix', '');
    }

    /** @throws RuntimeException */
    private function target(): Filesystem
    {
        $name = $this->diskName();

        if ($name === '') {
            throw new RuntimeException('Es ist kein Auslagerungsziel eingerichtet (BACKUP_OFFSITE_DISK).');
        }

        /*
         * AM ERGEBNIS GEPRUEFT, nicht an der Konfiguration.
         *
         * Erst stand hier ein Blick in config('filesystems.disks.…'). Das war in
         * zweierlei Hinsicht falsch: Es faengt eine Disk nicht, die zwar
         * eingetragen ist, deren Treiber aber fehlt (S3 ohne das passende
         * Flysystem-Paket ist genau dieser Fall) -- und es lehnt eine Disk ab,
         * die zur Laufzeit gesetzt wurde, was jeden Test mit Storage::fake
         * unmoeglich machte.
         */
        try {
            return Storage::disk($name);
        } catch (\Throwable $e) {
            throw new RuntimeException(sprintf(
                'Die Disk "%s" laesst sich nicht oeffnen: %s. Ist sie in '
                .'config/filesystems.php eingetragen, und ist der passende '
                .'Flysystem-Treiber installiert?',
                $name,
                $e->getMessage(),
            ));
        }
    }
}
