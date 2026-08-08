<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\Warehouse\Actions\DispatchForRepair;
use App\Modules\Warehouse\Actions\DisposeStock;
use App\Modules\Warehouse\Actions\IssueStock;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Actions\ReverseMovement;
use App\Modules\Warehouse\Enums\MovementType;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StockMovement;
use App\Modules\Warehouse\Permissions;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Putting a booking right.
 *
 * The counter-booking decision E1 has been pointing at since the first
 * migration: reverses_movement_id sat in the schema from day one with nothing
 * writing to it, so every ordinary slip had to be dressed up as a counting
 * difference.
 */
final class CorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([Permissions::STOCK_CORRECT, Permissions::STOCK_SCRAP, Permissions::STOCK_ISSUE] as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    #[Test]
    public function a_wrong_issue_is_taken_back_and_both_entries_stay(): void
    {
        // Not an edit. The original stands and a second, opposite entry is
        // written beside it -- which is also what a correction looks like on
        // paper.
        $nuts = $this->bulkPart();
        $user = $this->userWith(Permissions::STOCK_CORRECT);

        app(ReceiveStock::class)->handle($nuts, 500, '2025-07-01', lotData: $this->certified());
        $issue = app(IssueStock::class)->handle($nuts->fresh(), 200, occurredAt: '2025-08-15');

        $this->assertSame(300.0, $nuts->fresh()->currentStock());

        $correction = app(ReverseMovement::class)->handle($issue, $user, 'Falsches Teil gebucht');

        $this->assertSame(500.0, $nuts->fresh()->currentStock());
        $this->assertSame(MovementType::Correction, $correction->type);
        $this->assertSame($issue->id, $correction->reverses_movement_id);
        $this->assertSame(200.0, (float) $correction->quantity);

        $this->assertNotNull(StockMovement::find($issue->id), 'The original stays.');
        $this->assertSame(3, $nuts->fresh()->movements()->count());
    }

    #[Test]
    public function a_correction_carries_the_originals_context(): void
    {
        // A counter-booking that drops the work order and aircraft breaks the
        // very chain the movement was recorded for.
        $nuts = $this->bulkPart();
        $user = $this->userWith(Permissions::STOCK_CORRECT);

        app(ReceiveStock::class)->handle($nuts, 500, '2025-07-01', lotData: $this->certified());
        $issue = app(IssueStock::class)->handle(
            $nuts->fresh(), 10, null, $user,
            workOrderReference: 'AK-2025-014',
            aircraftReference: 'D-KABC',
        );

        $correction = app(ReverseMovement::class)->handle($issue, $user, 'Doppelt gebucht');

        $this->assertSame('AK-2025-014', $correction->work_order_reference);
        $this->assertSame('D-KABC', $correction->aircraft_reference);
    }

    #[Test]
    public function a_destruction_cannot_be_taken_back(): void
    {
        // THE refusal. A counter-booking here would assert the part is on the
        // shelf while it is in the bin.
        $nuts = $this->bulkPart();
        $user = $this->qualifiedUser();

        app(ReceiveStock::class)->handle($nuts, 500, '2025-07-01', lotData: $this->certified());

        $disposal = app(DisposeStock::class)
            ->handle($nuts->fresh(), 100, null, $user, 'Korrodiert');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot be reversed/');

        app(ReverseMovement::class)->handle($disposal, $user, 'Doch nicht');
    }

    #[Test]
    public function nothing_is_reversed_twice(): void
    {
        // Otherwise the same mistake can be corrected repeatedly, each time
        // moving the stock further from the truth.
        $nuts = $this->bulkPart();
        $user = $this->userWith(Permissions::STOCK_CORRECT);

        app(ReceiveStock::class)->handle($nuts, 500, '2025-07-01', lotData: $this->certified());
        $issue = app(IssueStock::class)->handle($nuts->fresh(), 200);

        app(ReverseMovement::class)->handle($issue, $user, 'Falsch');

        $this->assertFalse(app(ReverseMovement::class)->isReversible($issue));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already been corrected/');

        app(ReverseMovement::class)->handle($issue, $user, 'Nochmal falsch');
    }

    #[Test]
    public function a_receipt_cannot_be_taken_back_once_it_has_been_used(): void
    {
        // The stock did not vanish because the paperwork was wrong. A bigger
        // counter-booking would drive the lot below nil, so the honest answer is
        // a stocktake.
        $filters = $this->lotPart();
        $user = $this->userWith(Permissions::STOCK_CORRECT);

        app(ReceiveStock::class)->handle($filters, 4, '2025-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2025-1',
        ]);

        $lot = StockLot::sole();
        $receipt = $lot->movements()->sole();

        app(IssueStock::class)->handle($filters->fresh(), 3, $lot);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/holds only 1/');

        app(ReverseMovement::class)->handle($receipt, $user, 'Lieferung war falsch');
    }

    #[Test]
    public function a_receipt_can_be_taken_back_while_it_is_untouched(): void
    {
        $filters = $this->lotPart();
        $user = $this->userWith(Permissions::STOCK_CORRECT);

        app(ReceiveStock::class)->handle($filters, 4, '2025-07-01', lotData: $this->certified());
        $receipt = StockLot::sole()->movements()->sole();

        app(ReverseMovement::class)->handle($receipt, $user, 'Lieferschein gehörte zu einem anderen Verein');

        $this->assertSame(0.0, $filters->fresh()->currentStock());
        $this->assertSame(0.0, StockLot::sole()->remainingQuantity());
        $this->assertNotNull(StockLot::sole(), 'The lot stays, at nil.');
    }

    #[Test]
    public function a_correction_may_itself_be_corrected(): void
    {
        // A wrong correction needs fixing too, and refusing would leave the only
        // remedy outside the ledger.
        $nuts = $this->bulkPart();
        $user = $this->userWith(Permissions::STOCK_CORRECT);

        app(ReceiveStock::class)->handle($nuts, 500, '2025-07-01', lotData: $this->certified());
        $issue = app(IssueStock::class)->handle($nuts->fresh(), 200);

        $first = app(ReverseMovement::class)->handle($issue, $user, 'Falsch');
        $second = app(ReverseMovement::class)->handle($first, $user, 'Die Korrektur war falsch');

        $this->assertSame($first->id, $second->reverses_movement_id);
        $this->assertSame(300.0, $nuts->fresh()->currentStock(), 'Back where it started.');
    }

    #[Test]
    public function a_repair_dispatch_is_not_corrected_this_way(): void
    {
        // It has a path of its own that keeps the dispatch record straight.
        $release = $this->lotPart();
        $user = $this->userWith(Permissions::STOCK_CORRECT, Permissions::STOCK_REPAIR);

        app(ReceiveStock::class)->handle($release, 1, '2025-07-01', lotData: $this->certified());

        $dispatch = app(DispatchForRepair::class)->handle(
            $release, 1, StockLot::sole(), $user, 'Überholung', shopName: 'Musterwerft GmbH',
        );

        $movement = StockMovement::where('type', MovementType::RepairDispatch)->sole();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/path of their own/');

        app(ReverseMovement::class)->handle($movement, $user, 'Doch nicht');
    }

    #[Test]
    public function a_correction_needs_the_permission_and_a_reason(): void
    {
        $nuts = $this->bulkPart();

        app(ReceiveStock::class)->handle($nuts, 500, '2025-07-01', lotData: $this->certified());
        $issue = app(IssueStock::class)->handle($nuts->fresh(), 200);

        try {
            app(ReverseMovement::class)->handle($issue, $this->userWith(), 'Falsch');
            $this->fail('Without the permission this must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('stock.correct', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        app(ReverseMovement::class)->handle($issue, $this->userWith(Permissions::STOCK_CORRECT), '   ');
    }

    #[Test]
    public function the_database_itself_refuses_a_second_counter_booking(): void
    {
        // The backstop behind ReverseMovement's lock-and-recheck: even a path
        // that skips the action entirely cannot write two corrections against
        // one movement. Same construction as the release chain's unique on
        // supersedes_release_id, and for the same reason -- two parallel
        // "corrections" would move the stock twice for one mistake.
        $nuts = $this->bulkPart();
        $user = $this->userWith(Permissions::STOCK_CORRECT);

        app(ReceiveStock::class)->handle($nuts, 500, '2025-07-01', lotData: $this->certified());
        $issue = app(IssueStock::class)->handle($nuts->fresh(), 200, occurredAt: '2025-08-15');

        app(ReverseMovement::class)->handle($issue, $user, 'Falsch gebucht');

        $this->expectException(QueryException::class);

        StockMovement::create([
            'part_type_id' => $issue->part_type_id,
            'type' => MovementType::Correction,
            'quantity' => 200,
            'occurred_at' => now(),
            'user_id' => $user->id,
            'reverses_movement_id' => $issue->id,
            'note' => 'Zweiter Storno derselben Buchung',
        ]);
    }

    private function bulkPart(): PartType
    {
        return PartType::create([
            'name' => 'Mutter M6',
            'classification' => PartClassification::StandardPart,
            'unit_of_measure' => 'St',
        ]);
    }

    private function lotPart(): PartType
    {
        return PartType::create([
            'name' => 'Ölfilter Rotax 912',
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'St',
            'requires_form_one' => true,
        ]);
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function qualifiedUser(): User
    {
        $user = $this->userWith(Permissions::STOCK_CORRECT, Permissions::STOCK_SCRAP);

        Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.00000',
            'category' => 'B1',
            'valid_from' => now()->subYear()->toDateString(),
        ]);

        return $user->fresh();
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
}
