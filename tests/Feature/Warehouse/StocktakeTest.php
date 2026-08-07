<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Models\User;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Actions\RecordStocktake;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\MovementType;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Booking a stocktake.
 *
 * The heart of this file is one rule, and it is the: a surplus on a
 * lot-tracked part must never be added to an existing lot, because that would
 * quietly claim the extra part is covered by that lot's certificate. Most of
 * the tests below exist to hold that line.
 */
final class StocktakeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_difference_on_bulk_stock_is_booked_as_a_correction(): void
    {
        $nuts = $this->bulkPart();
        app(ReceiveStock::class)->handle($nuts, 500, '2026-07-01');

        // 480 counted where 500 were recorded.
        app(RecordStocktake::class)->correctBulk($nuts->fresh(), 480, $this->user(), 'Inventur Juli');

        $this->assertSame(480.0, $nuts->fresh()->currentStock());

        $correction = StockMovement::where('type', MovementType::Correction)->sole();
        $this->assertSame(-20.0, (float) $correction->quantity);
    }

    #[Test]
    public function the_original_booking_stays_visible_beside_the_correction(): void
    {
        // The point of a counter-booking: both entries together explain what
        // happened, which is what a stocktake difference looks like on paper too.
        $nuts = $this->bulkPart();
        app(ReceiveStock::class)->handle($nuts, 500, '2026-07-01');
        app(RecordStocktake::class)->correctBulk($nuts->fresh(), 480, $this->user(), 'Inventur');

        $this->assertSame(2, StockMovement::count());
        $this->assertSame(500.0, (float) StockMovement::where('type', MovementType::Receipt)->sole()->quantity);
    }

    #[Test]
    public function counting_what_the_system_says_books_nothing(): void
    {
        $nuts = $this->bulkPart();
        app(ReceiveStock::class)->handle($nuts, 500, '2026-07-01');

        $result = app(RecordStocktake::class)->correctBulk($nuts->fresh(), 500, $this->user());

        $this->assertNull($result);
        $this->assertSame(1, StockMovement::count(), 'An entry saying nothing changed is noise.');
    }

    #[Test]
    public function a_surplus_on_bulk_stock_is_simply_booked(): void
    {
        // No certificate is involved, so there is nothing to get wrong.
        $nuts = $this->bulkPart();
        app(ReceiveStock::class)->handle($nuts, 500, '2026-07-01');

        app(RecordStocktake::class)->correctBulk($nuts->fresh(), 512, $this->user(), 'Inventur');

        $this->assertSame(512.0, $nuts->fresh()->currentStock());
    }

    #[Test]
    public function a_shortfall_may_be_booked_against_a_lot(): void
    {
        // Saying "this lot is one short" claims nothing about any certificate.
        $filters = $this->lotPart();
        app(ReceiveStock::class)->handle($filters, 4, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-8842',
        ]);

        $lot = StockLot::sole();
        app(RecordStocktake::class)->correctLotShortfall($lot, 3, $this->user(), 'Inventur');

        $this->assertSame(3.0, $lot->fresh()->remainingQuantity());
    }

    #[Test]
    public function a_surplus_cannot_be_added_to_an_existing_lot(): void
    {
        // THE rule. Booking "+1" onto this lot would assert the extra filter
        // arrived on that delivery and is covered by F1-2026-8842 -- and nobody
        // counting a shelf knows that.
        $filters = $this->lotPart();
        app(ReceiveStock::class)->handle($filters, 4, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-8842',
        ]);

        $lot = StockLot::sole();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/covered by that lot/');

        app(RecordStocktake::class)->correctLotShortfall($lot, 5, $this->user(), 'Inventur');
    }

    #[Test]
    public function the_certificate_keeps_exactly_the_quantity_it_covered(): void
    {
        $filters = $this->lotPart();
        app(ReceiveStock::class)->handle($filters, 4, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-8842',
        ]);

        $lot = StockLot::sole();

        try {
            app(RecordStocktake::class)->correctLotShortfall($lot, 6, $this->user(), 'Inventur');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(4.0, $lot->fresh()->remainingQuantity(), 'Nothing may have been added.');
    }

    #[Test]
    public function a_found_part_opens_its_own_lot_without_a_certificate(): void
    {
        $filters = $this->lotPart();
        app(ReceiveStock::class)->handle($filters, 4, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-8842',
        ]);

        $found = app(RecordStocktake::class)->recordFound(
            $filters->fresh(), 1, $this->user(), 'Bei der Inventur im Regal gefunden, Herkunft unklar',
        );

        $this->assertSame(StockLot::DOCUMENT_NONE, $found->document_type);
        $this->assertNull($found->document_reference);
        $this->assertSame(2, StockLot::count());

        // ...and it did not touch the certified lot.
        $this->assertSame(
            4.0,
            StockLot::where('document_reference', 'F1-2026-8842')->sole()->remainingQuantity(),
        );
    }

    #[Test]
    public function a_found_part_is_not_usable_until_someone_says_so(): void
    {
        // ML.A.504: a part whose airworthiness status cannot be established is
        // unserviceable. An unknown origin is exactly that.
        $filters = $this->lotPart();

        $found = app(RecordStocktake::class)->recordFound(
            $filters, 1, $this->user(), 'Herkunft unklar',
        );

        $this->assertSame(LotState::Quarantined, $found->state);
        $this->assertFalse($found->isIssuable());
        $this->assertSame(0.0, $filters->fresh()->availableStock());
        $this->assertSame(1.0, $filters->fresh()->currentStock(), 'It is in the building, though.');
    }

    #[Test]
    public function a_found_part_gets_no_invented_expiry_date(): void
    {
        // It would have to be derived from a receipt date nobody knows, and a
        // made-up date on an airworthiness record is worse than an absent one.
        $filters = $this->lotPart(shelfLifeDays: 365);

        $found = app(RecordStocktake::class)->recordFound(
            $filters, 1, $this->user(), 'Herkunft unklar',
        );

        $this->assertNull($found->expires_at);
    }

    #[Test]
    public function recording_a_find_demands_a_note(): void
    {
        // It is the only clue anyone will have later.
        $this->expectException(InvalidArgumentException::class);

        app(RecordStocktake::class)->recordFound($this->lotPart(), 1, $this->user(), '   ');
    }

    #[Test]
    public function a_lot_tracked_part_cannot_be_corrected_in_bulk(): void
    {
        $filters = $this->lotPart();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/name the lot/');

        app(RecordStocktake::class)->correctBulk($filters, 5, $this->user());
    }

    #[Test]
    public function corrections_cannot_be_edited_away_afterwards(): void
    {
        $nuts = $this->bulkPart();
        app(ReceiveStock::class)->handle($nuts, 500, '2026-07-01');

        $correction = app(RecordStocktake::class)
            ->correctBulk($nuts->fresh(), 480, $this->user(), 'Inventur');

        $this->expectException(RuntimeException::class);

        $correction->update(['quantity' => 0]);
    }

    private function bulkPart(): PartType
    {
        return PartType::create([
            'name' => 'Mutter M6 '.uniqid(),
            'classification' => PartClassification::StandardPart,
            'unit_of_measure' => 'St',
        ]);
    }

    private function lotPart(?int $shelfLifeDays = null): PartType
    {
        return PartType::create([
            'name' => 'Ölfilter '.uniqid(),
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'St',
            'requires_form_one' => true,
            'shelf_life_days' => $shelfLifeDays,
        ]);
    }

    private function user(): User
    {
        return User::factory()->create(['is_active' => true]);
    }
}
