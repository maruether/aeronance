<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Core\Models\Qualification;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Fleet\Actions\FitComponent;
use App\Modules\Fleet\Enums\CounterKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\CounterReading;
use App\Modules\Fleet\Models\Installation;
use App\Modules\Warehouse\Enums\LifeLimitType;
use App\Modules\Warehouse\Enums\LotOrigin;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Taking a part off an aircraft puts it on the shelf.
 *
 * The return leg, and what it must NOT do is as interesting as what it does: it
 * goes through the warehouse's own removal action, so every rule that action
 * already enforces applies unchanged. A second door would mean a second set of
 * rules, and the second set is always the one that falls behind.
 */
final class ReturnLegTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([Permissions::STOCK_QUARANTINE_CERTIFY, Permissions::STOCK_ISSUE] as $p) {
            Permission::findOrCreate($p, 'web');
        }

        app(ModuleManager::class)->enable('warehouse');
        app(ModuleManager::class)->enable('fleet');
        app(ModuleManager::class)->forgetCache();
    }

    #[Test]
    public function a_removed_component_lands_on_the_shelf(): void
    {
        $instrument = $this->part();
        $installation = $this->fit($instrument);

        app(FitComponent::class)->remove(
            $installation, $this->qualifiedMechanic(), 'Umbau Instrumentenbrett',
            determinedServiceable: true,
        );

        $lot = StockLot::sole();

        $this->assertSame(LotOrigin::Removal, $lot->origin);
        $this->assertSame('D-KABC', $lot->removed_from_aircraft);
        $this->assertSame('ASK 21', $lot->removed_from_aircraft_type);
        $this->assertSame('VAR-8891', $lot->serial_number);
        $this->assertSame(LotState::Serviceable, $lot->state);
        $this->assertSame(1.0, $instrument->fresh()->currentStock());
    }

    #[Test]
    public function without_a_determination_it_lands_in_quarantine(): void
    {
        // The warehouse's rule, unchanged: a part nobody has judged is a part of
        // unknown condition.
        $instrument = $this->part();
        $installation = $this->fit($instrument);

        app(FitComponent::class)->remove(
            $installation, $this->qualifiedMechanic(), 'Ausgebaut, noch nicht geprüft',
        );

        $this->assertSame(LotState::Quarantined, StockLot::sole()->state);
    }

    #[Test]
    public function the_aircraft_restriction_comes_along(): void
    {
        // Straight out of the warehouse's own rule -- without a Form 1 the lot
        // goes back only into the aircraft it came from. Nothing about it is
        // restated on this path.
        $instrument = $this->part();
        $installation = $this->fit($instrument);

        app(FitComponent::class)->remove(
            $installation, $this->qualifiedMechanic(), 'Umbau', determinedServiceable: true,
        );

        $lot = StockLot::sole();

        $this->assertTrue($lot->isRestrictedToItsAircraft());
        $this->assertTrue($lot->mayBeFittedTo('D-KABC'));
        $this->assertFalse($lot->mayBeFittedTo('D-KXYZ'));
    }

    #[Test]
    public function a_replacement_interval_part_comes_off_but_does_not_come_back(): void
    {
        // The refusal that matters most here, because it must not break the
        // removal. Spark plugs are replaced, not recovered -- the fleet's record
        // that they came off is right whether or not they are worth keeping.
        $plugs = PartType::create([
            'name' => 'Zündkerze NGK',
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'St',
            'life_limit_type' => LifeLimitType::Tbr,
        ]);

        $installation = $this->fit($plugs, serial: null);

        app(FitComponent::class)->remove(
            $installation, $this->qualifiedMechanic(), 'Zündkerzenwechsel',
            determinedServiceable: true,
        );

        $this->assertSame(0, StockLot::count(), 'No way back onto the shelf.');
        $this->assertNotNull($installation->fresh()->removed_at, 'But it did come off.');
    }

    #[Test]
    public function a_part_that_the_store_never_knew_is_left_alone(): void
    {
        // A life record may contain something entered by hand that has no part
        // type at all. Nothing to book against, and that is not an error.
        $aircraft = $this->aircraft();

        $installation = Installation::create([
            'aircraft_id' => $aircraft->id,
            'part_name' => 'Handeingetragenes Teil',
            'installed_at' => now()->subYear()->toDateString(),
        ]);

        app(FitComponent::class)->remove(
            $installation, $this->qualifiedMechanic(), 'Ausgebaut', determinedServiceable: true,
        );

        $this->assertSame(0, StockLot::count());
        $this->assertNotNull($installation->fresh()->removed_at);
    }

    #[Test]
    public function the_fleet_works_perfectly_well_without_a_warehouse(): void
    {
        $instrument = $this->part();
        $installation = $this->fit($instrument);

        app(ModuleManager::class)->disable('warehouse');
        app(ModuleManager::class)->forgetCache();

        app(FitComponent::class)->remove(
            $installation, $this->qualifiedMechanic(), 'Umbau', determinedServiceable: true,
        );

        $this->assertSame(0, StockLot::count());
        $this->assertNotNull($installation->fresh()->removed_at);
    }

    #[Test]
    public function the_round_trip_keeps_the_operating_times(): void
    {
        // Off the aircraft, onto the shelf, back on again. The times live in the
        // fleet and are found by serial number -- they never travelled to the
        // warehouse and never needed to, which is why the lot carries no hours.
        $instrument = $this->part();

        // The counter is being KEPT from the start: without a counted baseline
        // at fitting, the 400 later would be a figure with nothing to measure
        // it against -- and usage() rightly answers "unknown" instead of
        // gifting the part the whole reading.
        $this->reading($this->aircraft(), 0.0);

        $installation = $this->fit($instrument);

        $this->reading($installation->aircraft, 400.0);

        $mechanic = $this->qualifiedMechanic();
        app(FitComponent::class)->remove($installation, $mechanic, 'Umbau', determinedServiceable: true);

        $refitted = app(FitComponent::class)->handle(
            $installation->aircraft->fresh(),
            'Variometer',
            $mechanic,
            attributes: ['serial_number' => 'VAR-8891', 'part_type_id' => $instrument->id],
        );

        $this->assertSame(
            400.0,
            $refitted->timeSinceNew(CounterKind::FlightHours),
            'Its history came back with it, from the fleet and not from the lot.',
        );
    }

    #[Test]
    public function a_removal_still_needs_a_reason(): void
    {
        $installation = $this->fit($this->part());

        $this->expectException(\InvalidArgumentException::class);

        app(FitComponent::class)->remove($installation, $this->qualifiedMechanic(), '  ');
    }

    private function aircraft(): Aircraft
    {
        return Aircraft::firstOrCreate(
            ['registration' => 'D-KABC'],
            ['model' => 'ASK 21'],
        );
    }

    private function part(): PartType
    {
        return PartType::create([
            'name' => 'Variometer',
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'St',
        ]);
    }

    private function fit(PartType $part, ?string $serial = 'VAR-8891'): Installation
    {
        return Installation::create([
            'aircraft_id' => $this->aircraft()->id,
            'part_name' => $part->name,
            'part_type_id' => $part->id,
            'serial_number' => $serial,
            'quantity' => 1,
            'installed_at' => now()->subYear()->toDateString(),
            'counters_at_installation' => $this->aircraft()->currentValues(),
        ]);
    }

    private function reading(Aircraft $aircraft, float $hours): void
    {
        CounterReading::create([
            'aircraft_id' => $aircraft->id,
            'kind' => CounterKind::FlightHours,
            'value' => $hours,
            'read_at' => now()->toDateString(),
        ]);
    }

    private function qualifiedMechanic(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::STOCK_QUARANTINE_CERTIFY);

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
