<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Shelf life is arithmetic, so it runs by itself.
 *
 * The command checks whether the warehouse module is enabled before it does
 * anything, so this line is harmless in an installation that has it switched
 * off. Early, before anyone is at the shelf: a lot that expired overnight
 * should already read "unserviceable" by the time the first person looks at it.
 */
Schedule::command('aeronance:expire-stock')->dailyAt('04:00');

/*
 * Abgelaufene Notzugaenge einloesen -- alle fuenf Minuten, denn `--hours`
 * ist ein Versprechen und war eine Zeit lang nur eine Notiz im Datensatz.
 * Bei einer Vorgabe von vier Stunden ist fuenf Minuten Aufloesung genau
 * genug; der Handweg (break-glass-revoke) bleibt davon unberuehrt.
 */
Schedule::command('aeronance:break-glass-expire')->everyFiveMinutes();

/*
 * Erinnerung an ueberfaellige Lieferungen -- der Zweck des Bestellteils.
 *
 * Um 07:30, also zum Arbeitsbeginn und nicht mitten in der Nacht: Eine
 * Erinnerung, die um vier Uhr morgens eintrifft, steht beim Aufwachen unter
 * zwanzig anderen Mails. Sie erinnert nicht taeglich an dasselbe, siehe den
 * Befehl.
 */
Schedule::command('aeronance:remind-orders')
    ->dailyAt('07:30')
    ->onOneServer();

/*
 * The manufacturers' LTA/TM lists, weekly.
 *
 * Weekly rather than daily because that is the pace at which manufacturers
 * publish, and because every run is a request to somebody else's server. Sunday
 * night means a new directive is waiting on Monday, which is when clubs work.
 *
 * The run only collects. Nothing it brings in is assessed, and an unassessed
 * directive blocks the release until a qualified person has looked at it -- so
 * the worst case of an over-eager fetch is a to-do, never a wrong answer.
 */
Schedule::command('aeronance:refresh-directives')
    ->weeklyOn(0, '03:00')
    ->withoutOverlapping();

/*
 * Die Aufbewahrungsregeln, taeglich.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER BEFEHL GAB ES SCHON, GEPLANT WAR ER NIE. Ein Verein konnte eine Regel in
 * config/aeronance.php einschalten, und es passierte nichts -- ohne Fehler, ohne
 * Hinweis. Eine Einstellung, die nichts bewirkt, ist schlimmer als keine: sie
 * sieht aus wie eine Zusage.
 *
 * Alle drei Aufgaben sind standardmaessig AUS. Diese Zeile loescht in einer
 * frischen Installation also nichts; sie sorgt nur dafuer, dass ein
 * eingeschalteter Schalter auch greift. Der Lauf sagt bei jeder abgeschalteten
 * Klasse "disabled" statt stillzuschweigen.
 *
 * TAEGLICH und nicht woechentlich, wegen der Pseudonymisierung: 28 Tage nach dem
 * Austritt gemeint, bei einem Wochenlauf bis zu 35 -- und das ist die eine der
 * drei Aufgaben, bei der Verspaetung jemanden betrifft und nicht nur Speicher
 * kostet. Um 04:30, nach dem Verfallslauf des Lagers.
 * ─────────────────────────────────────────────────────────────────────────────
 */
Schedule::command('aeronance:retention')
    ->dailyAt('04:30')
    ->withoutOverlapping();

/*
 * Die Sicherung, taeglich.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER BEFEHL LIEF BISHER NUR BEIM UPDATE. update.sh ruft ihn auf, bevor es
 * etwas anfasst -- das ist richtig und war alles. Zwischen zwei Updates, und
 * die koennen Monate auseinanderliegen, entstand keine einzige Sicherung.
 * CLAUDE.md verlangt aber "automatisierte Backups", und eine, die nur bei einer
 * Handlung entsteht, ist nicht automatisch.
 *
 * NACH DER AUFBEWAHRUNG (04:30), nicht davor: Was die Aufbewahrungsregeln
 * loeschen, soll nicht kurz vorher noch in eine Sicherung wandern und dort drei
 * Jahre ueberleben -- das waere die Loeschfrist durch die Hintertuer wieder
 * aufgehoben.
 *
 * withoutOverlapping, weil eine Vereinssicherung samt Auslagerung laenger
 * dauern kann als der Abstand zum naechsten Lauf, wenn die Leitung schlecht ist.
 * ─────────────────────────────────────────────────────────────────────────────
 */
Schedule::command('aeronance:backup')
    ->dailyAt('05:00')
    ->withoutOverlapping();

/*
 * Vereinsflieger, taeglich um 02:00.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „Der VF abruf findet bitte genau einmal am tag um 2 uhr morgens
 * statt."
 *
 * Vorher gab es gar keinen Zeitplan -- geholt wurde nur, wenn jemand zufaellig
 * auf die richtige Seite ging und einen Knopf drueckte. Das ist keine Regel,
 * sondern ein Zufall, und bei einem mengenbegrenzten Dienst ein schlechter.
 *
 * 02:00 und damit VOR allen anderen Laeufen (Lager 04:00, Aufbewahrung 04:30,
 * Sicherung 05:00): Wer nachts dazukommt oder wegfaellt, steht damit schon in
 * der Sicherung des gleichen Morgens.
 *
 * withoutOverlapping, weil ein Verein mit vielen Mitgliedern lange braucht --
 * gemessen: 394 Mitglieder passten nicht in 30 Sekunden, der Client wartet
 * jetzt bis zu 180.
 *
 * Ein abgeschaltetes Modul und ein fehlender Zugang sind KEIN Fehler: Der
 * Befehl sagt das und geht. Sonst braechte diese Zeile jeder Installation ohne
 * Vereinsflieger jede Nacht einen Fehlschlag, den niemand lesen will.
 * ─────────────────────────────────────────────────────────────────────────────
 */
Schedule::command('aeronance:vereinsflieger-sync')
    ->dailyAt('02:00')
    ->withoutOverlapping();

/*
 * Einmal am Tag nachsehen, ob es eine neuere Fassung gibt.
 *
 * Nicht, weil sich das oft aendert -- sondern damit die Antwort schon
 * zwischengespeichert ist, wenn jemand die Oberflaeche oeffnet. Ohne den
 * geplanten Lauf zahlt der erste Besucher des Tages die Wartezeit auf GitHub,
 * und wenn GitHub gerade nicht antwortet, sieht er gar nichts.
 *
 * Um 03:40, also vor der Sicherung und nach der Nacht. Ein Fehlschlag ist kein
 * Fehler (siehe ReleaseCheck), der Lauf meldet dann schlicht nichts.
 */
Schedule::command('aeronance:update-check')
    ->dailyAt('03:40')
    ->onOneServer();
