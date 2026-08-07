<?php

declare(strict_types=1);

namespace App\Modules\Vereinsflieger\Actions;

use App\Core\Identity\ExternalIdentity;
use App\Core\Identity\LinkExternalIdentity;
use App\Models\User;
use App\Modules\Vereinsflieger\Models\Connection;
use App\Modules\Vereinsflieger\VereinsfliegerProvider;
use RuntimeException;

/**
 * Konten anlegen und stilllegen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe zu F38: „wer fehlt ist weg."
 *
 * Damit ist die offene Frage entschieden. `memberend` taugt nicht (leer bei
 * allen 394), `memberstatus` auch nicht (229 stehen auf „sonstige") -- das
 * Merkmal ist die Anwesenheit in der Liste selbst.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WEG HEISST DEAKTIVIERT, NICHT GELOESCHT.
 *
 * Regel 3 der Naht, und sie gilt hier: Ein geloeschtes Konto reisst Loecher in
 * die Nachweiskette. Wer vor drei Jahren an einem Flugzeug gearbeitet hat,
 * steht in Arbeitskarten und Freigaben, und dieser Name muss auf ein Konto
 * zeigen koennen. Der Zugang ist weg, die Vergangenheit bleibt lesbar.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE EINE SICHERUNG, DIE BLEIBT: EINE LEERE LISTE DEAKTIVIERT NIEMANDEN.
 *
 * Das ist keine zweite Meinung zu die Entscheidung, sondern die Abgrenzung
 * gegen einen Zustand, der gar keine Aussage ist. Kommt aus user/list nichts
 * zurueck, hat der Verein nicht ueber Nacht alle Mitglieder verloren -- da ist
 * etwas kaputt. Eine Stoerung als Massenaustritt zu lesen waere der teuerste
 * denkbare Irrtum, und er kostet nichts, ihn auszuschliessen.
 *
 * Weiter gehende Plausibilitaetsgrenzen gibt es NICHT. Ich hatte welche
 * vorgeschlagen, mit dem Timeout als Begruendung -- der wirft aber eine
 * Ausnahme, und dann kommt hier gar keine Liste an. Eine Regel gegen einen Fall,
 * den es nicht gibt, waere Ballast.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SyncMembers
{
    /**
     * @return array{created: int, updated: int, deactivated: int}
     */
    public function handle(Connection $connection, ?VereinsfliegerProvider $provider = null): array
    {
        if (! $connection->provides_identities) {
            // Eine Anbindung ohne diesen Haken liefert nur Betriebszeiten.
            // Niemand bekommt ein Konto -- siehe ConnectionResource.
            return ['created' => 0, 'updated' => 0, 'deactivated' => 0];
        }

        /*
         * DEN PROVIDER DURCHREICHEN, nicht neu bauen.
         *
         * Der Provider haelt die Mitgliederliste fuer die Dauer seines Lebens
         * -- ein zweites Objekt bedeutet einen zweiten Abruf derselben Daten
         * gegen einen mengenbegrenzten Dienst. Genau das ist beim Bau passiert
         * und nur aufgefallen, weil ein Test die Aufrufe zaehlt.
         */
        $provider ??= new VereinsfliegerProvider($connection);
        $verknuepfen = app(LinkExternalIdentity::class);

        $angelegt = 0;
        $gepflegt = 0;
        $gesehen = [];

        foreach ($provider->members() as $subjekt) {
            $ergebnis = $verknuepfen->handle($provider->name(), $subjekt);

            $gesehen[] = $subjekt->id;
            $ergebnis['created'] ? $angelegt++ : $gepflegt++;
        }

        if ($gesehen === []) {
            /*
             * Siehe Kopf: Das ist keine Aussage ueber Mitglieder, sondern ueber
             * die Verbindung. Als Ausnahme, damit es im naechtlichen Lauf an der
             * Anbindung steht und nicht stillschweigend als "0 gemacht" durchgeht.
             */
            throw new RuntimeException(
                'Vereinsflieger lieferte keine Mitglieder. Das ist keine Grundlage für '
                .'einen Abgleich -- es wurde niemand deaktiviert.'
            );
        }

        return [
            'created' => $angelegt,
            'updated' => $gepflegt,
            'deactivated' => $this->deactivateMissing($provider->name(), $gesehen),
        ];
    }

    /**
     * Wer nicht mehr in der Liste steht, verliert den Zugang.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * NUR KONTEN DIESES PROVIDERS. Der lokale Break-glass-Admin und jeder von
     * Hand angelegte Zugang stehen in keiner externen Liste -- sie hier
     * mitzuzaehlen hiesse, sich mit dem ersten Abgleich selbst auszusperren.
     *
     * Die ExternalIdentity BLEIBT stehen. Sie ist die Antwort auf „wer war
     * das drueben" und wird gebraucht, wenn derselbe Mensch spaeter
     * wiederkommt: Dann findet ihn der Abgleich ueber seine Kennung wieder --
     * mit seiner Vergangenheit statt mit einem zweiten, leeren Konto.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @param  list<string>  $gesehen
     */
    private function deactivateMissing(string $provider, array $gesehen): int
    {
        $verschwunden = ExternalIdentity::query()
            ->where('provider', $provider)
            ->whereNotIn('subject', $gesehen)
            ->pluck('user_id');

        if ($verschwunden->isEmpty()) {
            return 0;
        }

        return User::query()
            ->whereIn('id', $verschwunden)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }
}
