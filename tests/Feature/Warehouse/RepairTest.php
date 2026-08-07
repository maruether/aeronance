<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\Warehouse\Actions\ChangeLotState;
use App\Modules\Warehouse\Actions\DispatchForRepair;
use App\Modules\Warehouse\Actions\ReceiveFromRepair;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Actions\RemovePartFromAircraft;
use App\Modules\Warehouse\Enums\LotOrigin;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\MovementType;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Enums\RepairDestination;
use App\Modules\Warehouse\Enums\RepairState;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\RepairDispatch;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Sending a part away to be repaired, and booking back what returns.
 *
 * The case that gives this its weight: no club holds a component rating, so a
 * part tied to one aircraft can only be freed by someone who does. Sending it
 * away and getting a Form 1 back is not a workaround -- it is the route.
 */
final class RepairTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([Permissions::STOCK_QUARANTINE_CERTIFY, Permissions::STOCK_ISSUE, Permissions::STOCK_REPAIR] as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    #[Test]
    public function a_part_goes_away_and_leaves_a_thread_attached(): void
    {
        // The gap this closes: booked out as an ordinary issue, the part would
        // vanish from the books the moment it went into the parcel.
        $release = $this->part(serialTracked: true);
        $user = $this->storeman();

        app(ReceiveStock::class)->handle($release, 1, '2025-07-01', lotData: [
            'serial_number' => '1378X5V',
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2025-0001',
        ]);

        $lot = StockLot::sole();

        $dispatch = app(DispatchForRepair::class)->handle(
            $release, 1, $lot, $user, 'Überholung fällig',
            shopName: 'Musterwerft GmbH',
            shopApproval: 'DE.145.0123',
        );

        $this->assertSame(RepairState::Dispatched, $dispatch->state);
        $this->assertSame('1378X5V', $dispatch->serial_number, 'The serial travels with the record.');
        $this->assertSame(0.0, $release->fresh()->currentStock(), 'It is off the shelf.');
        $this->assertTrue($dispatch->state->isOpen());
        $this->assertSame(1, RepairDispatch::open()->count());
    }

    #[Test]
    public function a_quarantined_part_may_be_sent_which_is_the_whole_point(): void
    {
        // IssueStock would refuse exactly these. A repair action that inherited
        // that check would refuse every part that needs repairing.
        $release = $this->part();
        $user = $this->storeman();

        app(ReceiveStock::class)->handle($release, 1, '2025-07-01', lotData: $this->certified());
        $lot = StockLot::sole();

        $lot = $this->quarantine($lot);
        $this->assertSame(LotState::Quarantined, $lot->state);

        $dispatch = app(DispatchForRepair::class)->handle(
            $release, 1, $lot, $user, 'Zur Prüfung', shopName: 'Musterwerft GmbH',
        );

        $this->assertSame(RepairState::Dispatched, $dispatch->state);
    }

    #[Test]
    public function a_part_determined_beyond_repair_may_not_be_sent(): void
    {
        // The one refusal that matters: a repair round trip would be the way
        // back into the supply system that 145.A.42 exists to prevent.
        $release = $this->part();
        $user = $this->storeman();

        app(ReceiveStock::class)->handle($release, 1, '2025-07-01', lotData: $this->certified());
        $lot = StockLot::sole();
        $lot->update(['state' => LotState::Unsalvageable]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must not re-enter the supply system/');

        app(DispatchForRepair::class)->handle(
            $release->fresh(), 1, $lot->fresh(), $user, 'Doch noch versuchen',
            shopName: 'Musterwerft GmbH',
        );
    }

    #[Test]
    public function a_form_one_from_the_shop_discharges_the_aircraft_restriction(): void
    {
        // THE point of the whole feature. Out of D-KABC, tied to D-KABC; away to
        // an approved shop; back with their certificate; free to go anywhere.
        $instrument = $this->part();
        $mechanic = $this->qualifiedMechanic();

        $removed = app(RemovePartFromAircraft::class)->handle(
            $instrument, 1, 'D-KABC', $mechanic, 'Defekt', determinedServiceable: false,
        );

        $this->assertTrue($removed->isRestrictedToItsAircraft());

        $dispatch = app(DispatchForRepair::class)->handle(
            $instrument->fresh(), 1, $removed->fresh(), $mechanic, 'Instandsetzung',
            shopName: 'Musterwerft GmbH', shopApproval: 'DE.145.0123',
        );

        $this->assertSame('D-KABC', $dispatch->restricted_to_aircraft, 'The tie travels along.');
        $this->assertTrue($dispatch->carriesAircraftRestriction());

        $back = app(ReceiveFromRepair::class)->handle($dispatch, $mechanic, lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2025-9911',
        ]);

        $this->assertSame(LotOrigin::Repair, $back->origin);
        $this->assertSame(LotState::Serviceable, $back->state);
        $this->assertFalse($back->isRestrictedToItsAircraft());
        $this->assertTrue($back->mayBeFittedTo('D-KXYZ'), 'Certified, so it travels.');
        $this->assertSame('Musterwerft GmbH', $back->document_issuer);
        $this->assertSame('DE.145.0123', $back->document_issuer_approval);
    }

    #[Test]
    public function without_a_form_one_nothing_has_changed(): void
    {
        // A shop may return a part untouched or with a quote instead of a
        // certificate. That has to be bookable -- and it must not quietly free
        // the part.
        $instrument = $this->part();
        $mechanic = $this->qualifiedMechanic();

        $removed = app(RemovePartFromAircraft::class)->handle(
            $instrument, 1, 'D-KABC', $mechanic, 'Defekt', determinedServiceable: false,
        );

        $dispatch = app(DispatchForRepair::class)->handle(
            $instrument->fresh(), 1, $removed->fresh(), $mechanic, 'Instandsetzung',
            shopName: 'Musterwerft GmbH',
        );

        $back = app(ReceiveFromRepair::class)->handle($dispatch, $mechanic, note: 'Kostenvoranschlag beiliegend');

        $this->assertSame(LotState::Quarantined, $back->state);
        $this->assertSame('D-KABC', $back->removed_from_aircraft);
        $this->assertTrue($back->isRestrictedToItsAircraft());
        $this->assertFalse($back->mayBeFittedTo('D-KXYZ'));
    }

    #[Test]
    public function what_returns_is_a_new_lot_not_the_old_one(): void
    {
        // A lot is a quantity covered by ONE certificate. Reviving the old lot
        // would attach the new paper to the old record and rewrite what the
        // part's evidence used to be.
        $release = $this->part(serialTracked: true);
        $user = $this->storeman();

        app(ReceiveStock::class)->handle($release, 1, '2025-07-01', lotData: [
            'serial_number' => '1378X5V',
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2025-0001',
        ]);

        $original = StockLot::sole();

        $dispatch = app(DispatchForRepair::class)->handle(
            $release, 1, $original, $user, 'Überholung', shopName: 'Musterwerft GmbH',
        );

        $back = app(ReceiveFromRepair::class)->handle($dispatch, $user, lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2025-9911',
        ]);

        $this->assertNotSame($original->id, $back->id);
        $this->assertSame('F1-2025-0001', $original->fresh()->document_reference, 'Untouched.');
        $this->assertSame('F1-2025-9911', $back->document_reference);
        $this->assertSame('1378X5V', $back->serial_number, 'Same physical part.');
        $this->assertSame($dispatch->id, $back->repair_dispatch_id, 'The chain stays walkable.');
        $this->assertSame(0.0, $original->fresh()->remainingQuantity());
        $this->assertSame(1.0, $back->remainingQuantity());
        $this->assertSame(1.0, $release->fresh()->currentStock());
    }

    #[Test]
    public function the_ledger_names_both_directions(): void
    {
        $release = $this->part();
        $user = $this->storeman();

        app(ReceiveStock::class)->handle($release, 1, '2025-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2025-0001',
        ]);

        $dispatch = app(DispatchForRepair::class)->handle(
            $release, 1, StockLot::sole(), $user, 'Überholung', shopName: 'Musterwerft GmbH',
        );
        app(ReceiveFromRepair::class)->handle($dispatch, $user, lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2025-9911',
        ]);

        $types = $release->fresh()->movements()->pluck('type')->all();

        $this->assertContains(MovementType::RepairDispatch, $types);
        $this->assertContains(MovementType::RepairReturn, $types);
        $this->assertTrue(MovementType::RepairReturn->isInbound());
        $this->assertFalse(MovementType::RepairDispatch->isInbound());
        $this->assertTrue(MovementType::RepairDispatch->isTemporaryAbsence());
    }

    #[Test]
    public function a_dispatch_that_never_comes_back_can_be_closed(): void
    {
        $release = $this->part();
        $user = $this->storeman();

        app(ReceiveStock::class)->handle($release, 1, '2025-07-01', lotData: $this->certified());

        $dispatch = app(DispatchForRepair::class)->handle(
            $release, 1, StockLot::sole(), $user, 'Prüfung', shopName: 'Musterwerft GmbH',
        );

        $closed = app(ReceiveFromRepair::class)->writeOff($dispatch, $user, 'Betrieb meldet Totalschaden');

        $this->assertSame(RepairState::WrittenOff, $closed->state);
        $this->assertSame(0, RepairDispatch::open()->count());
        $this->assertSame(0.0, $release->fresh()->currentStock(), 'It left at dispatch and stays gone.');

        $this->expectException(RuntimeException::class);
        app(ReceiveFromRepair::class)->handle($closed->fresh(), $user);
    }

    #[Test]
    public function the_in_house_shop_needs_a_module_that_does_not_exist_yet(): void
    {
        // The seam. the starting assumption is that nobody holds a
        // component rating, so today there is exactly one destination.
        $release = $this->part();
        $user = $this->storeman();

        app(ReceiveStock::class)->handle($release, 1, '2025-07-01', lotData: $this->certified());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/component-repair/');

        app(DispatchForRepair::class)->handle(
            $release, 1, StockLot::sole(), $user, 'Intern instand setzen',
            destination: RepairDestination::InHouse,
        );
    }

    #[Test]
    public function an_outside_dispatch_without_a_shop_is_a_parcel_to_nowhere(): void
    {
        $release = $this->part();
        $user = $this->storeman();

        app(ReceiveStock::class)->handle($release, 1, '2025-07-01', lotData: $this->certified());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/who will\s+certify/');

        app(DispatchForRepair::class)->handle($release, 1, StockLot::sole(), $user, 'Weg damit');
    }

    #[Test]
    public function a_dispatch_cannot_exceed_what_the_lot_holds(): void
    {
        $filters = $this->part();
        $user = $this->storeman();

        app(ReceiveStock::class)->handle($filters, 2, '2025-07-01', lotData: $this->certified());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/holds only 2/');

        app(DispatchForRepair::class)->handle(
            $filters, 4, StockLot::sole(), $user, 'Zu viel', shopName: 'Musterwerft GmbH',
        );
    }

    #[Test]
    public function an_overdue_dispatch_can_be_found(): void
    {
        // Nobody remembers a part that has been away eight months, and that is
        // exactly when it gets written off in everyone's head while still
        // standing in the books.
        $release = $this->part();
        $user = $this->storeman();

        app(ReceiveStock::class)->handle($release, 1, '2025-07-01', lotData: $this->certified());

        $dispatch = app(DispatchForRepair::class)->handle(
            $release, 1, StockLot::sole(), $user, 'Überholung',
            shopName: 'Musterwerft GmbH',
            expectedBackAt: now()->subMonths(3)->toDateString(),
            dispatchedAt: now()->subMonths(6)->toDateString(),
        );

        $this->assertTrue($dispatch->fresh()->isOverdue());
        $this->assertSame(1, RepairDispatch::overdue()->count());
    }

    private function part(bool $serialTracked = false): mixed
    {
        return PartType::create([
            'name' => 'Schleppkupplung '.uniqid(),
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'St',
            'serial_tracked' => $serialTracked,
            'requires_form_one' => true,
        ]);
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function storeman(): User
    {
        return $this->userWith(Permissions::STOCK_REPAIR);
    }

    private function qualifiedMechanic(): User
    {
        $user = $this->userWith(
            Permissions::STOCK_REPAIR,
            Permissions::STOCK_ISSUE,
            Permissions::STOCK_QUARANTINE_CERTIFY,
        );

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

    /**
     * Ein Los sperren -- ausdruecklich, nicht als Nebenwirkung.
     *
     * Frueher entstand ein gesperrtes Los beim Wareneingang ohne Papier. Das
     * geht nicht mehr: Ohne Form 1 wird gar nicht erst eingebucht ("vorher
     * liegt es im wareneingang und ist noch nicht verbucht"). Gesperrt wird
     * jetzt, was IM LAGER ist -- und dafuer braucht es einen Grund und
     * jemanden, der ihn nennt.
     */
    private function quarantine(StockLot $lot, ?string $grund = null): StockLot
    {
        Permission::findOrCreate(Permissions::STOCK_QUARANTINE, 'web');

        app(ChangeLotState::class)->handle(
            $lot,
            LotState::Quarantined,
            $grund ?? 'Verdacht auf Transportschaden',
            $this->userWith(Permissions::STOCK_QUARANTINE),
        );

        return $lot->fresh();
    }
}
