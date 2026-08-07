<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\Warehouse\Actions\IssueStock;
use App\Modules\Warehouse\Actions\RemovePartFromAircraft;
use App\Modules\Warehouse\Enums\LifeLimitType;
use App\Modules\Warehouse\Enums\LotOrigin;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\LotStateChange;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Parts taken out of an aircraft and put into the store.
 *
 * Three rules are under test, and each answers a question the research raised:
 * whether the part was serviceable is a determination rather than a checkbox;
 * replacement-interval parts have no way back; and without a Form 1 a removed
 * part goes back into the aircraft it came from and nowhere else.
 */
final class RemovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([Permissions::STOCK_QUARANTINE_CERTIFY, Permissions::STOCK_ISSUE] as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    #[Test]
    public function a_removed_instrument_becomes_a_lot_of_its_own(): void
    {
        $instrument = $this->part(serialTracked: true);
        $mechanic = $this->qualifiedMechanic();

        $lot = app(RemovePartFromAircraft::class)->handle(
            $instrument, 1, 'D-KABC', $mechanic, 'Umbau Instrumentenbrett',
            determinedServiceable: true,
            aircraftType: 'ASK 21',
            lotData: ['serial_number' => 'VAR-8891'],
        );

        $this->assertSame(LotOrigin::Removal, $lot->origin);
        $this->assertSame('D-KABC', $lot->removed_from_aircraft);
        $this->assertSame('ASK 21', $lot->removed_from_aircraft_type);
        $this->assertSame('VAR-8891', $lot->serial_number);
        $this->assertSame(1.0, $lot->remainingQuantity());
    }

    #[Test]
    public function declaring_it_serviceable_is_a_determination_and_is_frozen(): void
    {
        // Same mechanism as declaring something unusable, only the other way
        // round -- and equally something somebody answers for.
        $instrument = $this->part();
        $mechanic = $this->qualifiedMechanic('DE.66.12345', 'B1.2');

        $lot = app(RemovePartFromAircraft::class)->handle(
            $instrument, 1, 'D-KABC', $mechanic, 'Umbau', determinedServiceable: true,
        );

        $this->assertSame(LotState::Serviceable, $lot->state);

        $change = LotStateChange::where('stock_lot_id', $lot->id)->sole();
        $this->assertTrue($change->isDetermination());
        $this->assertSame('DE.66.12345', $change->qualification_reference);
        $this->assertSame('B1.2', $change->qualification_category);
        $this->assertSame('D-KABC', $change->aircraft_reference);
    }

    #[Test]
    public function without_a_licence_it_cannot_be_declared_serviceable(): void
    {
        $instrument = $this->part();
        $storeman = $this->userWith(Permissions::STOCK_QUARANTINE_CERTIFY);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/qualified staff/');

        app(RemovePartFromAircraft::class)->handle(
            $instrument, 1, 'D-KABC', $storeman, 'Umbau', determinedServiceable: true,
        );
    }

    #[Test]
    public function it_can_still_be_taken_in_without_a_determination(): void
    {
        // Refusing the booking would leave the part off the books entirely,
        // which is worse than recording it as being of unknown condition.
        $instrument = $this->part();
        $storeman = $this->userWith();

        $lot = app(RemovePartFromAircraft::class)->handle(
            $instrument, 1, 'D-KABC', $storeman, 'Ausgebaut, noch nicht geprüft',
            determinedServiceable: false,
        );

        $this->assertSame(LotState::Quarantined, $lot->state);
        $this->assertFalse($lot->isIssuable());
        $this->assertSame(1.0, $instrument->fresh()->currentStock(), 'It is in the building.');
        $this->assertSame(0.0, $instrument->fresh()->availableStock());
    }

    #[Test]
    public function a_replacement_interval_part_has_no_way_back(): void
    {
        // Spark plugs and hoses are replaced, not recovered. Letting one onto
        // the shelf invites it being fitted again.
        $plugs = $this->part(lifeLimit: LifeLimitType::Tbr);
        $mechanic = $this->qualifiedMechanic();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/replacement interval/');

        app(RemovePartFromAircraft::class)->handle(
            $plugs, 4, 'D-KABC', $mechanic, 'Zündkerzenwechsel', determinedServiceable: true,
        );
    }

    #[Test]
    public function an_overhaul_interval_part_may_come_back(): void
    {
        // The tow release: overhauled and fitted again, and exactly why this
        // exists at all. A flat "life-limited means blocked" would have caught
        // it along with the spark plugs.
        $release = $this->part(lifeLimit: LifeLimitType::Tbo, serialTracked: true);
        $mechanic = $this->qualifiedMechanic();

        $lot = app(RemovePartFromAircraft::class)->handle(
            $release, 1, 'D-KABC', $mechanic, 'Zur Überholung ausgebaut',
            determinedServiceable: true,
            lotData: ['serial_number' => '1378X5V'],
        );

        $this->assertSame(LotState::Serviceable, $lot->state);
        $this->assertSame('1378X5V', $lot->serial_number);
    }

    #[Test]
    public function without_a_form_one_it_goes_back_only_into_its_own_aircraft(): void
    {
        // THE rule from the research. A removal record proves the part was
        // serviceable when it came out and nothing more; moving it elsewhere
        // needs a certificate the club cannot issue.
        $instrument = $this->part();
        $mechanic = $this->qualifiedMechanic();

        $lot = app(RemovePartFromAircraft::class)->handle(
            $instrument, 1, 'D-KABC', $mechanic, 'Umbau', determinedServiceable: true,
        );

        $this->assertTrue($lot->mayBeFittedTo('D-KABC'));
        $this->assertFalse($lot->mayBeFittedTo('D-KXYZ'));
        $this->assertTrue($lot->isRestrictedToItsAircraft());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/may only go back into that aircraft/');

        app(IssueStock::class)->handle(
            $instrument->fresh(), 1, $lot->fresh(), $mechanic, aircraftReference: 'D-KXYZ',
        );
    }

    #[Test]
    public function it_may_be_fitted_back_into_the_aircraft_it_came_from(): void
    {
        $instrument = $this->part();
        $mechanic = $this->qualifiedMechanic();

        $lot = app(RemovePartFromAircraft::class)->handle(
            $instrument, 1, 'D-KABC', $mechanic, 'Winterlagerung', determinedServiceable: true,
        );

        app(IssueStock::class)->handle(
            $instrument->fresh(), 1, $lot->fresh(), $mechanic, aircraftReference: 'D-KABC',
        );

        $this->assertSame(0.0, $lot->fresh()->remainingQuantity());
    }

    #[Test]
    public function a_form_one_lifts_the_restriction(): void
    {
        // If an organisation with a component rating does issue a certificate,
        // the part travels like any other certified one.
        $instrument = $this->part();
        $mechanic = $this->qualifiedMechanic();

        $lot = app(RemovePartFromAircraft::class)->handle(
            $instrument, 1, 'D-KABC', $mechanic, 'Umbau', determinedServiceable: true,
        );

        $lot->update([
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2025-4711',
        ]);

        $this->assertTrue($lot->fresh()->mayBeFittedTo('D-KXYZ'));
        $this->assertFalse($lot->fresh()->isRestrictedToItsAircraft());
    }

    #[Test]
    public function a_bought_lot_is_not_restricted_at_all(): void
    {
        $instrument = $this->part();

        $lot = StockLot::create([
            'part_type_id' => $instrument->id,
            'lot_number' => '202507-999',
            'received_at' => '2025-07-01',
        ]);

        $this->assertSame(LotOrigin::Supplier, $lot->origin);
        $this->assertTrue($lot->mayBeFittedTo('D-KXYZ'));
    }

    #[Test]
    public function no_expiry_date_is_invented_for_a_removed_part(): void
    {
        // A shelf life runs from manufacture or delivery, not from the day
        // something came out of an aircraft.
        $instrument = $this->part(shelfLifeDays: 365);
        $mechanic = $this->qualifiedMechanic();

        $lot = app(RemovePartFromAircraft::class)->handle(
            $instrument, 1, 'D-KABC', $mechanic, 'Umbau', determinedServiceable: true,
        );

        $this->assertNull($lot->expires_at);
    }

    #[Test]
    public function the_aircraft_and_a_reason_are_required(): void
    {
        $instrument = $this->part();
        $mechanic = $this->qualifiedMechanic();

        try {
            app(RemovePartFromAircraft::class)->handle(
                $instrument, 1, '  ', $mechanic, 'Umbau', determinedServiceable: true,
            );
            $this->fail('An empty registration must be refused.');
        } catch (InvalidArgumentException) {
        }

        try {
            app(RemovePartFromAircraft::class)->handle(
                $instrument, 1, 'D-KABC', $mechanic, '  ', determinedServiceable: true,
            );
            $this->fail('An empty reason must be refused.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame(0, StockLot::count());
    }

    private function part(
        ?LifeLimitType $lifeLimit = null,
        bool $serialTracked = false,
        ?int $shelfLifeDays = null,
    ): PartType {
        return PartType::create([
            'name' => 'Teil '.uniqid(),
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'St',
            'serial_tracked' => $serialTracked,
            'shelf_life_days' => $shelfLifeDays,
            'life_limit_type' => $lifeLimit ?? LifeLimitType::None,
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

    private function qualifiedMechanic(string $reference = 'DE.66.00000', string $category = 'B1'): User
    {
        $user = $this->userWith(Permissions::STOCK_QUARANTINE_CERTIFY, Permissions::STOCK_ISSUE);

        Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => $reference,
            'category' => $category,
            'valid_from' => now()->subYear()->toDateString(),
        ]);

        return $user->fresh();
    }
}
