<?php

declare(strict_types=1);

namespace App\Modules\Vereinsflieger\Actions;

use App\Core\Identity\ExternalIdentity;
use App\Core\Modules\ModuleManager;
use App\Modules\Vereinsflieger\Models\Connection;
use App\Modules\Vereinsflieger\Models\WorkHourTransfer;
use App\Modules\Vereinsflieger\VereinsfliegerClient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Geleistete Arbeitszeit nach Vereinsflieger buchen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER TEXT IST MARVINS VORGABE:
 *
 *     Kennzeichen | Workorder | Tätigkeit (kurz)
 *
 * Drei Angaben, in dieser Reihenfolge, weil die Stundenliste in Vereinsflieger
 * nur eine Textspalte hat: Wer dort sucht, sucht zuerst nach dem Flugzeug.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WAS GEMESSEN IST UND DEN BAU BESTIMMT:
 *
 *  1. `workhours/add` NIMMT `status` ENTGEGEN. Ein Eintrag kann also gleich als
 *     „Akzeptiert" ankommen -- und ist damit drueben nicht mehr aenderbar.
 *     Vorgabe: „wenn es sauber über aeronance dokumentiert ist dann bin ich
 *     happy wenn es akzeptiert heisst." Der Admin entscheidet das per
 *     Einstellung; `uidstatus` wird ignoriert, es steht also niemand als
 *     Bewerter daran.
 *
 *  2. IDENTISCHE DATEN ERZEUGEN EINEN ZWEITEN EINTRAG. Vereinsflieger prueft
 *     nichts, und loeschen kann die API gar nicht. Eine Doppelbuchung ist
 *     dauerhaft -- deshalb die Sperrtabelle mit eindeutigem Schluessel.
 *
 *  3. `hours` ist „HH:MM", nicht 1.75. Abgelesen an der Antwort von
 *     `workhours/list/daterange`, nicht geraten.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * VEREINSFLIEGER IST NICHT DER NACHWEIS. Der auditierbare Datensatz bleibt in
 * Aeronance -- Arbeitskarte und Vorgang, nach Freigabe eingefroren. Was hier
 * hinuebergeht, ist eine Zweitschrift fuer die Vereinsbuchhaltung. Deshalb ist
 * ein Fehlschlag hier auch kein Drama: Es fehlt eine Buchung, kein Nachweis.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class TransferWorkHours
{
    /**
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function handle(Connection $connection, ?VereinsfliegerClient $client = null): array
    {
        if (! (bool) config('aeronance.vereinsflieger.workhours.enabled', false)) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        }

        if (! app(ModuleManager::class)->isEnabled('taskcards')) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $offen = $this->pending();

        if ($offen === []) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 0];
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

        $gesendet = 0;
        $fehlgeschlagen = 0;
        $uebersprungen = 0;

        /** @var list<WorkHourTransfer> $gesendeteBelege */
        $gesendeteBelege = [];
        $tage = [];

        try {
            foreach ($offen as $zeit) {
                $uid = $this->uidFor((int) $zeit->user_id);

                if ($uid === null) {
                    // Ohne Vereinsflieger-Kennung gibt es niemanden, dem die
                    // Stunde gutgeschrieben werden koennte. Kein Fehler --
                    // dieser Mensch ist schlicht nicht in Vereinsflieger.
                    $uebersprungen++;

                    continue;
                }

                $daten = $this->payload($zeit, $uid);

                /*
                 * ERST DIE SPERRE, DANN DER VERSUCH. Legte man die Zeile erst
                 * nach der Antwort an, wuerde ein Abbruch dazwischen -- Timeout,
                 * Stromausfall -- die Buchung in der naechsten Nacht
                 * wiederholen, ohne dass jemand weiss, ob sie schon drueben ist.
                 */
                $beleg = $this->lockFor($zeit, $connection, $daten);

                if ($beleg === null) {
                    // Schon vergeben -- ein anderer Lauf war schneller.
                    $uebersprungen++;

                    continue;
                }

                $tage[$daten['jobdate']] = true;

                try {
                    $antwort = $client->authorised('POST', 'workhours/add', $daten);

                    $beleg->update([
                        'whid' => (string) ($antwort['whid'] ?? ''),
                        'status' => (string) ($antwort['status'] ?? ''),
                        'transferred_at' => now(),
                        'last_error' => null,
                    ]);

                    $gesendet++;
                } catch (Throwable $e) {
                    /*
                     * Die Zeile BLEIBT -- mit dem Fehler und dem erhoehten
                     * Zaehler. Sonst versuchte es jede Nacht erneut, was gestern
                     * schon scheiterte, gegen einen mengenbegrenzten Dienst und
                     * ohne dass es jemand merkt.
                     *
                     * ABER: Ob wirklich nichts ankam, weiss hier niemand. Ein
                     * Timeout kann eine Anfrage treffen, die drueben laengst
                     * ausgefuehrt wurde. Deshalb wird gleich nachgesehen.
                     */
                    $beleg->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);

                    $fehlgeschlagen++;
                }

                $gesendeteBelege[] = $beleg;
            }

            /*
             * ─────────────────────────────────────────────────────────────────
             * NACHSEHEN. Vorgabe: „nach eintagung der stunden muss das tool
             * einmal alles abrufen und prüfen ob die einträge da sind."
             *
             * Das beantwortet den einen Fall, den die Antwort nicht beantworten
             * kann: keine Antwort. Ein Timeout heisst nicht „nicht angekommen"
             * -- er heisst „unbekannt", und der Unterschied entscheidet
             * daraueber, ob eine Wiederholung eine Reparatur oder eine
             * Doppelbuchung ist.
             *
             * EINE Anfrage je betroffenem Tag, nicht eine je Eintrag.
             * ─────────────────────────────────────────────────────────────────
             */
            if ($gesendeteBelege !== []) {
                $bestaetigt = $this->verify($client, array_keys($tage), $gesendeteBelege);

                // Was das Nachsehen findet, war kein Fehlschlag.
                $fehlgeschlagen = max(0, $fehlgeschlagen - $bestaetigt['recovered']);
                $gesendet += $bestaetigt['recovered'];
            }
        } finally {
            if ($eigenerClient) {
                $client->signOut();
            }
        }

        return ['sent' => $gesendet, 'failed' => $fehlgeschlagen, 'skipped' => $uebersprungen];
    }

    /**
     * Die Sperre fuer diese Arbeitszeit -- neu oder wiederverwendet.
     *
     * Der eindeutige Schluessel macht den zweiten gleichzeitigen Lauf zum
     * Datenbankfehler statt zum zweiten Eintrag. Eine vorhandene Zeile ohne
     * Nummer ist dagegen ein offener Fall und wird WIEDERVERWENDET -- so
     * zaehlt `attempts` ueber Naechte hinweg und nicht bei null los.
     *
     * @param  array<string, string>  $daten
     */
    private function lockFor(object $zeit, Connection $connection, array $daten): ?WorkHourTransfer
    {
        $vorhanden = $zeit->transfer_id !== null
            ? WorkHourTransfer::find($zeit->transfer_id)
            : null;

        if ($vorhanden !== null) {
            if (! $vorhanden->mayRetry()) {
                return null;
            }

            $vorhanden->update([
                'attempts' => (int) $vorhanden->attempts + 1,
                'job_text' => $daten['jobtext'],
                'hours' => $daten['hours'],
                'category' => $daten['category'],
                'status' => $daten['status'],
            ]);

            return $vorhanden;
        }

        try {
            return WorkHourTransfer::create([
                'task_card_time_id' => $zeit->id,
                'connection_id' => $connection->id,
                'job_text' => $daten['jobtext'],
                'hours' => $daten['hours'],
                'category' => $daten['category'],

                // Der Status, mit dem GESENDET wird. Nach einer erfolgreichen
                // Antwort wird er durch den ersetzt, den Vereinsflieger meldet.
                'status' => $daten['status'],
                'attempts' => 1,
            ]);
        } catch (QueryException) {
            return null;
        }
    }

    /**
     * Nachsehen, was drueben wirklich angekommen ist.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * ERKANNT WIRD AM TEXT UND AM DATUM, nicht an der Nummer -- die Nummer ist
     * ja gerade das, was fehlt, wenn die Antwort ausblieb.
     *
     * Das ist eine Naeherung, und sie hat eine bekannte Grenze: Zwei
     * Arbeitszeiten desselben Menschen am selben Tag auf derselben Karte sind
     * fuer diesen Abgleich nicht unterscheidbar. Der Text traegt Kennzeichen,
     * Vorgang und Taetigkeit -- das ist genau genug fuer den Regelfall und zu
     * grob fuer den Sonderfall. Lieber eine fehlende Buchung als eine doppelte:
     * Loeschen kann Vereinsflieger nicht.
     *
     * @param  list<string>  $tage
     * @param  list<WorkHourTransfer>  $belege
     * @return array{recovered: int}
     */
    private function verify(VereinsfliegerClient $client, array $tage, array $belege): array
    {
        $drueben = [];

        foreach ($tage as $tag) {
            try {
                $liste = $client->authorised('POST', 'workhours/list/daterange', [
                    'datefrom' => $tag,
                    'dateto' => $tag,
                ]);
            } catch (Throwable) {
                // Das Nachsehen selbst ist fehlgeschlagen. Dann bleibt es beim
                // Stand von vorher -- und beim naechsten Lauf wird erneut
                // nachgesehen, solange die Obergrenze es zulaesst.
                continue;
            }

            foreach ($liste as $schluessel => $eintrag) {
                if (! is_array($eintrag) || ! is_numeric($schluessel)) {
                    continue;
                }

                $kennung = $this->fingerprint(
                    (string) ($eintrag['uid'] ?? ''),
                    (string) ($eintrag['jobdate'] ?? ''),
                    (string) ($eintrag['category'] ?? ''),
                    (string) ($eintrag['status'] ?? ''),
                    self::decodeEntities((string) ($eintrag['jobtext'] ?? '')),
                );

                $drueben[$kennung] = (string) ($eintrag['whid'] ?? '');
            }
        }

        $wiedergefunden = 0;

        foreach ($belege as $beleg) {
            $beleg->refresh();

            if ($beleg->succeeded()) {
                $beleg->update(['verified_at' => now()]);

                continue;
            }

            $zeit = DB::table('task_card_times')->where('id', $beleg->task_card_time_id)->first();

            if ($zeit === null) {
                continue;
            }

            $uid = $this->uidFor((int) $zeit->user_id);

            /*
             * Kategorie und Status kommen aus dem BELEG, nicht aus der
             * Einstellung: Aendert der Admin sie zwischen zwei Laeufen, wuerde
             * ein offener Beleg sonst gegen den falschen Wert verglichen, nie
             * wiedergefunden -- und beim naechsten Versuch doppelt gebucht.
             */
            $kennung = $this->fingerprint(
                (string) $uid,
                (string) $zeit->worked_on,
                (string) $beleg->category,
                (string) $beleg->status,
                (string) $beleg->job_text,
            );

            if (! array_key_exists($kennung, $drueben)) {
                $beleg->update(['verified_at' => now()]);

                continue;
            }

            /*
             * DA -- trotz ausgebliebener Antwort. Die Nummer wird nachgetragen,
             * und damit ist der Fall geschlossen: Kein weiterer Versuch, keine
             * Doppelbuchung.
             */
            $beleg->update([
                'whid' => $drueben[$kennung],
                'transferred_at' => $beleg->transferred_at ?? now(),
                'verified_at' => now(),
                'last_error' => null,
            ]);

            $wiedergefunden++;
        }

        return ['recovered' => $wiedergefunden];
    }

    /**
     * Was einen Eintrag drueben wiedererkennbar macht.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * FUENF MERKMALE, und das letzte ist die Idee: „check doch einfach die
     * kategorie noch mit."
     *
     * Damit wird die Verwechslung sehr unwahrscheinlich. Ein von Hand
     * angelegter Eintrag muesste Person, Datum, Wortlaut, Status UND Kategorie
     * treffen -- und bei einer API-only-Kategorie (in der Referenzinstallation
     * 7813 mit enabled=0) kann er die Kategorie ueberhaupt nicht waehlen.
     *
     * Vorgabe zur verbleibenden Luecke: „wenn er selbst beides genau trifft ...
     * dann bekommt er die stunden halt nicht." Das ist die richtige Richtung:
     * eine fehlende Buchung statt einer doppelten, die niemand mehr loeschen
     * kann.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private function fingerprint(string $uid, string $datum, string $kategorie, string $status, string $text): string
    {
        return implode('|', [$uid, $datum, $kategorie, $status, trim($text)]);
    }

    /** Vereinsflieger kodiert Sonderzeichen als HTML-Entities. */
    private static function decodeEntities(string $wert): string
    {
        return html_entity_decode($wert, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Arbeitszeiten, die noch nicht uebertragen wurden.
     *
     * @return list<object>
     */
    private function pending(): array
    {
        /*
         * Ueber den Query Builder statt ueber das Model der Arbeitskarten: Das
         * Modul kann abgeschaltet sein, und dann gibt es die Klasse zwar, aber
         * ihr Gebrauch waere eine Abhaengigkeit, die die Modulgrenze nicht
         * hergibt. Die Tabellen bleiben immer bestehen (D1).
         */
        return DB::table('task_card_times as t')
            ->join('task_cards as c', 'c.id', '=', 't.task_card_id')
            ->join('work_orders as w', 'w.id', '=', 'c.work_order_id')
            ->leftJoin('aircraft as a', 'a.id', '=', 'w.aircraft_id')
            ->leftJoin('vereinsflieger_work_hour_transfers as v', 'v.task_card_time_id', '=', 't.id')

            /*
             * NOCH NIE VERSUCHT ODER NOCH NICHT ANGEKOMMEN.
             *
             * Vorgabe: „wenn was fehlt wiederholen. max 3 versuche." Eine Zeile
             * ohne bestaetigte Nummer ist ein offener Fall -- solange die
             * Obergrenze nicht erreicht ist.
             */
            ->where(function ($q): void {
                $q->whereNull('v.id')
                    ->orWhere(function ($q): void {
                        $q->whereNull('v.whid')
                            ->orWhere('v.whid', '');
                    });
            })
            ->where(function ($q): void {
                $q->whereNull('v.attempts')
                    ->orWhere('v.attempts', '<', WorkHourTransfer::MAX_ATTEMPTS);
            })

            // Die Karte kann weich geloescht sein -- die Zeit selbst nicht,
            // die Tabelle kennt keine Loeschung.
            ->whereNull('c.deleted_at')
            ->select([
                't.id',
                't.user_id',
                't.minutes',
                't.worked_on',
                't.note',
                'c.title as card_title',
                'w.number as work_order',
                'a.registration',
                'v.id as transfer_id',
                'v.attempts',
            ])
            ->orderBy('t.id')
            ->get()
            ->all();
    }

    /**
     * Die Vereinsflieger-Kennung eines Aeronance-Kontos.
     */
    private function uidFor(int $userId): ?string
    {
        $subject = ExternalIdentity::query()
            ->where('provider', 'vereinsflieger')
            ->where('user_id', $userId)
            ->value('subject');

        return $subject !== null ? (string) $subject : null;
    }

    /**
     * Was gesendet wird.
     *
     * @return array<string, string>
     */
    private function payload(object $zeit, string $uid): array
    {
        return [
            'uid' => $uid,
            'jobdate' => (string) $zeit->worked_on,
            'jobtext' => self::jobText($zeit),
            'hours' => self::asHoursMinutes((int) $zeit->minutes),
            'category' => (string) config('aeronance.vereinsflieger.workhours.category', ''),

            /*
             * Der Status kommt aus der Einstellung. Gemessen: Vereinsflieger
             * uebernimmt ihn beim Anlegen. „2" heisst akzeptiert und macht den
             * Eintrag drueben unveraenderlich -- was der Punkt daran ist.
             */
            'status' => (string) config('aeronance.vereinsflieger.workhours.status', '1'),
        ];
    }

    /**
     * „Kennzeichen | Workorder | Tätigkeit (kurz)" -- die Vorgabe.
     *
     * Fehlt ein Teil, faellt er weg statt als leere Stelle dazustehen: „D-KEWW
     * | | Ölwechsel" liest sich wie ein Fehler, „D-KEWW | Ölwechsel" nicht.
     */
    public static function jobText(object $zeit): string
    {
        $teile = array_values(array_filter([
            trim((string) ($zeit->registration ?? '')),
            trim((string) ($zeit->work_order ?? '')),
            trim((string) ($zeit->card_title ?? '')),
        ], static fn (string $s): bool => $s !== ''));

        $text = implode(' | ', $teile);

        // Die Spalte drueben ist begrenzt, und ein abgeschnittener Text mitten
        // im Wort ist schlechter zu lesen als einer mit Auslassung.
        return mb_strlen($text) > 100 ? mb_substr($text, 0, 97).'...' : $text;
    }

    /**
     * Minuten nach „HH:MM".
     *
     * Gemessen an der Antwort von workhours/list/daterange: Vereinsflieger
     * fuehrt Stunden als „03:00", nicht als 3.0. Aeronance rechnet intern in
     * Minuten, weil jeder „1:45" schreibt und niemand 1,75.
     */
    public static function asHoursMinutes(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
