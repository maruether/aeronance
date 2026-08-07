<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Models\User;
use App\Modules\Warehouse\Actions\ChangeLotState;
use App\Modules\Warehouse\Actions\IssueStock;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\MovementType;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StockMovement;
use App\Modules\Warehouse\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Decision E1: stock is the sum of its movements. There is no quantity to
 * overwrite, so a correction is a counter-booking and the ledger cannot
 * disagree with itself.
 *
 * And decision 4.5: two ways of keeping stock, chosen by the part type. Nuts
 * are counted; anything with a certificate, a shelf life or a serial number is
 * tracked by lot, because for those "which delivery did this come from" has to
 * have an answer.
 */
final class StockLedgerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function bulk_stock_needs_no_lot(): void
    {
        $nuts = $this->bulkPart();

        app(ReceiveStock::class)->handle($nuts, 500, '2026-07-01', lotData: $this->certified());

        $this->assertSame(500.0, $nuts->currentStock());
        $this->assertNull(StockMovement::sole()->stock_lot_id, 'Nuts do not need a lot.');
        $this->assertSame(0, StockLot::count());
    }

    #[Test]
    public function stock_is_the_sum_of_movements(): void
    {
        $nuts = $this->bulkPart();
        $receive = app(ReceiveStock::class);
        $issue = app(IssueStock::class);

        $receive->handle($nuts, 500, '2026-07-01');
        $issue->handle($nuts, 20);
        $issue->handle($nuts, 5);
        $receive->handle($nuts, 100, '2026-07-15');

        $this->assertSame(575.0, $nuts->fresh()->currentStock());
        $this->assertSame(4, StockMovement::count());
    }

    #[Test]
    public function a_movement_cannot_be_edited_or_deleted(): void
    {
        // Without this, the ledger is just a table.
        $nuts = $this->bulkPart();
        $movement = app(ReceiveStock::class)->handle($nuts, 100, '2026-07-01', lotData: $this->certified());

        try {
            $movement->update(['quantity' => 999]);
            $this->fail('A movement must not be editable.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('counter-booking', $e->getMessage());
        }

        try {
            $movement->delete();
            $this->fail('A movement must not be deletable.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('they are the stock', $e->getMessage());
        }

        $this->assertSame(100.0, $nuts->fresh()->currentStock());
    }

    #[Test]
    public function a_part_with_a_shelf_life_is_tracked_by_lot(): void
    {
        $oil = $this->lotTrackedPart(shelfLifeDays: 365);

        app(ReceiveStock::class)->handle($oil, 4, '2026-07-01', lotData: $this->certified());

        $lot = StockLot::sole();
        $this->assertSame($oil->id, $lot->part_type_id);
        $this->assertSame('2027-07-01', $lot->expires_at->toDateString());
        $this->assertSame(4.0, $lot->remainingQuantity());
    }

    #[Test]
    public function the_expiry_is_stored_not_recomputed(): void
    {
        // If the shelf life on the part type is corrected later, stock already
        // on the shelf must not silently come back to life.
        $oil = $this->lotTrackedPart(shelfLifeDays: 30);
        app(ReceiveStock::class)->handle($oil, 1, '2026-07-01', lotData: $this->certified());

        $oil->update(['shelf_life_days' => 3650]);

        $this->assertSame('2026-07-31', StockLot::sole()->expires_at->toDateString());
    }

    #[Test]
    public function four_oil_filters_from_one_delivery_are_one_lot(): void
    {
        // the example, and literally how a Form 1 is laid out: block 9 is a
        // quantity, block 10 a serial OR batch number.
        $filters = $this->lotTrackedPart(requiresFormOne: true);

        app(ReceiveStock::class)->handle($filters, 4, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-8842',
            'document_issuer' => 'Rotax Service Center',
        ]);

        $lot = StockLot::sole();
        $this->assertSame(4.0, $lot->remainingQuantity());
        $this->assertSame('F1-2026-8842', $lot->document_reference);
    }

    #[Test]
    public function a_serialised_part_is_a_lot_of_one(): void
    {
        $release = $this->lotTrackedPart(serialTracked: true);

        app(ReceiveStock::class)->handle($release, 1, '2026-07-01', lotData: [
            'serial_number' => '1378X5V',
        ]);

        $this->assertSame('1378X5V', StockLot::sole()->serial_number);

        $this->expectException(InvalidArgumentException::class);
        app(ReceiveStock::class)->handle($release, 3, '2026-07-01', lotData: $this->certified());
    }

    #[Test]
    public function issuing_from_a_lot_tracked_part_requires_naming_the_lot(): void
    {
        $oil = $this->lotTrackedPart(shelfLifeDays: 365);
        app(ReceiveStock::class)->handle($oil, 4, '2026-07-01', lotData: $this->certified());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/which lot/');

        app(IssueStock::class)->handle($oil, 1);
    }

    #[Test]
    public function a_lot_keeps_its_certificate_while_anything_remains(): void
    {
        // One of four filters goes out; the other three stay, with the same
        // Form 1. The chain from part to certificate holds for each of them.
        $filters = $this->lotTrackedPart(requiresFormOne: true);
        app(ReceiveStock::class)->handle($filters, 4, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-8842',
        ]);

        $lot = StockLot::sole();
        app(IssueStock::class)->handle($filters, 1, $lot, aircraftReference: 'D-KABC');

        $this->assertSame(3.0, $lot->fresh()->remainingQuantity());
        $this->assertSame('F1-2026-8842', $lot->fresh()->document_reference);
    }

    #[Test]
    public function an_issue_records_where_the_part_went(): void
    {
        // This is where stock keeping turns into traceability.
        $filters = $this->lotTrackedPart(requiresFormOne: true);
        app(ReceiveStock::class)->handle($filters, 4, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-8842',
        ]);

        app(IssueStock::class)->handle(
            $filters,
            1,
            StockLot::sole(),
            workOrderReference: 'AK-2026-014',
            aircraftReference: 'D-KABC',
        );

        $movement = StockMovement::where('type', MovementType::Issue)->sole();
        $this->assertSame('D-KABC', $movement->aircraft_reference);
        $this->assertSame('AK-2026-014', $movement->work_order_reference);

        // ...and the chain back out again.
        $this->assertSame('F1-2026-8842', $movement->lot->document_reference);
    }

    #[Test]
    public function one_lot_can_end_up_in_several_aircraft(): void
    {
        // The point of 4.7 f: one certificate, several life records.
        $filters = $this->lotTrackedPart(requiresFormOne: true);
        app(ReceiveStock::class)->handle($filters, 4, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-8842',
        ]);

        $lot = StockLot::sole();
        $issue = app(IssueStock::class);

        foreach (['D-KABC', 'D-KXYZ', 'D-1234'] as $aircraft) {
            $issue->handle($filters, 1, $lot, aircraftReference: $aircraft);
        }

        $aircraft = StockMovement::where('type', MovementType::Issue)
            ->pluck('aircraft_reference')->all();

        $this->assertEqualsCanonicalizing(['D-KABC', 'D-KXYZ', 'D-1234'], $aircraft);
        $this->assertSame(1.0, $lot->fresh()->remainingQuantity());
    }

    #[Test]
    public function it_refuses_to_issue_more_than_is_there(): void
    {
        $oil = $this->lotTrackedPart(shelfLifeDays: 365);
        app(ReceiveStock::class)->handle($oil, 2, '2026-07-01', lotData: $this->certified());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/holds only/');

        app(IssueStock::class)->handle($oil, 5, StockLot::sole());
    }

    #[Test]
    public function it_refuses_to_issue_from_an_expired_lot(): void
    {
        $oil = $this->lotTrackedPart(shelfLifeDays: 30);
        app(ReceiveStock::class)->handle($oil, 4, '2026-01-01', lotData: $this->certified());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/expired/');

        app(IssueStock::class)->handle($oil, 1, StockLot::sole());
    }

    /**
     * Ohne Form 1 wird gar nicht erst eingebucht.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Hier stand vorher: Los anlegen, Zustand „gesperrt". Die Regel wurde
     * danach klargestellt -- „ein los geht erst dann ins lager wenn das form1
     * da ist. vorher liegt es im wareneingang und ist noch nicht verbucht."
     *
     * Der Unterschied ist nicht Buchhaltung: Ein gesperrtes Los IST Bestand.
     * Es hat eine Losnummer, steht in Listen, wird bei der Inventur gezählt und
     * muss von jemandem entsperrt werden. Der Karton im Wareneingang ist nichts
     * davon.
     * ─────────────────────────────────────────────────────────────────────────
     */
    #[Test]
    public function goods_arriving_without_the_required_certificate_are_refused(): void
    {
        $filters = $this->lotTrackedPart(requiresFormOne: true);

        $this->expectExceptionMessageMatches('/Wareneingang/');

        app(ReceiveStock::class)->handle($filters, 4, '2026-07-01');
    }

    #[Test]
    public function nothing_is_booked_when_the_certificate_is_missing(): void
    {
        // Der Punkt an „noch nicht verbucht": kein Los, KEINE Bewegung. Ein
        // Wareneingang, der die Ware halb verbucht, wäre schlimmer als keiner.
        $filters = $this->lotTrackedPart(requiresFormOne: true);

        try {
            app(ReceiveStock::class)->handle($filters, 4, '2026-07-01');
        } catch (\Throwable) {
            // erwartet
        }

        $this->assertSame(0, StockLot::count());
        $this->assertSame(0.0, (float) $filters->currentStock());
    }

    #[Test]
    public function quarantined_stock_counts_but_cannot_be_issued(): void
    {
        // It is in the building and on the books -- it just must not be fitted.
        $filters = $this->lotTrackedPart(requiresFormOne: true);
        app(ReceiveStock::class)->handle($filters, 4, '2026-07-01', lotData: $this->certified());

        $this->quarantine(StockLot::sole());

        $this->assertSame(4.0, $filters->fresh()->currentStock());
        $this->assertSame(0.0, $filters->fresh()->availableStock());
    }

    #[Test]
    public function fefo_suggests_the_lot_that_expires_first(): void
    {
        $oil = $this->lotTrackedPart(shelfLifeDays: 365);
        $receive = app(ReceiveStock::class);

        $receive->handle($oil, 2, '2026-06-01');   // expires 2027-06-01
        $receive->handle($oil, 2, '2026-01-01');   // expires 2027-01-01 -- first
        $receive->handle($oil, 2, '2026-07-01');

        $suggested = app(IssueStock::class)->suggestLot($oil->fresh());

        $this->assertNotNull($suggested);
        $this->assertSame('2027-01-01', $suggested->expires_at->toDateString());
    }

    #[Test]
    public function nothing_is_suggested_for_a_serialised_part(): void
    {
        // There the serial number is asked for outright: the choice IS the
        // identification, and a suggestion would invite confirming the wrong
        // part. See F26.
        $release = $this->lotTrackedPart(serialTracked: true);
        app(ReceiveStock::class)->handle($release, 1, '2026-07-01', lotData: ['serial_number' => 'A1'] + $this->certified());

        $this->assertNull(app(IssueStock::class)->suggestLot($release->fresh()));
    }

    #[Test]
    public function it_reports_stock_below_the_minimum(): void
    {
        $nuts = $this->bulkPart(['minimum_stock' => 100]);
        app(ReceiveStock::class)->handle($nuts, 500, '2026-07-01', lotData: $this->certified());

        $this->assertFalse($nuts->fresh()->isBelowMinimum());

        app(IssueStock::class)->handle($nuts->fresh(), 450);

        $this->assertTrue($nuts->fresh()->isBelowMinimum());
    }

    #[Test]
    public function quantities_may_be_fractional(): void
    {
        // Metres, litres and kilograms do not come in whole numbers. The legacy
        // schema used an integer while its own form offered steps of 0.1.
        $sealant = $this->bulkPart([
            'classification' => PartClassification::ConsumableMaterial,
            'unit_of_measure' => 'l',
        ]);

        app(ReceiveStock::class)->handle($sealant, 2.5, '2026-07-01', lotData: $this->certified());
        app(IssueStock::class)->handle($sealant->fresh(), 0.75);

        $this->assertSame(1.75, $sealant->fresh()->currentStock());
    }

    #[Test]
    public function the_sql_filter_agrees_with_the_per_record_calculation(): void
    {
        // Available stock is worked out twice -- in PHP for one record, in SQL
        // for a filtered list. They must not drift apart, which is why the rule
        // for "which movements count" lives in one place. This test is what
        // notices if someone changes only one of them.
        $plenty = $this->bulkPart(['minimum_stock' => 10]);
        $short = $this->bulkPart(['minimum_stock' => 100]);
        $quarantined = $this->lotTrackedPart(requiresFormOne: true);
        $quarantined->update(['minimum_stock' => 1]);

        app(ReceiveStock::class)->handle($plenty, 500, '2026-07-01', lotData: $this->certified());
        app(ReceiveStock::class)->handle($short, 50, '2026-07-01', lotData: $this->certified());
        app(ReceiveStock::class)->handle($quarantined, 5, '2026-07-01', lotData: $this->certified());

        // Gesperrt zaehlt nicht als verfuegbar -- darum geht es hier.
        $this->quarantine(StockLot::where('part_type_id', $quarantined->id)->sole());

        $viaSql = PartType::belowMinimum()->pluck('id')->all();

        $viaPhp = PartType::all()
            ->filter(fn (PartType $p): bool => $p->isBelowMinimum())
            ->pluck('id')->all();

        sort($viaSql);
        sort($viaPhp);

        $this->assertSame($viaPhp, $viaSql);
        $this->assertContains($short->id, $viaSql);
        $this->assertContains($quarantined->id, $viaSql, 'Quarantined stock does not count as available.');
        $this->assertNotContains($plenty->id, $viaSql);
    }

    #[Test]
    public function the_eager_loaded_sum_agrees_with_the_per_record_calculation(): void
    {
        $part = $this->bulkPart();
        app(ReceiveStock::class)->handle($part, 500, '2026-07-01', lotData: $this->certified());
        app(IssueStock::class)->handle($part->fresh(), 120);

        $eager = PartType::withAvailableStock()->find($part->id);

        $this->assertSame(380.0, $eager->availableStock());
        $this->assertSame(380.0, PartType::find($part->id)->availableStock());
    }

    /** @param  array<string, mixed>  $attributes */
    private function bulkPart(array $attributes = []): PartType
    {
        return PartType::create(array_merge([
            'name' => 'Mutter M6 '.uniqid(),
            'classification' => PartClassification::StandardPart,
            'unit_of_measure' => 'St',
        ], $attributes));
    }

    private function lotTrackedPart(
        bool $requiresFormOne = false,
        bool $serialTracked = false,
        ?int $shelfLifeDays = null,
    ): PartType {
        return PartType::create([
            'name' => 'Teil '.uniqid(),
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'St',
            'requires_form_one' => $requiresFormOne,
            'serial_tracked' => $serialTracked,
            'shelf_life_days' => $shelfLifeDays,
        ]);
    }

    private function user(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    /**
     * Wareneingang mit Nachweis.
     *
     * Seit „ein los geht erst dann ins lager wenn das form1 da ist" verweigert
     * ReceiveStock die Einbuchung eines Teils, das ein Form 1 verlangt, ohne
     * eines. Diese Tests sind nicht darueber -- sie brauchen nur Bestand.
     */
    private function certified(?string $reference = null): array
    {
        return [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => $reference ?? 'F1-'.mb_substr(md5((string) mt_rand()), 0, 8),
        ];
    }

    /**
     * Ein Los sperren -- ausdruecklich, nicht als Nebenwirkung.
     *
     * Frueher entstand ein gesperrtes Los beim Wareneingang ohne Papier. Das
     * geht nicht mehr: Ohne Form 1 wird gar nicht erst eingebucht ("vorher
     * liegt es im wareneingang und ist noch nicht verbucht").
     */
    private function quarantine(StockLot $lot, ?string $grund = null): StockLot
    {
        Permission::findOrCreate(Permissions::STOCK_QUARANTINE, 'web');

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::STOCK_QUARANTINE);

        app(ChangeLotState::class)->handle(
            $lot,
            LotState::Quarantined,
            $grund ?? 'Verdacht auf Transportschaden',
            $user->fresh(),
        );

        return $lot->fresh();
    }
}
