<?php

declare(strict_types=1);

namespace Tests\Feature\Inspection;

use App\Core\Access\AccessSetup;
use App\Core\Models\Qualification;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Inspection\Actions\CompleteIncomingInspection;
use App\Modules\Inspection\Enums\CheckItem;
use App\Modules\Inspection\Enums\CheckResult;
use App\Modules\Inspection\Enums\InspectionState;
use App\Modules\Inspection\Models\IncomingInspection;
use App\Modules\Inspection\Permissions;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockMovement;
use App\Modules\Warehouse\Permissions as WarehousePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Die Eingangsprüfung.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE ZWEI TESTS, UM DIE ES GEHT:
 *
 *   `an_arrival_is_held_until_it_has_been_looked_at` — der Nachweis allein
 *   nützt dem Flugzeug nichts. Was ein ungeprüftes Teil von der Tragfläche
 *   fernhält, ist die Sperre.
 *
 *   `the_warehouse_is_untouched_when_the_module_is_off` — ein Modul, das sich
 *   nicht folgenlos abschalten lässt, ist kein Modul.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class IncomingInspectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();

        app(ModuleManager::class)->enable('warehouse');
        app(ModuleManager::class)->enable('inspection');
        app(ModuleManager::class)->forgetCache();
    }

    /**
     * DER TEST, UM DEN ES GEHT: Angelieferte Ware ist nicht sofort verfügbar.
     */
    #[Test]
    public function an_arrival_is_held_until_it_has_been_looked_at(): void
    {
        $bewegung = $this->receive();

        $los = $bewegung->lot;

        $this->assertNotNull($los);
        $this->assertSame(LotState::Quarantined, $los->fresh()->state);
        $this->assertFalse($los->fresh()->state->allowsIssue(), 'Ungeprüfte Ware darf nicht ausgegeben werden.');
    }

    #[Test]
    public function an_arrival_opens_a_checklist(): void
    {
        $bewegung = $this->receive();

        $pruefung = IncomingInspection::query()->where('stock_movement_id', $bewegung->id)->first();

        $this->assertNotNull($pruefung);
        $this->assertSame(InspectionState::Open, $pruefung->state);
        $this->assertFalse($pruefung->isAnswered());

        $punkte = $pruefung->checks->pluck('item')->all();

        // Ein Bauteil mit Form 1 und Seriennummer: die volle Liste.
        $this->assertContains(CheckItem::Certificate, $punkte);
        $this->assertContains(CheckItem::Issuer, $punkte);
        $this->assertContains(CheckItem::Identification, $punkte);
    }

    /**
     * Normteile werden nicht nach einer Form 1 gefragt.
     *
     * Wer bei jeder Tüte Nieten „entfällt" anklickt, klickt es irgendwann auch
     * dort an, wo es zählt.
     */
    #[Test]
    public function a_bag_of_rivets_is_not_asked_for_a_form_one(): void
    {
        $normteil = PartType::create([
            'name' => 'Niete',
            'classification' => PartClassification::StandardPart,
            'unit' => 'Stk',
        ]);

        $bewegung = app(ReceiveStock::class)->handle($normteil, 100, now()->toDateString());

        $pruefung = IncomingInspection::query()->where('stock_movement_id', $bewegung->id)->firstOrFail();
        $punkte = $pruefung->checks->pluck('item')->all();

        $this->assertNotContains(CheckItem::Certificate, $punkte);
        $this->assertContains(CheckItem::PartNumber, $punkte);
        $this->assertContains(CheckItem::Condition, $punkte);

        // Und ohne Los gibt es nichts zu sperren -- die Prüfung ist hier ein
        // Nachweis, keine Sperre. Steht so auch im Modul.
        $this->assertNull($pruefung->stock_lot_id);
    }

    /**
     * DER ZWEITE TEST, UM DEN ES GEHT: abgeschaltet heißt abgeschaltet.
     */
    #[Test]
    public function the_warehouse_is_untouched_when_the_module_is_off(): void
    {
        app(ModuleManager::class)->disable('inspection');
        app(ModuleManager::class)->forgetCache();

        $bewegung = $this->receive();

        $this->assertSame(
            LotState::Serviceable,
            $bewegung->lot->fresh()->state,
            'Ohne Eingangsprüfung muss der Wareneingang genau so laufen wie vorher.',
        );
        $this->assertSame(0, IncomingInspection::query()->count());
    }

    #[Test]
    public function a_signed_inspection_releases_the_goods(): void
    {
        $bewegung = $this->receive();
        $pruefung = IncomingInspection::query()->where('stock_movement_id', $bewegung->id)->firstOrFail();

        app(CompleteIncomingInspection::class)->accept(
            $pruefung,
            $this->qualifiedInspector(),
            $this->allPass($pruefung),
        );

        $this->assertSame(InspectionState::Accepted, $pruefung->fresh()->state);
        $this->assertSame(LotState::Serviceable, $bewegung->lot->fresh()->state);
    }

    /**
     * Eine Prüfung mit Lücken wird nicht unterschrieben.
     */
    #[Test]
    public function an_unanswered_item_blocks_the_signature(): void
    {
        $bewegung = $this->receive();
        $pruefung = IncomingInspection::query()->where('stock_movement_id', $bewegung->id)->firstOrFail();

        $antworten = $this->allPass($pruefung);
        array_shift($antworten); // Einen Punkt offen lassen.

        $this->expectException(InvalidArgumentException::class);

        app(CompleteIncomingInspection::class)->accept($pruefung, $this->qualifiedInspector(), $antworten);
    }

    /**
     * Auch eine Zurückweisung braucht die vollständige Liste.
     *
     * Der Grund für die Rückweisung ist der Befund -- und der ist nichts wert,
     * wenn niemand aufgeschrieben hat, was der Lieferung sonst noch fehlte.
     */
    #[Test]
    public function a_rejection_needs_the_full_list_too(): void
    {
        $bewegung = $this->receive();
        $pruefung = IncomingInspection::query()->where('stock_movement_id', $bewegung->id)->firstOrFail();

        $this->expectException(InvalidArgumentException::class);

        app(CompleteIncomingInspection::class)->reject($pruefung, $this->qualifiedInspector(), [], 'Papier fehlt');
    }

    #[Test]
    public function a_rejection_leaves_the_goods_where_they_are(): void
    {
        $bewegung = $this->receive();
        $pruefung = IncomingInspection::query()->where('stock_movement_id', $bewegung->id)->firstOrFail();

        app(CompleteIncomingInspection::class)->reject(
            $pruefung,
            $this->qualifiedInspector(),
            $this->allPass($pruefung),
            'Form 1 fehlt, geht zurück an den Lieferanten.',
        );

        $this->assertSame(InspectionState::Rejected, $pruefung->fresh()->state);
        $this->assertSame(
            LotState::Quarantined,
            $bewegung->lot->fresh()->state,
            'Zurückweisen bewegt nichts -- die Ware bleibt gesperrt.',
        );
    }

    #[Test]
    public function rejecting_without_a_reason_is_refused(): void
    {
        $bewegung = $this->receive();
        $pruefung = IncomingInspection::query()->where('stock_movement_id', $bewegung->id)->firstOrFail();

        $this->expectException(InvalidArgumentException::class);

        app(CompleteIncomingInspection::class)->reject($pruefung, $this->qualifiedInspector(), $this->allPass($pruefung));
    }

    /**
     * Beanstandung und trotzdem annehmen: erlaubt, aber begründet.
     */
    #[Test]
    public function accepting_despite_a_failure_needs_a_word_on_it(): void
    {
        $bewegung = $this->receive();
        $pruefung = IncomingInspection::query()->where('stock_movement_id', $bewegung->id)->firstOrFail();

        $antworten = $this->allPass($pruefung);
        $antworten[CheckItem::Condition->value] = [
            'result' => CheckResult::Fail,
            'note' => 'Karton eingedrückt.',
        ];

        try {
            app(CompleteIncomingInspection::class)->accept($pruefung, $this->qualifiedInspector(), $antworten);
            $this->fail('Ohne Begründung darf das nicht durchgehen.');
        } catch (InvalidArgumentException) {
            // So gewollt.
        }

        $ergebnis = app(CompleteIncomingInspection::class)->accept(
            $pruefung->fresh(['checks']),
            $this->qualifiedInspector(),
            $antworten,
            'Teil selbst unbeschädigt, Sichtprüfung ohne Befund.',
        );

        $this->assertSame(InspectionState::Accepted, $ergebnis->state);
    }

    /**
     * „Entfällt" ohne Begründung ist von „nicht hingeschaut" nicht zu
     * unterscheiden.
     */
    #[Test]
    public function not_applicable_without_a_reason_is_refused(): void
    {
        $bewegung = $this->receive();
        $pruefung = IncomingInspection::query()->where('stock_movement_id', $bewegung->id)->firstOrFail();

        $antworten = $this->allPass($pruefung);
        // Ein Punkt, der bei DIESER Lieferung wirklich gestellt wird -- die
        // Restlaufzeit waere es nicht, das Teil hat keine.
        $antworten[CheckItem::Identification->value] = ['result' => CheckResult::NotApplicable, 'note' => null];

        $this->expectException(InvalidArgumentException::class);

        app(CompleteIncomingInspection::class)->accept($pruefung, $this->qualifiedInspector(), $antworten);
    }

    /**
     * Eine abgeschlossene Prüfung wird nicht noch einmal abgeschlossen.
     */
    #[Test]
    public function a_decided_inspection_is_final(): void
    {
        $bewegung = $this->receive();
        $pruefung = IncomingInspection::query()->where('stock_movement_id', $bewegung->id)->firstOrFail();

        app(CompleteIncomingInspection::class)->accept($pruefung, $this->qualifiedInspector(), $this->allPass($pruefung));

        $this->expectException(InvalidArgumentException::class);

        app(CompleteIncomingInspection::class)->reject(
            $pruefung->fresh(['checks']),
            $this->qualifiedInspector(),
            [],
            'Doch nicht.',
        );
    }

    /**
     * Wer nicht freigeben darf, gibt auch über die Eingangsprüfung nicht frei.
     *
     * Die Annahme hebt die Sperre auf, und das ist die Regel des Lagers. Sie
     * hier zu umgehen wäre der bequemste Weg an einer Qualifikation vorbei.
     */
    #[Test]
    public function acceptance_inherits_the_warehouses_qualification_rule(): void
    {
        $bewegung = $this->receive();
        $pruefung = IncomingInspection::query()->where('stock_movement_id', $bewegung->id)->firstOrFail();

        $ungelernt = User::factory()->create(['is_active' => true]);

        try {
            app(CompleteIncomingInspection::class)->accept($pruefung, $ungelernt, $this->allPass($pruefung));
            $this->fail('Ohne Qualifikation darf die Sperre nicht fallen.');
        } catch (\Throwable) {
            // So gewollt.
        }

        $this->assertSame(LotState::Quarantined, $bewegung->lot->fresh()->state);
        $this->assertSame(
            InspectionState::Open,
            $pruefung->fresh()->state,
            'Und die Prüfung darf dabei auch nicht halb abgeschlossen zurückbleiben.',
        );
    }

    private function receive(): StockMovement
    {
        $teil = PartType::create([
            'name' => 'Bremszylinder',
            'classification' => PartClassification::Component,
            'unit' => 'Stk',
            'requires_form_one' => true,
            'serial_tracked' => true,
        ]);

        return app(ReceiveStock::class)->handle($teil, 1, now()->toDateString(), null, [
            'serial_number' => 'SN-4711',
            'document_type' => 'form_one',
            'document_reference' => 'F1-2026-0815',
        ]);
    }

    /** @return array<string, array{result: CheckResult, note: string|null}> */
    private function allPass(IncomingInspection $inspection): array
    {
        $antworten = [];

        foreach ($inspection->checks as $check) {
            $antworten[$check->item->value] = ['result' => CheckResult::Pass, 'note' => null];
        }

        return $antworten;
    }

    /**
     * Jemand, der freigeben darf -- Recht UND Part-66-Qualifikation.
     *
     * Beides ist noetig, und das ist keine Doppelung: das Recht ist eine
     * Verwaltungsfrage, die Qualifikation eine Aussage ueber die Person.
     */
    private function qualifiedInspector(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(WarehousePermissions::STOCK_QUARANTINE);
        $user->givePermissionTo(WarehousePermissions::STOCK_QUARANTINE_CERTIFY);
        $user->givePermissionTo(Permissions::INSPECTION_PERFORM);

        Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.00000',
            'category' => 'B1',
            'valid_from' => now()->subYear()->toDateString(),
        ]);

        return $user->fresh();
    }
}
