<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Actions\CertifyTaskCard;
use App\Modules\TaskCards\Actions\InspectCriticalTask;
use App\Modules\TaskCards\Actions\IssueRelease;
use App\Modules\TaskCards\Actions\ManageFindingReport;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Actions\RecordFinding;
use App\Modules\TaskCards\Enums\ActivityKind;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Zwei Vorgänge: einer fertig, einer offen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER FERTIGE ist der Rundgang durchs ganze Modul: Befundbericht mit drei
 * Punkten, je eine Arbeitskarte, eine davon kritisch und deshalb unabhängig
 * kontrolliert, Arbeitszeiten, Fremdkörperkontrolle, Abzeichnung und die
 * erteilte Freigabe. Wer die Demo öffnet, kann ihn ausdrucken und sieht, was
 * am Ende in der Akte liegt.
 *
 * DER OFFENE ist der Alltag: eine Karte in Arbeit und ein blockierender Befund,
 * der die Freigabe verhindert. Ohne ihn zeigte die Demo nur den Zustand, in dem
 * alles schon gut ist -- und die halbe Fachlogik dieses Moduls handelt davon,
 * dass es das nicht ist.
 *
 * Alles über die Aktionen: Nummern, Zustände und Unterschriften entstehen wie
 * im Betrieb. Schlägt etwas fehl, bricht der Seeder NICHT ab -- eine Demo ohne
 * zweiten Vorgang ist immer noch eine Demo, eine gescheiterte Installation
 * nicht.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class DemoWorkshopSeeder extends Seeder
{
    /** @param  array<string, User>  $konten */
    public function run(array $konten = []): void
    {
        $segelflugzeug = Aircraft::where('registration', 'D-1234')->first();
        $motorsegler = Aircraft::where('registration', 'D-KABC')->first();

        if ($segelflugzeug === null || $motorsegler === null) {
            return;
        }

        try {
            $this->finishedVisit($segelflugzeug, $konten);
        } catch (Throwable $e) {
            $this->command?->warn('Demo: der abgeschlossene Vorgang fehlt -- '.$e->getMessage());
        }

        try {
            $this->openVisit($motorsegler, $konten);
        } catch (Throwable $e) {
            $this->command?->warn('Demo: der offene Vorgang fehlt -- '.$e->getMessage());
        }
    }

    /** @param  array<string, User>  $konten */
    private function finishedVisit(Aircraft $lfz, array $konten): void
    {
        $leitung = $konten['werkstattleiter'];
        $mechanikerin = $konten['mechaniker'];
        $pruefer = $konten['freigabeberechtigter'];

        $vorgang = app(ManageWorkOrder::class)->open(
            aircraft: $lfz,
            title: 'Jahresnachprüfung '.now()->subMonths(9)->format('Y'),
            user: $leitung,
            description: 'Beispielvorgang der Demo: Jahresnachprüfung mit Befundbericht.',
            openedAt: now()->subMonths(9)->toDateString(),
        );

        $karten = app(ManageFindingReport::class)->record(
            order: $vorgang,
            points: [
                [
                    'title' => 'Riss in der Haube',
                    'description' => 'Vorne links, etwa 3 cm, vom Rahmen ausgehend.',
                ],
                [
                    'title' => 'Höhenruderanlenkung nachziehen',
                    'description' => 'Anschluss beim Ausbau gelöst — neu verschrauben und sichern.',
                    'critical' => true,
                    'critical_reason' => 'Anschluss Höhenruder, Sicherung prüfen',
                ],
                [
                    'title' => 'Hauptrad abgefahren',
                    'description' => 'Profil unter 1 mm, an der Verschleißgrenze.',
                ],
            ],
            user: $mechanikerin,
            foundOn: now()->subMonths(9)->toDateString(),
        );

        $arbeit = [
            'Haube ausgebaut, Riss gestoppt und laminiert, lackiert.',
            'Anlenkung montiert, mit Splint gesichert, Funktion und Ausschläge geprüft.',
            'Reifen und Schlauch erneuert, Rad gewuchtet und montiert.',
        ];

        foreach ($karten as $i => $karte) {
            app(CertifyTaskCard::class)->complete(
                card: $karte,
                user: $mechanikerin,
                workPerformed: $arbeit[$i] ?? 'Erledigt.',
                minutes: [95, 60, 75][$i] ?? 60,
            );

            // Die kritische Karte braucht das zweite Augenpaar -- und der
            // Prüfer ist der, der sie NICHT gemacht hat.
            if ($karte->fresh()->critical) {
                app(InspectCriticalTask::class)->handle(
                    card: $karte->fresh(),
                    user: $pruefer,
                    note: 'Sicherung und Ausschläge nachgeprüft, Splint gesetzt.',
                );
            }
        }

        app(ManageFindingReport::class)->confirmForeignObjectCheck($vorgang->fresh(), $mechanikerin);

        /*
         * Freigegeben wird OHNE die Karten einzeln abzuzeichnen: Die Freigabe
         * zeichnet fertiggemeldete Karten mit, und genau das soll die Demo
         * zeigen -- eine Unterschrift, nicht vier.
         */
        app(IssueRelease::class)->handle(
            order: $vorgang->fresh(),
            user: $pruefer,
            maintenanceData: 'Wartungshandbuch ASK 21, Ausgabe 2019, Abschnitt 4',
            statement: null,
            releasedAt: now()->subMonths(9)->addDays(2)->toDateString(),
        );
    }

    /** @param  array<string, User>  $konten */
    private function openVisit(Aircraft $lfz, array $konten): void
    {
        $leitung = $konten['werkstattleiter'];
        $mechanikerin = $konten['mechaniker'];

        $vorgang = app(ManageWorkOrder::class)->open(
            aircraft: $lfz,
            title: '100-Stunden-Kontrolle',
            user: $leitung,
            description: 'Beispielvorgang der Demo: läuft gerade.',
            openedAt: now()->subDays(6)->toDateString(),
        );

        app(ManageWorkOrder::class)->addCard(
            order: $vorgang,
            title: 'Ölwechsel und Filter',
            instruction: 'Öl ablassen, Filter tauschen, Sieb kontrollieren, Probelauf.',
            kind: ActivityKind::Maintenance,
            ataChapter: '79',
        );

        /*
         * Ein blockierender Befund OHNE Karte: Er steht der Freigabe im Weg,
         * und wer die Demo ausprobiert, bekommt die Sperre zu sehen, statt sie
         * beschrieben zu bekommen.
         */
        app(RecordFinding::class)->record(
            aircraft: $lfz,
            title: 'Ölspur am Getriebeflansch',
            description: 'Feuchte Stelle unterhalb des Flansches, Ursache noch offen.',
            user: $mechanikerin,
            isBlocking: true,
            foundOn: now()->subDays(2)->toDateString(),
        );
    }
}
