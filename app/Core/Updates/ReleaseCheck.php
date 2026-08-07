<?php

declare(strict_types=1);

namespace App\Core\Updates;

use App\Core\Version;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gibt es eine neuere Fassung?
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „ich hätte gerne ein auto update das auf GitHub zugreift."
 *
 * Diese Klasse ist die erste Hälfte davon: SIE SCHAUT NACH, sie installiert
 * nichts. Das Einspielen bleibt `deploy/update.sh` — und das aus einem Grund,
 * der nicht Vorsicht heißt, sondern Bauart:
 *
 *   Im Docker-Kanal kann sich eine Installation gar nicht selbst aktualisieren;
 *   das Image ist unveränderlich, aktualisiert wird durch ein neues Image. Im
 *   Tarball-Kanal gibt es kein Git, mit dem sich ein Tag auschecken liesse.
 *   Ein „Update-Knopf" in der Anwendung liefe also in zwei von drei Kanälen ins
 *   Leere — und CLAUDE.md verbietet kanalspezifische Codepfade.
 *
 * Was in allen drei Kanälen funktioniert, ist die Frage. Also beantwortet die
 * Anwendung die Frage, und der Kanal erledigt den Rest.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ZWISCHENGESPEICHERT, WEIL DIESE FRAGE SICH NICHT OFT ÄNDERT.
 *
 * Ein Verein veröffentlicht keine zwei Fassungen am Tag. Ohne Zwischenspeicher
 * fragte jede Seitenanzeige bei GitHub nach — das ist langsam, es läuft in die
 * Ratenbegrenzung (60 Abrufe je Stunde und IP ohne Anmeldung), und es meldet
 * einem Dritten den Betrieb jeder einzelnen Instanz.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * EIN FEHLSCHLAG IST KEIN FEHLER. GitHub ist nicht erreichbar, der Verein sitzt
 * hinter einem Filter, die Instanz hat gar keinen Internetzugang — alles
 * normale Betriebszustände. Die Prüfung meldet dann „weiß nicht" und nicht
 * „kaputt", und die Oberfläche zeigt nichts an. Eine Werkstattverwaltung, die
 * eine Fehlermeldung anzeigt, weil sie GitHub nicht erreicht, hätte den
 * Zusammenhang verloren.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ReleaseCheck
{
    private const CACHE_KEY = 'aeronance.updates.latest';

    /**
     * Die neueste veröffentlichte Fassung — oder null, wenn unbekannt.
     */
    public function latest(bool $fresh = false): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $stunden = max(1, (int) config('aeronance.updates.cache_hours', 12));

        /** @var string|null $tag */
        $tag = Cache::remember(self::CACHE_KEY, now()->addHours($stunden), fn (): ?string => $this->fetch());

        return $tag;
    }

    /**
     * Was zuletzt bekannt war — OHNE nachzufragen.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * DER UNTERSCHIED ZU latest() IST DER PUNKT, UND ER IST KEIN DETAIL.
     *
     * `latest()` holt die Auskunft, wenn der Zwischenspeicher leer ist. Auf
     * einem Bildschirm wäre das ein Fehler: Die erste Seitenanzeige nach einem
     * Neustart müsste auf GitHub warten -- und wenn GitHub gerade nicht
     * antwortet, wartet sie bis zur Zeitüberschreitung. Eine
     * Werkstattverwaltung, die langsam startet, weil sie nach Updates schaut,
     * hat die Verhältnisse verkehrt.
     *
     * Deshalb liest die Oberfläche NUR, was schon da ist. Gefüllt wird der
     * Zwischenspeicher vom nächtlichen Lauf (`aeronance:update-check`) --
     * genau dafür gibt es ihn.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function known(): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        /** @var string|null $tag */
        $tag = Cache::get(self::CACHE_KEY);

        return $tag;
    }

    /**
     * Ist eine neuere Fassung bekannt? Ohne nachzufragen — für die Oberfläche.
     */
    public function updateKnown(): bool
    {
        $eigene = Version::current();
        $neueste = $this->known();

        if ($eigene === null || $neueste === null) {
            return false;
        }

        return version_compare(ltrim($neueste, 'vV'), ltrim($eigene, 'vV')) === 1;
    }

    /**
     * Ist eine neuere Fassung verfügbar als die laufende?
     *
     * `false`, solange eine der beiden Seiten unbekannt ist — im
     * Entwicklungsstand gibt es keine eigene Nummer, und dann jede
     * Veröffentlichung als „neuer" zu melden hiesse, jeden Entwickler
     * zum Update aufzufordern.
     */
    public function updateAvailable(): bool
    {
        return $this->compare() === 1;
    }

    /**
     * -1 = wir sind älter … 0 = gleich … 1 = es gibt Neueres … null = unbekannt.
     */
    public function compare(): ?int
    {
        $eigene = Version::current();
        $neueste = $this->latest();

        if ($eigene === null || $neueste === null) {
            return null;
        }

        return version_compare(
            ltrim($neueste, 'vV'),
            ltrim($eigene, 'vV'),
        );
    }

    public function enabled(): bool
    {
        return (bool) config('aeronance.updates.check', true)
            && filled(config('aeronance.updates.repository'));
    }

    /**
     * Die Adresse der Veröffentlichungen, für den Menschen zum Nachlesen.
     */
    public function releasesUrl(): ?string
    {
        $repo = (string) config('aeronance.updates.repository', '');

        return $repo === '' ? null : 'https://github.com/'.$repo.'/releases';
    }

    /**
     * ─────────────────────────────────────────────────────────────────────────
     * GEFRAGT WIRD NACH TAGS, NICHT NACH „RELEASES", und das ist eine
     * Korrektur: Hier stand `/releases/latest`.
     *
     * Ein GitLab-Push-Mirror überträgt REFS -- Branches und Tags. Ein GitHub
     * *Release* ist dagegen ein eigenes Objekt, das nur über die Oberfläche
     * oder die API entsteht. Auf einem reinen Spiegel gibt es also nie eines,
     * und `/releases/latest` hätte für immer 404 geantwortet: Die Prüfung wäre
     * dauerhaft stumm gewesen, ohne dass irgendetwas kaputt ausgesehen hätte.
     *
     * Tags sind ausserdem die verlässlichere Menge: Ein Release ohne Tag gibt
     * es nicht, ein Tag ohne Release schon.
     *
     * DIE HÖCHSTE FASSUNG WIRD SELBST ERMITTELT, weil die Reihenfolge der
     * GitHub-Antwort nichts zusagt -- sie ist weder nach Version noch
     * zuverlässig nach Datum sortiert. `v1.10.0` wäre alphabetisch kleiner als
     * `v1.9.0`, und genau daran scheitern solche Prüfungen üblicherweise beim
     * zehnten Release.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private function fetch(): ?string
    {
        $repo = (string) config('aeronance.updates.repository', '');

        try {
            $antwort = Http::timeout((int) config('aeronance.updates.timeout', 8))
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    /*
                     * GitHub verlangt eine Kennung. Sie nennt das Projekt und
                     * NICHT die Instanz -- wer hier die eigene Adresse
                     * einträgt, verrät jeder Anfrage, welcher Verein wann
                     * nachgesehen hat.
                     */
                    'User-Agent' => 'Aeronance',
                ])
                ->get(sprintf('https://api.github.com/repos/%s/tags', $repo), ['per_page' => 100]);

            if (! $antwort->successful()) {
                /*
                 * 404 heisst: kein Zugriff. Entweder gibt es das Repository
                 * nicht, oder es ist PRIVAT -- die GitHub-API antwortet ohne
                 * Anmeldung in beiden Faellen gleich. Solange der Spiegel
                 * privat ist, bleibt die Pruefung also stumm, und das ist
                 * richtig so: Sie soll nicht raten.
                 */
                Log::info('Aktualisierungspruefung ohne Ergebnis.', [
                    'status' => $antwort->status(),
                    'repository' => $repo,
                ]);

                return null;
            }

            $hoechste = null;

            foreach ((array) $antwort->json() as $eintrag) {
                $name = is_array($eintrag) ? ($eintrag['name'] ?? null) : null;

                if (! is_string($name) || preg_match('/^v?\d+\.\d+\.\d+/', $name) !== 1) {
                    continue;
                }

                if ($hoechste === null || version_compare(ltrim($name, 'vV'), ltrim($hoechste, 'vV')) === 1) {
                    $hoechste = $name;
                }
            }

            return $hoechste;
        } catch (Throwable $e) {
            /*
             * Kein Internet, ein Filter davor, eine Zeitueberschreitung -- alles
             * normale Betriebszustaende einer Werkstatt. Vermerkt, nicht
             * gemeldet.
             */
            Log::info('Aktualisierungspruefung nicht moeglich.', ['grund' => $e->getMessage()]);

            return null;
        }
    }
}
