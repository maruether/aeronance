<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Modules\Fleet\Enums\WeighingKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\Weighing;
use App\Modules\Fleet\Models\WeighingEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The loading plan: what may sit in the seat.
 *
 * The weighing says where the aircraft balances empty. This answers the question
 * the pilot actually has -- and it is solved rather than searched, so the
 * boundary is exact instead of to the nearest ten kilos.
 *
 * Checked against hand calculations throughout. A permitted seat load that is
 * quietly wrong is wrong in the direction of letting somebody heavy sit down.
 */
final class LoadingPlanTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_permitted_seat_load_matches_the_hand_calculation(): void
    {
        // Empty 250 kg at 300 mm, seat at 100 mm, in-flight range 250 to 350 mm.
        //
        //   m = M·(X − x_e) / (x_s − X)
        //   at 250: 250·(250−300)/(100−250) = 250·(−50)/(−150) = 83.33
        //   at 350: 250·(350−300)/(100−350) = 250·(50)/(−250)  = −50 -> 0
        //
        // So the seat may carry up to 83.3 kg, and there is no lower bound
        // beyond empty.
        $weighing = $this->weighing(emptyMass: 250, emptyCg: 300, forward: 250, aft: 350);
        $this->seat($weighing, 'Sitz', arm: 100);

        $plan = $weighing->fresh()->loadingPlan();

        $this->assertTrue($plan->computable);
        $this->assertSame(0.0, $plan->seats[0]['min']);
        $this->assertSame(83.3, $plan->seats[0]['max']);
    }

    #[Test]
    public function a_seat_behind_the_centre_of_gravity_works_the_other_way_round(): void
    {
        // Which limit gives the minimum and which the maximum depends on where
        // the seat sits. Assuming instead of sorting would have produced a
        // negative range here.
        $weighing = $this->weighing(emptyMass: 250, emptyCg: 300, forward: 250, aft: 350);
        $this->seat($weighing, 'Hintersitz', arm: 900);

        $plan = $weighing->fresh()->loadingPlan();

        //   at 250: 250·(250−300)/(900−250) = −19.2 -> 0
        //   at 350: 250·(350−300)/(900−350) = 22.7
        $this->assertSame(0.0, $plan->seats[0]['min']);
        $this->assertSame(22.7, $plan->seats[0]['max']);
    }

    #[Test]
    public function the_maximum_mass_can_be_the_binding_limit(): void
    {
        // And when it is, the plan says so -- "you may not put more in because
        // of the CG" and "because the aircraft would be too heavy" are different
        // problems with different remedies.
        $weighing = $this->weighing(emptyMass: 250, emptyCg: 300, forward: 250, aft: 350);
        $weighing->update(['max_mass_kg' => 300]);
        $this->seat($weighing, 'Sitz', arm: 100);

        $plan = $weighing->fresh()->loadingPlan();

        $this->assertSame(50.0, $plan->seats[0]['max'], 'Only 50 kg to the maximum mass.');
        $this->assertSame('mass', $plan->seats[0]['limited_by']);
    }

    #[Test]
    public function a_lower_bound_appears_when_the_empty_aircraft_is_out_of_range(): void
    {
        // A glider that is tail-heavy empty needs a minimum pilot. That is a
        // real and common case, and a plan that only ever reported a maximum
        // would hide it.
        $weighing = $this->weighing(emptyMass: 250, emptyCg: 400, forward: 250, aft: 350);
        $this->seat($weighing, 'Sitz', arm: 100);

        $plan = $weighing->fresh()->loadingPlan();

        //   at 350: 250·(350−400)/(100−350) = 50    -> minimum
        //   at 250: 250·(250−400)/(100−250) = 250   -> maximum
        $this->assertSame(50.0, $plan->seats[0]['min']);
        $this->assertSame(250.0, $plan->seats[0]['max']);
    }

    #[Test]
    public function a_two_seater_gets_a_table_to_read_across(): void
    {
        // What a loading plan looks like in a cockpit: somebody reads across
        // from the weight of the person in the back.
        $weighing = $this->weighing(emptyMass: 350, emptyCg: 300, forward: 250, aft: 350);
        $this->seat($weighing, 'Vordersitz', arm: 100);
        $this->seat($weighing, 'Hintersitz', arm: 900);

        $plan = $weighing->fresh()->loadingPlan();

        $this->assertNotEmpty($plan->combinations);
        $this->assertSame(0.0, $plan->combinations[0]['rear']);

        // More weight in the back moves the CG aft, so the front seat may -- and
        // must -- carry more.
        $first = $plan->combinations[0]['front_max'];
        $later = $plan->combinations[5]['front_max'];

        $this->assertGreaterThan($first, $later);
    }

    #[Test]
    public function without_the_flight_limits_it_declines_to_guess(): void
    {
        // The in-flight range is NOT the empty-mass range. Using the one for the
        // other would be wrong in the direction that lets somebody heavy sit
        // down, so an absent value produces no plan rather than a plausible one.
        $weighing = $this->weighing(emptyMass: 250, emptyCg: 300, forward: null, aft: null);
        $weighing->update(['cg_range_from_mm' => 250, 'cg_range_to_mm' => 350]);
        $this->seat($weighing, 'Sitz', arm: 100);

        $plan = $weighing->fresh()->loadingPlan();

        $this->assertFalse($plan->computable);
        $this->assertStringContainsString('Flughandbuch', $plan->notes[0]);
    }

    #[Test]
    public function it_says_the_flight_manual_is_the_authority(): void
    {
        // The rule from the AD module holds here too: the tool may add work,
        // never remove it. A manual may carry terms this does not know about.
        $weighing = $this->weighing(emptyMass: 250, emptyCg: 300, forward: 250, aft: 350);
        $this->seat($weighing, 'Sitz', arm: 100);

        $this->assertStringContainsString(
            'Flughandbuch bleibt maßgeblich',
            $weighing->fresh()->loadingPlan()->notes[0],
        );
    }

    #[Test]
    public function stored_figures_that_no_longer_match_the_rows_are_flagged(): void
    {
        // The other half of storing both inputs and result. Keeping the result
        // means a signed report keeps its numbers -- and it means the two can
        // drift, which must not happen silently.
        $weighing = $this->weighing(emptyMass: 250, emptyCg: 300, forward: 250, aft: 350);

        $this->assertTrue($weighing->fresh()->figuresMatchRows());

        // Somebody edits a row after it was signed.
        WeighingEntry::create([
            'weighing_id' => $weighing->id,
            'section' => WeighingEntry::SECTION_COMPONENT,
            'label' => 'Nachträglich',
            'mass_kg' => 12.0,
        ]);

        $this->assertFalse($weighing->fresh()->figuresMatchRows());
    }

    private function weighing(float $emptyMass, float $emptyCg, ?float $forward, ?float $aft): Weighing
    {
        $aircraft = Aircraft::create([
            'registration' => 'D-K'.strtoupper(substr(uniqid(), -4)),
            'model' => 'ASK 21',
        ]);

        $weighing = Weighing::create([
            'aircraft_id' => $aircraft->id,
            'kind' => WeighingKind::Glider,
            'weighed_at' => now()->toDateString(),
            'front_support_arm_mm' => 0,
            'support_distance_mm' => 1000,
            'flight_cg_from_mm' => $forward,
            'flight_cg_to_mm' => $aft,
        ]);

        // One component carrying the whole empty mass, and supports placing it
        // exactly at the wanted CG: G2/G = x_e / b.
        WeighingEntry::create([
            'weighing_id' => $weighing->id,
            'section' => WeighingEntry::SECTION_COMPONENT,
            'label' => 'Gesamt',
            'mass_kg' => $emptyMass,
        ]);

        $rear = $emptyMass * $emptyCg / 1000;

        WeighingEntry::create([
            'weighing_id' => $weighing->id,
            'section' => WeighingEntry::SECTION_SUPPORT,
            'label' => 'G1',
            'gross_kg' => $emptyMass - $rear,
            'tare_kg' => 0,
        ]);

        WeighingEntry::create([
            'weighing_id' => $weighing->id,
            'section' => WeighingEntry::SECTION_SUPPORT,
            'label' => 'G2',
            'gross_kg' => $rear,
            'tare_kg' => 0,
        ]);

        return $weighing->fresh()->load('entries')->recalculate() ? $weighing->fresh() : $weighing->fresh();
    }

    private function seat(Weighing $weighing, string $label, float $arm): WeighingEntry
    {
        return WeighingEntry::create([
            'weighing_id' => $weighing->id,
            'section' => WeighingEntry::SECTION_SEAT,
            'label' => $label,
            'arm_mm' => $arm,
        ]);
    }
}
