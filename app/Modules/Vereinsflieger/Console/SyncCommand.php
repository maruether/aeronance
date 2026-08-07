<?php

declare(strict_types=1);

namespace App\Modules\Vereinsflieger\Console;

use App\Core\Identity\RememberExternalGroups;
use App\Core\Modules\ModuleManager;
use App\Modules\Vereinsflieger\Actions\ReadAircraftTimes;
use App\Modules\Vereinsflieger\Actions\SyncMembers;
use App\Modules\Vereinsflieger\Actions\TransferWorkHours;
use App\Modules\Vereinsflieger\Models\Connection;
use App\Modules\Vereinsflieger\Models\MemberStatus;
use App\Modules\Vereinsflieger\VereinsfliegerProvider;
use Illuminate\Console\Command;
use Throwable;

/**
 * Der eine Abruf am Tag -- ueber alle Anbindungen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „Der VF abruf findet bitte genau einmal am tag um 2 uhr morgens
 * statt." Und zu den Luftfahrzeugen: „das sollte auch nachts um 2, NACH den
 * mitgliedern gemacht werden."
 *
 * DIE REIHENFOLGE IST ALSO VORGEGEBEN und hat einen Grund: Wer nachts neu
 * dazukommt, soll sein Konto haben, bevor Arbeitsstunden fuer ihn gebucht
 * werden -- sonst faellt er durch, weil es zu seiner Kennung noch keine
 * Zuordnung gibt.
 *
 *   1. Mitglieder, Gruppen, Status   (nur die Identitaets-Anbindung)
 *   2. Betriebszeiten der Luftfahrzeuge   (JEDE aktive Anbindung)
 *   3. Arbeitsstunden hinueber            (nur die Identitaets-Anbindung)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * EINE SITZUNG JE ANBINDUNG. Anmelden kostet zwei Anfragen, und ein Verein
 * bleibt ein Verein -- deshalb wird pro Anbindung genau einmal angemeldet und
 * alles darin erledigt, was diese Anbindung betrifft.
 *
 * Was sich NICHT sparen laesst: Die Zeiten kommen je Luftfahrzeug einzeln, weil
 * der Endpunkt genau ein Kennzeichen nimmt. Bei zehn Maschinen zehn Anfragen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * EINE SCHEITERNDE ANBINDUNG BEENDET DEN LAUF NICHT. Wenn der eine Verein sein
 * Passwort aendert, sollen die anderen trotzdem ihre Zeiten bekommen. Der
 * Fehler steht an der Anbindung und ist dort zu sehen -- nicht nur im Log.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SyncCommand extends Command
{
    protected $signature = 'aeronance:vereinsflieger-sync';

    protected $description = 'Holt Mitglieder und Betriebszeiten aus Vereinsflieger und schreibt Arbeitsstunden -- einmal taeglich.';

    public function handle(): int
    {
        /*
         * Ein abgeschaltetes Modul schweigt. Der Eintrag im Zeitplan steht
         * fest; ob er etwas tut, entscheidet die Modulverwaltung -- sonst
         * bricht jede Installation ohne Vereinsflieger jede Nacht mit einem
         * Fehler ab, den niemand lesen will.
         */
        if (! app(ModuleManager::class)->isEnabled('vereinsflieger')) {
            $this->components->info('Das Vereinsflieger-Modul ist nicht aktiv -- nichts zu tun.');

            return self::SUCCESS;
        }

        $anbindungen = Connection::query()->active()->orderBy('name')->get();

        if ($anbindungen->isEmpty()) {
            // Keine Anbindung eingerichtet ist kein Fehler, sondern ein Modul,
            // das noch niemand konfiguriert hat.
            $this->components->info('Keine aktive Vereinsflieger-Anbindung -- nichts zu tun.');

            return self::SUCCESS;
        }

        $gescheitert = 0;

        foreach ($anbindungen as $anbindung) {
            $this->components->info(sprintf('Anbindung "%s"', $anbindung->name));

            try {
                $this->runFor($anbindung);
                $anbindung->recordRun(null);
            } catch (Throwable $e) {
                $gescheitert++;
                $anbindung->recordRun(mb_substr($e->getMessage(), 0, 500));

                // Die Begruendung des Dienstes wird durchgereicht. Ein
                // "Abruf fehlgeschlagen" ohne Grund hat in diesem Projekt schon
                // zwei Anmeldungen gekostet.
                $this->components->error($e->getMessage());
            }
        }

        $this->reportUndecided();

        /*
         * Fehlschlag nur, wenn ALLE scheitern. Eine von fuenf Anbindungen mit
         * abgelaufenem Passwort ist ein Hinweis, kein Grund, den naechtlichen
         * Lauf als kaputt zu melden -- sonst gewoehnt man sich an rote Mails.
         */
        return $gescheitert === $anbindungen->count() ? self::FAILURE : self::SUCCESS;
    }

    private function runFor(Connection $anbindung): void
    {
        $provider = new VereinsfliegerProvider($anbindung);

        if ($anbindung->provides_identities) {
            /*
             * groups() zieht die Mitgliederliste und frischt dabei die
             * Statusliste mit auf -- beides aus DERSELBEN Antwort, siehe
             * rawMembers(). Ein eigener Aufruf fuer die Status waere ein
             * zweiter Abruf fuer Daten, die schon da sind.
             */
            $gruppen = app(RememberExternalGroups::class)
                ->handle($provider->name(), $provider->groups());

            $this->components->twoColumnDetail(
                '  Gruppen',
                sprintf('%d gefunden, %d neu', $gruppen['seen'], $gruppen['new']),
            );

            /*
             * Der Abgleich selbst -- und er kommt NACH den Gruppen, weil er
             * dieselbe Mitgliederliste benutzt. Der Provider haelt sie fuer die
             * Dauer des Laufs; ein zweiter Abruf faende dieselben Daten.
             */
            $konten = app(SyncMembers::class)->handle($anbindung, $provider);

            $this->components->twoColumnDetail(
                '  Konten',
                sprintf('%d angelegt, %d gepflegt, %d deaktiviert',
                    $konten['created'], $konten['updated'], $konten['deactivated']),
            );

            if ($konten['deactivated'] > 0) {
                // Als Warnung: Wer den Zugang verliert, merkt es am Samstag vor
                // dem Hangar -- das soll vorher jemand gesehen haben.
                $this->components->warn(sprintf(
                    '%d Konten deaktiviert (nicht mehr in Vereinsflieger).',
                    $konten['deactivated'],
                ));
            }
        }

        $zeiten = app(ReadAircraftTimes::class)->handle($anbindung);

        if ($zeiten !== ['read' => 0, 'failed' => 0, 'skipped' => 0]) {
            $this->components->twoColumnDetail(
                '  Betriebszeiten',
                sprintf('%d gelesen, %d ohne Luftfahrzeug, %d fehlgeschlagen',
                    $zeiten['read'], $zeiten['skipped'], $zeiten['failed']),
            );
        }

        if (! $anbindung->provides_identities) {
            return;
        }

        $stunden = app(TransferWorkHours::class)->handle($anbindung);

        if ($stunden !== ['sent' => 0, 'failed' => 0, 'skipped' => 0]) {
            $this->components->twoColumnDetail(
                '  Arbeitsstunden',
                sprintf('%d gebucht, %d ohne VF-Kennung, %d fehlgeschlagen',
                    $stunden['sent'], $stunden['skipped'], $stunden['failed']),
            );
        }
    }

    /**
     * Offene Statusentscheidungen laut sagen.
     *
     * Als WARNUNG und nicht als Zeile am Rand: Hinter jedem offenen Status
     * stehen Menschen, die kein Konto bekommen.
     */
    private function reportUndecided(): void
    {
        $offen = MemberStatus::query()->whereNull('handling')->count();

        if ($offen > 0) {
            $this->components->warn(sprintf(
                '%d Mitgliedsstatus ohne Entscheidung -- diese Menschen bekommen kein Konto. '
                .'Zu entscheiden unter "VF-Mitgliedsstatus".',
                $offen,
            ));
        }
    }
}
