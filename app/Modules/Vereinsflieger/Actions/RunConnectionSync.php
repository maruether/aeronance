<?php

declare(strict_types=1);

namespace App\Modules\Vereinsflieger\Actions;

use App\Core\Identity\RememberExternalGroups;
use App\Modules\Vereinsflieger\Models\Connection;
use App\Modules\Vereinsflieger\VereinsfliegerProvider;
use Throwable;

/**
 * Ein vollstaendiger Abgleich EINER Anbindung -- der eine Ablauf fuer alle Wege.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Herausgezogen aus dem Nachtlauf (SyncCommand), als der Knopf dazukam:
 * "das sollte alles auf der seite vf-anbindung mit dem einen button da
 * funktionieren." Vorher lebte der Ablauf als private Methode im Kommando --
 * ein Knopf haette ihn duplizieren muessen, und zwei Abgleiche driften.
 *
 * DIE REIHENFOLGE IST VORGEGEBEN und hat einen Grund: Wer nachts neu
 * dazukommt, soll sein Konto haben, bevor Arbeitsstunden fuer ihn gebucht
 * werden.
 *
 *   1. Mitglieder, Gruppen, Status      (nur die Identitaets-Anbindung)
 *   2. Arbeitsstunden-Kategorien        (nur die Identitaets-Anbindung)
 *   3. Betriebszeiten der Luftfahrzeuge (JEDE aktive Anbindung)
 *   4. Arbeitsstunden hinueber          (nur die Identitaets-Anbindung)
 *
 * Die Kategorien haengen mit drin, weil die Einstellungsseite sie als
 * Auswahlliste braucht ("kann nicht ohne auswahlliste nach der nummer der
 * kategorie gefragt werden") -- und ein eigener Abrufweg nur dafuer waere ein
 * zweiter Knopf fuer denselben Dienst.
 *
 * Fehlerbehandlung ist Sache des AUFRUFERS: Kommando und Job fangen, schreiben
 * recordRun() und entscheiden, was ein Fehlschlag bedeutet -- fuer den
 * Nachtlauf etwas anderes als fuer einen Knopfdruck.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class RunConnectionSync
{
    /**
     * @return array{
     *     groups: array{seen: int, new: int}|null,
     *     accounts: array{created: int, updated: int, deactivated: int}|null,
     *     categories: array{seen: int, new: int}|null,
     *     categories_error: string|null,
     *     times: array{read: int, failed: int, skipped: int},
     *     hours: array{sent: int, failed: int, skipped: int}|null,
     * }
     */
    public function handle(Connection $anbindung): array
    {
        $provider = new VereinsfliegerProvider($anbindung);

        $gruppen = null;
        $konten = null;
        $kategorien = null;
        $kategorienFehler = null;
        $stunden = null;

        if ($anbindung->provides_identities) {
            /*
             * groups() zieht die Mitgliederliste und frischt dabei die
             * Statusliste mit auf -- beides aus DERSELBEN Antwort, siehe
             * rawMembers(). Der Abgleich der Konten kommt danach, weil er
             * dieselbe Liste benutzt; der Provider haelt sie fuer die Dauer
             * des Laufs.
             */
            $gruppen = app(RememberExternalGroups::class)
                ->handle($provider->name(), $provider->groups());

            $konten = app(SyncMembers::class)->handle($anbindung, $provider);

            /*
             * Eigene Sitzung fuer die Kategorien: Der Provider kapselt seine
             * Sitzung um die Mitgliederliste, der Kategorien-Endpunkt liegt
             * daneben. Drei Anfragen am Tag -- das Kontingent verkraftet das,
             * und die Auswahlliste in den Einstellungen bleibt frisch.
             *
             * ─────────────────────────────────────────────────────────────────
             * UND DER SCHRITT DARF SCHEITERN, OHNE DEN LAUF ZU REISSEN.
             * Gemessen am 12.08. auf test.aeronance.de: Die Kategorien-Tabelle
             * fehlte (Update ohne Migration), die Ausnahme flog bis zum
             * Aufrufer -- und die BETRIEBSZEITEN, der eigentliche Zweck des
             * Laufs, wurden zwei Naechte lang nie gelesen. Eine Auswahlliste
             * ist Komfort; sie hat nicht das Recht, die Pflicht zu verhindern.
             * Der Fehler steht im Bericht und wird vom Aufrufer angesagt.
             * ─────────────────────────────────────────────────────────────────
             */
            try {
                $client = $anbindung->client();

                if ($client->signIn()) {
                    try {
                        $kategorien = app(RememberWorkHourCategories::class)
                            ->handle($client->workHourCategories());
                    } finally {
                        $client->signOut();
                    }
                }
            } catch (Throwable $e) {
                $kategorienFehler = mb_substr($e->getMessage(), 0, 300);
            }
        }

        $zeiten = app(ReadAircraftTimes::class)->handle($anbindung);

        if ($anbindung->provides_identities) {
            $stunden = app(TransferWorkHours::class)->handle($anbindung);
        }

        return [
            'groups' => $gruppen,
            'accounts' => $konten,
            'categories' => $kategorien,
            'categories_error' => $kategorienFehler,
            'times' => $zeiten,
            'hours' => $stunden,
        ];
    }
}
