<?php

declare(strict_types=1);

namespace Tests\Feature\TaskCards;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Actions\ManageFindingReport;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Actions\RecordFinding;
use App\Modules\TaskCards\Enums\FindingState;
use App\Modules\TaskCards\Models\Finding;
use App\Modules\TaskCards\Models\TaskCard;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Der Befundbericht eines Vorgangs.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „Einem Vorgang sollte immer ein befundbericht zugeordnet sein, nach
 * dem neu anlegen eines vorgangs sollte ein befundbericht angelegt werden
 * können innerhalb des vorgangs, wobei jeder punkt zu einer arbeitskarte wird.
 * Außerdem Sollte der Befundbericht nach dem Schema ‚Laufende Nummer - Befund -
 * Behebung - Ausgeführt durch - Geprüft durch - freigegeben durch' aufgebaut
 * sein."
 *
 * Zwei Dinge halten diese Tests fest, und beide sind Entscheidungen:
 *
 *  1. EINE KARTE JE PUNKT -- anders als bei der Sammelaktion an der
 *     Befundliste, die aus mehreren Befunden EINE Karte macht. Auf dem Blatt
 *     trägt jede Zeile ihre eigenen drei Unterschriften.
 *
 *  2. DER BERICHT IST KEIN ZWEITER DATENSATZ. Seine Zeilen sind die Befunde des
 *     Vorgangs, die Unterschriftsspalten stehen an der Karte. Damit hat jeder
 *     Vorgang seinen Bericht, ohne dass ihn jemand anlegt -- und das Blatt kann
 *     nicht etwas anderes sagen als die Akte darunter.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class FindingReportSheetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();
        app(ModuleManager::class)->enable('fleet');
        app(ModuleManager::class)->enable('taskcards');
        app(ModuleManager::class)->forgetCache();
    }

    #[Test]
    public function every_point_becomes_its_own_finding_and_its_own_card(): void
    {
        $order = $this->workOrder();

        $cards = app(ManageFindingReport::class)->record(
            order: $order,
            points: [
                ['title' => 'Riss in der Haube', 'description' => 'Vorne links, etwa 3 cm.'],
                ['title' => 'Reifen abgefahren', 'description' => 'Hauptrad, an der Verschleißgrenze.'],
                ['title' => 'Bowdenzug schwergängig', 'description' => 'Bremsklappe rechts.'],
            ],
            user: $this->worker(),
        );

        $this->assertCount(3, $cards);
        $this->assertSame(3, Finding::query()->count());
        $this->assertSame(3, TaskCard::query()->count());

        // Jede Karte gehört zu genau einem Befund -- das ist der Unterschied
        // zur Sammelkarte, und er trägt die Unterschriftsspalten des Blatts.
        foreach (Finding::query()->get() as $befund) {
            $this->assertSame(FindingState::Scheduled, $befund->state);
            $this->assertNotNull($befund->resolving_task_card_id);
        }

        $this->assertSame(
            3,
            Finding::query()->distinct()->count('resolving_task_card_id'),
            'Drei Punkte, drei Karten -- keine geteilte.',
        );
    }

    #[Test]
    public function a_critical_point_raises_a_critical_card(): void
    {
        /*
         * Die Markierung ist nur beim Anlegen zu setzen (TaskCard::booted).
         * Käme sie hier nicht durch, liesse sie sich für einen Befund nie mehr
         * setzen -- und die Spalte „Kontrolle" des Blatts bliebe für immer leer.
         */
        $order = $this->workOrder();

        $cards = app(ManageFindingReport::class)->record(
            order: $order,
            points: [[
                'title' => 'Steuerstange getauscht',
                'description' => 'Höhenruderanlenkung, Anschluss neu verschraubt.',
                'critical' => true,
                'critical_reason' => 'Anschluss Höhenruder, Sicherung prüfen',
            ]],
            user: $this->worker(),
        );

        $this->assertTrue($cards[0]->critical);
        $this->assertSame('Anschluss Höhenruder, Sicherung prüfen', $cards[0]->critical_reason);
    }

    #[Test]
    public function a_report_is_written_whole_or_not_at_all(): void
    {
        /*
         * Ein Bericht, von dem vier Punkte in der Datenbank stehen und der
         * fünfte an einer Pflichtangabe scheitert, wäre schlimmer als gar
         * keiner: Wer ihn neu abschickt, bekommt die ersten vier zweimal.
         */
        $order = $this->workOrder();

        try {
            app(ManageFindingReport::class)->record(
                order: $order,
                points: [
                    ['title' => 'Riss in der Haube', 'description' => 'Vorne links.'],
                    ['title' => 'Ohne Beschreibung', 'description' => '   '],
                ],
                user: $this->worker(),
            );

            $this->fail('Ein Punkt ohne Beschreibung muss den ganzen Bericht ablehnen.');
        } catch (InvalidArgumentException) {
            // erwartet
        }

        $this->assertSame(0, Finding::query()->count());
        $this->assertSame(0, TaskCard::query()->count());
    }

    #[Test]
    public function a_closed_visit_takes_no_report(): void
    {
        $order = $this->workOrder();
        $order->update(['state' => WorkOrder::STATE_CLOSED]);

        $this->expectException(RuntimeException::class);

        app(ManageFindingReport::class)->record(
            order: $order->fresh(),
            points: [['title' => 'Riss', 'description' => 'Vorne links.']],
            user: $this->worker(),
        );
    }

    #[Test]
    public function an_empty_report_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ManageFindingReport::class)->record(
            order: $this->workOrder(),
            points: [],
            user: $this->worker(),
        );
    }

    #[Test]
    public function the_sheet_reads_its_signatures_from_the_card(): void
    {
        $order = $this->workOrder();

        $cards = app(ManageFindingReport::class)->record(
            order: $order,
            points: [['title' => 'Riss in der Haube', 'description' => 'Vorne links, etwa 3 cm.']],
            user: $this->worker(),
        );

        // Was am Blatt in der Spalte „Erledigung" steht, steht an der Karte.
        $cards[0]->update([
            'work_performed' => 'Haube ausgebaut, Riss gestoppt und laminiert.',
            'completed_by_name' => 'Hilde Hobel',
            'completed_at' => now(),
        ]);

        $zeilen = app(ManageFindingReport::class)->points($order->fresh());

        $this->assertCount(1, $zeilen);
        $this->assertSame(1, $zeilen[0]['position']);
        $this->assertSame('Riss in der Haube', $zeilen[0]['finding']->title);
        $this->assertSame('Hilde Hobel', $zeilen[0]['card']->completed_by_name);
        $this->assertSame(
            'Haube ausgebaut, Riss gestoppt und laminiert.',
            $zeilen[0]['card']->work_performed,
        );
    }

    #[Test]
    public function a_finding_noticed_while_working_is_on_the_sheet_too(): void
    {
        /*
         * Ohne eigene Karte und trotzdem auf dem Blatt: Ein offener Befund
         * gehört auf den Bericht, GERADE weil er offen ist. Ein Blatt, das nur
         * das Erledigte zeigt, liest sich, als wäre alles gemacht.
         */
        $order = $this->workOrder();

        $cards = app(ManageFindingReport::class)->record(
            order: $order,
            points: [['title' => 'Reifen abgefahren', 'description' => 'Hauptrad.']],
            user: $this->worker(),
        );

        app(RecordFinding::class)->record(
            aircraft: $order->aircraft,
            title: 'Riss am Radkasten',
            description: 'Beim Ausbau des Rades gesehen.',
            user: $this->worker(),
            noticedOn: $cards[0],
        );

        $zeilen = app(ManageFindingReport::class)->points($order->fresh());

        $this->assertCount(2, $zeilen);
        $this->assertSame([1, 2], array_column($zeilen, 'position'));
        $this->assertNull(
            $zeilen[1]['card'],
            'Der nebenbei gefundene Befund hat noch keine Karte -- die Spalte bleibt leer.',
        );
    }

    #[Test]
    public function the_foreign_object_check_is_recorded_with_name_and_time(): void
    {
        $order = $this->workOrder();

        $this->assertFalse($order->foreignObjectCheckDone());

        $bestaetigt = app(ManageFindingReport::class)
            ->confirmForeignObjectCheck($order, $this->worker());

        $this->assertTrue($bestaetigt->foreignObjectCheckDone());
        $this->assertNotNull($bestaetigt->foreign_object_check_by_name);
    }

    #[Test]
    public function without_the_working_permission_nobody_confirms_the_check(): void
    {
        $this->expectException(RuntimeException::class);

        app(ManageFindingReport::class)->confirmForeignObjectCheck(
            $this->workOrder(),
            User::factory()->create(['is_active' => true]),
        );
    }

    #[Test]
    public function a_released_visit_refuses_the_check(): void
    {
        // Sie nachträglich einzutragen hiesse, eine erteilte Freigabe zu
        // ergänzen -- dieselbe Sperre wie überall nach der Unterschrift.
        $order = $this->workOrder();
        $order->update(['released_at' => now()]);

        $this->expectException(RuntimeException::class);

        app(ManageFindingReport::class)->confirmForeignObjectCheck($order->fresh(), $this->worker());
    }

    private function workOrder(): WorkOrder
    {
        $aircraft = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);

        return app(ManageWorkOrder::class)->open(
            $aircraft,
            'Jahresnachprüfung',
            $this->userWith(Permissions::WORK_ORDERS_MANAGE),
        );
    }

    /** Wer den Bericht aufnimmt: melden UND Karten schreiben dürfen. */
    private function worker(): User
    {
        return $this->userWith(Permissions::FINDINGS_RECORD, Permissions::CARDS_WORK);
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }
}
