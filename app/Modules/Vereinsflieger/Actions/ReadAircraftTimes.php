<?php

declare(strict_types=1);

namespace App\Modules\Vereinsflieger\Actions;

use App\Core\Modules\ModuleManager;
use App\Modules\Vereinsflieger\Models\AircraftLink;
use App\Modules\Vereinsflieger\Models\Connection;
use App\Modules\Vereinsflieger\VereinsfliegerClient;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Die Betriebszeiten der angebundenen Luftfahrzeuge holen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „du kannst die aktuellen zeiten für ein lfz auslesen. das sollte auch
 * nachts um 2, nach den mitgliedern gemacht werden."
 *
 * GEMESSEN an D-KEWW liefert `maintenance/airplane/{Kennzeichen}`:
 *
 *     motortime     788.55   -> engine_hours
 *     flighttime   8230.30   -> flight_hours
 *     landingcount   21342   -> landings
 *     towcount           0   -> hat in Aeronance keine Entsprechung
 *
 * Das ist der EINZIGE Wartungsendpunkt, und er ist lesend -- ein Flugzeug
 * anzulegen oder Zeiten zurueckzuschreiben kann die API nicht.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * JEDES LUFTFAHRZEUG EINZELN, und daran laesst sich nichts sparen: Der Endpunkt
 * nimmt genau ein Kennzeichen. Bei zehn Maschinen sind das zehn Anfragen --
 * plus An- und Abmeldung, einmal je Anbindung. Deshalb laeuft das nachts und
 * nicht, wenn jemand eine Seite oeffnet.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE MODULGRENZE: Diese Klasse fasst die Flotte an -- aber nur, wenn es sie
 * gibt. Ist das Flottenmodul aus, gibt es keine Luftfahrzeuge, also auch nichts
 * zu holen; die Anbindungstabelle bleibt stehen und tut nichts. Deshalb steht
 * die Pruefung ganz oben und nicht irgendwo im Ablauf.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ReadAircraftTimes
{
    /**
     * VF-Feld => Zaehlerart in Aeronance.
     *
     * `towcount` fehlt mit Absicht: Aeronance kennt Schleppstarts nicht als
     * eigene Zaehlerart, und einen Wert in eine unpassende Schublade zu legen
     * waere schlimmer als ihn wegzulassen.
     */
    private const MAPPING = [
        'motortime' => 'engine_hours',
        'flighttime' => 'flight_hours',
        'landingcount' => 'landings',
    ];

    /**
     * @param  AircraftLink|null  $only  genau diese eine Kopplung lesen -- der
     *                                   "Jetzt lesen"-Knopf; null heisst alle
     *                                   aktiven der Anbindung (Nachtlauf).
     * @return array{read: int, failed: int, skipped: int}
     */
    public function handle(Connection $connection, ?VereinsfliegerClient $client = null, ?AircraftLink $only = null): array
    {
        if (! app(ModuleManager::class)->isEnabled('fleet')) {
            return ['read' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $links = AircraftLink::query()
            ->active()
            ->where('connection_id', $connection->id)
            ->when($only !== null, fn ($query) => $query->whereKey($only->id))
            ->get();

        if ($links->isEmpty()) {
            return ['read' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $eigenerClient = $client === null;
        $client ??= $connection->client();

        if ($eigenerClient && ! $client->signIn()) {
            throw new \RuntimeException(sprintf(
                'Die Anbindung "%s" wird abgelehnt: %s',
                $connection->name,
                (string) ($client->lastResponse()['error'] ?? 'keine Begründung genannt'),
            ));
        }

        $gelesen = 0;
        $fehlgeschlagen = 0;
        $uebersprungen = 0;

        try {
            foreach ($links as $link) {
                try {
                    $antwort = $client->authorised('POST', 'maintenance/airplane/'.rawurlencode((string) $link->callsign));

                    $geschrieben = $this->record($link, $antwort);

                    if ($geschrieben === null) {
                        $uebersprungen++;
                        $link->recordRead('Kein Luftfahrzeug mit dieser Kennung in der Flotte.');

                        continue;
                    }

                    $gelesen++;
                    $link->recordRead(null);
                } catch (Throwable $e) {
                    /*
                     * EIN FEHLSCHLAG BEENDET DEN LAUF NICHT. Ein falsch
                     * geschriebenes Kennzeichen bei einer Maschine darf nicht
                     * die neun anderen um ihre Zeiten bringen -- er wird an der
                     * Zeile festgehalten und ist dort zu sehen.
                     */
                    $fehlgeschlagen++;
                    $link->recordRead(mb_substr($e->getMessage(), 0, 500));
                }
            }
        } finally {
            if ($eigenerClient) {
                $client->signOut();
            }
        }

        return ['read' => $gelesen, 'failed' => $fehlgeschlagen, 'skipped' => $uebersprungen];
    }

    /**
     * Die Werte als Zaehlerstaende festhalten.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * NUR, WENN SICH ETWAS GEAENDERT HAT. Ein Zaehlerstand ist unveraenderlich
     * (CounterReading verweigert update und delete), also erzeugte ein
     * naechtlicher Lauf sonst 365 identische Zeilen im Jahr je Zaehler und
     * Maschine -- und die Betriebshistorie waere nicht mehr lesbar.
     *
     * Verglichen wird gegen den JUENGSTEN Stand derselben Art. Ist er gleich,
     * ist nichts passiert; ist er kleiner, ist etwas passiert und es wird
     * geschrieben.
     *
     * @param  array<int|string, mixed>  $antwort
     */
    private function record(AircraftLink $link, array $antwort): ?int
    {
        /** @var class-string<Model> $aircraftModel */
        $aircraftModel = 'App\\Modules\\Fleet\\Models\\Aircraft';
        /** @var class-string<Model> $readingModel */
        $readingModel = 'App\\Modules\\Fleet\\Models\\CounterReading';

        if (! class_exists($aircraftModel) || $aircraftModel::query()->whereKey($link->aircraft_id)->doesntExist()) {
            return null;
        }

        $geschrieben = 0;

        foreach (self::MAPPING as $feld => $art) {
            if (! array_key_exists($feld, $antwort) || ! is_numeric($antwort[$feld])) {
                continue;
            }

            $wert = round((float) $antwort[$feld], 2);

            $letzter = $readingModel::query()
                ->where('aircraft_id', $link->aircraft_id)
                ->where('kind', $art)
                ->orderByDesc('read_at')
                ->orderByDesc('id')
                ->value('value');

            if ($letzter !== null && abs(((float) $letzter) - $wert) < 0.005) {
                continue;
            }

            $readingModel::create([
                'aircraft_id' => $link->aircraft_id,
                'kind' => $art,
                'value' => $wert,
                'read_at' => now()->toDateString(),

                /*
                 * KEIN user_id. Diesen Stand hat niemand abgelesen -- er kommt
                 * aus einer Schnittstelle. Einen Menschen daranzuschreiben
                 * waere eine Behauptung ueber eine Handlung, die nicht
                 * stattgefunden hat.
                 */
                'user_id' => null,
                'note' => __('vereinsflieger.counter.note', [
                    'connection' => $link->connection?->name ?? '?',
                    'callsign' => $link->callsign,
                ]),
            ]);

            $geschrieben++;
        }

        return $geschrieben;
    }
}
