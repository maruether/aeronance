<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Warehouse\Actions\CancelPurchaseOrder;
use App\Modules\Warehouse\Actions\ReceivePurchaseOrderLine;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Enums\PurchaseOrderState;
use App\Modules\Warehouse\Filament\Resources\PurchaseOrders\Schemas\PurchaseOrderForm;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\PurchaseOrder;
use App\Modules\Warehouse\Models\PurchaseOrderLine;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bestellungen — Lieferverfolgung, keine Warenwirtschaft.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „Es geht bei den bestellungen nicht darum über aeronance bestellungen
 * auszuführen oder die Kosten zu führen sondern nur darum einen reminder zu
 * bekommen. Der Hintergrund ist das ich gerade erst mit einem Lieferanten auf
 * die nase gefallen bin der sich nicht gemeldet hatte."
 *
 * Die zwei Tests, auf die es ankommt:
 *
 *   `an_ordered_quantity_is_not_stock` — bestellt ist nicht vorrätig. Sonst
 *   rechnet sich ein Verein reich mit Teilen, die beim Lieferanten stehen.
 *
 *   `a_partial_delivery_leaves_the_order_open` — „erst wenn alles abgehakt ist
 *   ist die bestellung erledigt".
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(ModuleManager::class)->enable('warehouse');
        app(ModuleManager::class)->forgetCache();
    }

    /**
     * DER TEST, UM DEN ES GEHT: Bestellt ist nicht vorrätig.
     *
     * Eine bestellte Menge liegt nicht im Regal. Tauchte sie im Bestand auf,
     * wäre jede Verfügbarkeitsauskunft gelogen — und jemand plant eine Wartung
     * mit einem Teil, das noch beim Lieferanten steht.
     */
    #[Test]
    public function an_ordered_quantity_is_not_stock(): void
    {
        $teil = $this->part();
        $this->order($teil, 5);

        $this->assertSame(0.0, $teil->fresh()->availableStock());
        $this->assertSame(0, StockLot::query()->count());
    }

    /**
     * DER ZWEITE: Eine Teillieferung lässt die Bestellung offen.
     */
    #[Test]
    public function a_partial_delivery_leaves_the_order_open(): void
    {
        $teil = $this->part();
        $bestellung = $this->order($teil, 5);
        $position = $bestellung->lines()->first();

        app(ReceivePurchaseOrderLine::class)->handle($position, 2, '2026-08-06');

        $bestellung->refresh();

        $this->assertSame(PurchaseOrderState::PartiallyReceived, $bestellung->state);
        $this->assertFalse($bestellung->isComplete());
        $this->assertSame(3.0, $position->fresh()->outstanding());

        // Und was da ist, IST jetzt Bestand.
        $this->assertSame(2.0, $teil->fresh()->availableStock());
    }

    #[Test]
    public function the_last_delivery_completes_the_order(): void
    {
        $teil = $this->part();
        $bestellung = $this->order($teil, 5);
        $position = $bestellung->lines()->first();

        app(ReceivePurchaseOrderLine::class)->handle($position, 2, '2026-08-06');
        app(ReceivePurchaseOrderLine::class)->handle($position->fresh(), 3, '2026-08-07');

        $this->assertSame(PurchaseOrderState::Received, $bestellung->fresh()->state);
        $this->assertSame(5.0, $teil->fresh()->availableStock());
    }

    /**
     * Das Einbuchen geht durch die Lageraktion — also entsteht ein Los.
     *
     * Das ist der Nutzen am anderen Ende der Kette: Die Bestellung führt in
     * die Nachweiskette hinein, statt eine zweite Welt daneben aufzumachen.
     */
    #[Test]
    public function receiving_creates_a_lot_with_its_paper(): void
    {
        /*
         * Ein Teil MIT Form-1-Pflicht -- nur dann entsteht ein Los. Ein
         * Standard-Teil laeuft als Sammelbestand mit reinem Bewegungsjournal
         * (siehe E1/E6), und das ist hier nicht die Frage.
         */
        $teil = $this->part('Bremszylinder', formOne: true);
        $bestellung = $this->order($teil, 2);

        app(ReceivePurchaseOrderLine::class)->handle(
            $bestellung->lines()->first(),
            2,
            '2026-08-06',
            lotData: [
                'document_type' => StockLot::DOCUMENT_FORM_ONE,
                'document_reference' => 'F1-2026-77',
            ],
        );

        $los = StockLot::query()->sole();

        $this->assertSame('F1-2026-77', $los->document_reference);
        $this->assertSame($teil->getKey(), $los->part_type_id);

        // Der Lieferant der Bestellung wird uebernommen -- er steht ja fest.
        $this->assertSame($bestellung->supplier_id, $los->supplier_id);
    }

    /**
     * Eine Bestellung ohne Positionen ist NICHT erledigt.
     *
     * Sonst gälte sie in dem Moment als abgeschlossen, in dem jemand sie
     * anlegt und beim Eintragen der Teile unterbrochen wird.
     */
    #[Test]
    public function an_order_without_lines_is_not_complete(): void
    {
        $bestellung = PurchaseOrder::create([
            'supplier_id' => Supplier::create(['name' => 'Firma'])->getKey(),
            'ordered_at' => '2026-08-01',
        ]);

        $this->assertFalse($bestellung->isComplete());

        $bestellung->refreshState();

        $this->assertSame(PurchaseOrderState::Open, $bestellung->fresh()->state);
    }

    /**
     * Das Lieferdatum ist mit Bestelldatum plus einer Woche vorbelegt.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Vorgabe: „da einige lieferanten kein lieferdatum angeben, würde ich gerne
     * bestelldatum + 1 Woche als default einsetzen."
     *
     * Der Grund ist der Erinnerer: Er hängt am zugesagten Datum. Ohne
     * Vorbelegung gäbe es bei genau den Lieferanten keine Erinnerung, die gar
     * kein Datum nennen — also bei dem, der sich nicht meldet, und das ist der
     * Fall, um den es geht.
     * ─────────────────────────────────────────────────────────────────────────
     */
    #[Test]
    public function the_expected_date_is_prefilled_a_week_out(): void
    {
        $this->assertSame(
            '2026-08-08',
            PurchaseOrderForm::defaultExpectedAt('2026-08-01'),
        );
    }

    /**
     * Und die Frist ist einstellbar — eine Woche ist eine Annahme, keine Regel.
     */
    #[Test]
    public function the_lead_time_is_configurable(): void
    {
        config()->set('aeronance.orders.default_lead_days', 14);

        $this->assertSame(
            '2026-08-15',
            PurchaseOrderForm::defaultExpectedAt('2026-08-01'),
        );
    }

    // ── Stornieren ───────────────────────────────────────────────────────────

    /**
     * „Es kann vorkommen das material nicht kommt."
     */
    #[Test]
    public function an_order_can_be_cancelled_with_a_reason(): void
    {
        $bestellung = $this->order($this->part(), 5);

        app(CancelPurchaseOrder::class)->handle($bestellung, 'Lieferant kann nicht liefern');

        $bestellung->refresh();

        $this->assertSame(PurchaseOrderState::Cancelled, $bestellung->state);
        $this->assertSame('Lieferant kann nicht liefern', $bestellung->cancel_reason);
    }

    /**
     * Storniert LÖSCHT keine gelieferte Ware.
     *
     * Der häufigste Fall: Die Hälfte kam, der Rest kommt nie. Was im Regal
     * liegt, liegt im Regal.
     */
    #[Test]
    public function cancelling_keeps_what_already_arrived(): void
    {
        $teil = $this->part();
        $bestellung = $this->order($teil, 5);

        app(ReceivePurchaseOrderLine::class)->handle($bestellung->lines()->first(), 2, '2026-08-06');
        app(CancelPurchaseOrder::class)->handle($bestellung->fresh(), 'Rest kommt nicht mehr');

        $this->assertSame(PurchaseOrderState::Cancelled, $bestellung->fresh()->state);
        $this->assertSame(2.0, $teil->fresh()->availableStock(), 'Gelieferte Ware bleibt.');
    }

    /**
     * Auf eine stornierte Bestellung wird nichts mehr gebucht.
     */
    #[Test]
    public function nothing_is_booked_onto_a_cancelled_order(): void
    {
        $bestellung = $this->order($this->part(), 5);
        app(CancelPurchaseOrder::class)->handle($bestellung, 'Storniert');

        $this->expectException(InvalidArgumentException::class);

        app(ReceivePurchaseOrderLine::class)->handle($bestellung->lines()->first(), 1, '2026-08-06');
    }

    #[Test]
    public function a_cancellation_needs_a_reason(): void
    {
        $bestellung = $this->order($this->part(), 5);

        $this->expectException(InvalidArgumentException::class);

        app(CancelPurchaseOrder::class)->handle($bestellung, '   ');
    }

    // ── Überfälligkeit ───────────────────────────────────────────────────────

    /**
     * Überfällig ist, was zugesagt war und nicht kam.
     */
    #[Test]
    public function an_order_past_its_promised_date_is_overdue(): void
    {
        $spaet = $this->order($this->part(), 1, expected: now()->subDays(3)->toDateString());
        $puenktlich = $this->order($this->part('Zweites'), 1, expected: now()->addDays(3)->toDateString());

        $ueberfaellig = PurchaseOrder::query()->overdue()->pluck('id');

        $this->assertTrue($ueberfaellig->contains($spaet->id));
        $this->assertFalse($ueberfaellig->contains($puenktlich->id));
    }

    /**
     * OHNE ZUGESAGTES DATUM GIBT ES KEINE ÜBERFÄLLIGKEIT.
     *
     * Man kann nicht zu spät sein, wenn nie ein Termin genannt wurde. Eine
     * Bestellung ohne Datum trotzdem anzumahnen hiesse, eine Zusage zu
     * erfinden — und die Erinnerung damit wertlos zu machen.
     */
    #[Test]
    public function without_a_promised_date_nothing_is_overdue(): void
    {
        $ohne = $this->order($this->part(), 1, expected: null);

        $this->assertFalse(PurchaseOrder::query()->overdue()->pluck('id')->contains($ohne->id));
    }

    /**
     * Und eine erledigte Bestellung ist nie überfällig.
     */
    #[Test]
    public function a_completed_order_is_never_overdue(): void
    {
        $bestellung = $this->order($this->part(), 2, expected: now()->subDays(5)->toDateString());

        app(ReceivePurchaseOrderLine::class)->handle($bestellung->lines()->first(), 2, '2026-08-06');

        $this->assertFalse(PurchaseOrder::query()->overdue()->pluck('id')->contains($bestellung->id));
    }

    private function order(PartType $part, float $menge, ?string $expected = '2026-09-01'): PurchaseOrder
    {
        $bestellung = PurchaseOrder::create([
            'order_number' => 'B-'.$part->getKey().'-'.(int) $menge,
            'supplier_id' => Supplier::firstOrCreate(['name' => 'Firma'])->getKey(),
            'ordered_at' => '2026-08-01',
            'expected_at' => $expected,
            'created_by_id' => User::factory()->create(['is_active' => true])->getKey(),
        ]);

        PurchaseOrderLine::create([
            'purchase_order_id' => $bestellung->getKey(),
            'part_type_id' => $part->getKey(),
            'quantity_ordered' => $menge,
        ]);

        return $bestellung->fresh();
    }

    private function part(string $name = 'Bremsklotz', bool $formOne = false): PartType
    {
        return PartType::query()->firstOrCreate(
            ['name' => $name],
            [
                'classification' => $formOne
                    ? PartClassification::Component
                    : PartClassification::StandardPart,
                'requires_form_one' => $formOne,
            ],
        );
    }
}
