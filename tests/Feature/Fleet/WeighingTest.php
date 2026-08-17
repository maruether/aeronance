<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Fleet\Enums\WeighingKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\Weighing;
use App\Modules\Fleet\Models\WeighingEntry;
use App\Modules\Fleet\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The weighing sheet's arithmetic.
 *
 * Checked against figures worked out by hand, because this is the part somebody
 * will want to compare line by line with the paper -- and because a mass and
 * balance calculation that is quietly wrong is wrong in the worst possible way:
 * it looks like an answer.
 */
final class WeighingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_glider_adds_its_components_up(): void
    {
        $weighing = $this->gliderWeighing();

        $this->massRow($weighing, 'Tragwerk rechts innen', 62.4);
        $this->massRow($weighing, 'Tragwerk links innen', 62.1);
        $this->massRow($weighing, 'Rumpf mit Seitenruder', 118.3, nonLifting: 118.3);
        $this->massRow($weighing, 'Höhenleitwerk', 9.2, nonLifting: 9.2);

        $result = $weighing->fresh()->result();

        $this->assertSame(252.0, $result->emptyMassKg);
        $this->assertSame(127.5, $result->nonLiftingMassKg, 'Only the parts that do not lift.');
    }

    #[Test]
    public function the_centre_of_gravity_comes_out_where_the_hand_calculation_says(): void
    {
        // G1 = 180 kg at the front, G2 = 70 kg at the rear, supports 1400 mm
        // apart, datum 250 mm behind the front support.
        //
        //   X = (G2 * b) / G + a = (70 * 1400) / 250 + (-250) = 392 - 250 = 142
        $weighing = $this->gliderWeighing(frontArm: -250, distance: 1400);

        $this->massRow($weighing, 'Alles', 250.0);
        $this->support($weighing, 'Auflage vorn G1', gross: 190.0, tare: 10.0);
        $this->support($weighing, 'Auflage hinten G2', gross: 75.0, tare: 5.0);

        $this->assertSame(142.0, $weighing->fresh()->result()->emptyCgMm);
    }

    #[Test]
    public function the_two_printed_formulas_are_one_equation(): void
    {
        // The BWLV sheet draws "(G2*b)/G - a" and "(G2*b)/G + a" as two cases.
        // They differ only in where the datum sits, so a signed arm collapses
        // them -- and the same numbers with the sign flipped land symmetrically
        // either side of the bare quotient.
        $behind = $this->gliderWeighing(frontArm: -250, distance: 1400);
        $this->support($behind, 'G1', gross: 180.0, tare: 0.0);
        $this->support($behind, 'G2', gross: 70.0, tare: 0.0);

        $ahead = $this->gliderWeighing(frontArm: 250, distance: 1400);
        $this->support($ahead, 'G1', gross: 180.0, tare: 0.0);
        $this->support($ahead, 'G2', gross: 70.0, tare: 0.0);

        $quotient = (70.0 * 1400) / 250.0;

        $this->assertSame(round($quotient - 250, 2), $behind->fresh()->result()->emptyCgMm);
        $this->assertSame(round($quotient + 250, 2), $ahead->fresh()->result()->emptyCgMm);
    }

    #[Test]
    public function tare_comes_off_every_support(): void
    {
        $weighing = $this->gliderWeighing(frontArm: 0, distance: 1000);

        $this->support($weighing, 'G1', gross: 100.0, tare: 20.0);
        $this->support($weighing, 'G2', gross: 60.0, tare: 10.0);

        // Netto 80 and 50, total 130.  X = (50 * 1000) / 130 = 384.62
        $this->assertSame(384.62, $weighing->fresh()->result()->emptyCgMm);
    }

    #[Test]
    public function a_tare_bigger_than_the_gross_does_not_go_negative(): void
    {
        // A transcription error. Letting it through as a negative would pull the
        // total down quietly instead of showing up as the mistake it is.
        $weighing = $this->gliderWeighing();
        $entry = $this->support($weighing, 'G1', gross: 10.0, tare: 25.0);

        $this->assertSame(0.0, $entry->netto());
    }

    #[Test]
    public function a_powered_aircraft_has_its_usable_fuel_taken_off(): void
    {
        // 420 kg on the scales, 40 litres of petrol at 0.72 and 5 litres of oil
        // at 0.89 -> 420 - 28.8 - 4.45 = 386.75
        $weighing = $this->poweredWeighing();

        $this->support($weighing, 'G1l', gross: 150.0, tare: 0.0, arm: 1000.0);
        $this->support($weighing, 'G1r', gross: 150.0, tare: 0.0, arm: 1000.0);
        $this->support($weighing, 'G2', gross: 120.0, tare: 0.0, arm: 4000.0);

        $this->deduction($weighing, 'Rumpfbehälter I', litres: 40.0, density: 0.72, arm: 1500.0);
        $this->deduction($weighing, 'Schmierstoff', litres: 5.0, density: 0.89, arm: 900.0);

        $this->assertSame(386.75, $weighing->fresh()->result()->emptyMassKg);
    }

    #[Test]
    public function a_deduction_moves_the_centre_of_gravity_as_well_as_the_mass(): void
    {
        // Taking fuel off a wing tank shifts the CG. Subtracting only the mass
        // would put the empty CG out by exactly the amount that matters.
        $weighing = $this->poweredWeighing();

        $this->support($weighing, 'G1', gross: 300.0, tare: 0.0, arm: 1000.0);
        $this->support($weighing, 'G2', gross: 100.0, tare: 0.0, arm: 4000.0);

        $withoutFuel = $weighing->fresh()->result()->emptyCgMm;

        $this->deduction($weighing, 'Flügelbehälter', litres: 50.0, density: 0.72, arm: 3500.0);

        $withFuel = $weighing->fresh()->result()->emptyCgMm;

        $this->assertNotSame($withoutFuel, $withFuel);

        // (300*1000 + 100*4000 - 36*3500) / (400 - 36) = 574000 / 364 = 1576.92
        $this->assertSame(1576.92, $withFuel);
    }

    #[Test]
    public function a_centre_of_gravity_out_of_range_is_reported_and_still_saved(): void
    {
        // A weighing that comes out of range is a real measurement of a real
        // aircraft. Refusing to save it would leave the only copy on the sheet
        // in somebody's hand.
        $weighing = $this->gliderWeighing(frontArm: 0, distance: 1000);
        $weighing->update(['cg_range_from_mm' => 200, 'cg_range_to_mm' => 300]);

        $this->support($weighing, 'G1', gross: 100.0, tare: 0.0);
        $this->support($weighing, 'G2', gross: 100.0, tare: 0.0);

        $result = $weighing->fresh()->result();

        $this->assertSame(500.0, $result->emptyCgMm);
        $this->assertFalse($result->isAcceptable());
        $this->assertStringContainsString('außerhalb', $result->findings[0]);
    }

    #[Test]
    public function too_much_non_lifting_mass_is_reported(): void
    {
        $weighing = $this->gliderWeighing();
        $weighing->update(['max_non_lifting_kg' => 100]);

        $this->massRow($weighing, 'Rumpf', 130.0, nonLifting: 130.0);

        $result = $weighing->fresh()->result();

        $this->assertFalse($result->isAcceptable());
        $this->assertStringContainsString('nichttragenden', $result->findings[0]);
    }

    #[Test]
    public function the_useful_load_is_what_is_left_to_the_maximum(): void
    {
        $weighing = $this->gliderWeighing();
        $weighing->update(['max_mass_kg' => 600]);

        $this->massRow($weighing, 'Alles', 380.0);

        $this->assertSame(220.0, $weighing->fresh()->result()->usefulLoadKg);
    }

    #[Test]
    public function the_result_is_written_down_and_not_recomputed_later(): void
    {
        // A signed document keeps its numbers. Recomputing a 2019 report with
        // 2027 code would republish somebody's signature over a different answer.
        $weighing = $this->gliderWeighing();
        $this->massRow($weighing, 'Alles', 250.0);

        $weighing->fresh()->recalculate();

        $this->assertSame('250.00', $weighing->fresh()->empty_mass_kg);
    }

    #[Test]
    public function the_sheet_prints_with_its_result(): void
    {
        app(AccessSetup::class)->run();
        app(ModuleManager::class)->enable('fleet');
        app(ModuleManager::class)->forgetCache();

        $weighing = $this->gliderWeighing(frontArm: -250, distance: 1400);
        $weighing->update(['cg_range_from_mm' => 100, 'cg_range_to_mm' => 200]);

        $this->massRow($weighing, 'Rumpf', 118.3, nonLifting: 118.3);
        $this->support($weighing, 'Auflage vorn G1', gross: 190.0, tare: 10.0);
        $this->support($weighing, 'Auflage hinten G2', gross: 75.0, tare: 5.0);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::FLEET_VIEW);

        $this->actingAs($user->fresh())
            ->get(route('fleet.weighing', ['weighing' => $weighing]))
            ->assertSuccessful()
            ->assertSee('Massenübersicht', false)
            ->assertSee('142,0 mm hinter B.P.', false)
            // Die Tabellen des Blattes, nicht irgendeine Auflistung.
            ->assertSee(__('fleet.sheet.weighing'), false)
            ->assertSee(__('fleet.sheet.limits'), false)
            ->assertSee(__('fleet.sheet.cg_determination'), false)
            /*
             * Die bebilderte Erklaerung. Sie ist seit 0.1.10 die Zeichnung des
             * Blattes selbst und damit SYMBOLISCH -- so, wie sie auf Papier
             * steht. Vorher stand hier eine vorgerechnete Formel mit den Zahlen
             * dieser Waegung; die Zahl steht jetzt im Ergebnisbalken darueber,
             * und das Bild erklaert nur noch die Konvention.
             */
            ->assertSee('sheet/lever.png', false)
            ->assertSee(__('fleet.sheet.confirm.cg_in_range'), false);
    }

    #[Test]
    public function a_printed_sheet_that_is_out_of_range_says_so(): void
    {
        app(AccessSetup::class)->run();
        app(ModuleManager::class)->enable('fleet');
        app(ModuleManager::class)->forgetCache();

        $weighing = $this->gliderWeighing(frontArm: 0, distance: 1000);
        $weighing->update(['cg_range_from_mm' => 100, 'cg_range_to_mm' => 200]);

        $this->support($weighing, 'G1', gross: 100.0, tare: 0.0);
        $this->support($weighing, 'G2', gross: 100.0, tare: 0.0);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::FLEET_VIEW);

        $this->actingAs($user->fresh())
            ->get(route('fleet.weighing', ['weighing' => $weighing]))
            ->assertSuccessful()
            ->assertDontSee(__('fleet.sheet.confirm.cg_in_range'), false)
            ->assertSee('außerhalb', false);
    }

    private function aircraft(): Aircraft
    {
        return Aircraft::create([
            'registration' => 'D-K'.strtoupper(substr(uniqid(), -4)),
            'model' => 'ASK 21',
        ]);
    }

    private function gliderWeighing(int $frontArm = 0, int $distance = 1000): Weighing
    {
        return Weighing::create([
            'aircraft_id' => $this->aircraft()->id,
            'kind' => WeighingKind::Glider,
            'weighed_at' => now()->toDateString(),
            'front_support_arm_mm' => $frontArm,
            'support_distance_mm' => $distance,
        ]);
    }

    private function poweredWeighing(): Weighing
    {
        return Weighing::create([
            'aircraft_id' => $this->aircraft()->id,
            'kind' => WeighingKind::Powered,
            'weighed_at' => now()->toDateString(),
        ]);
    }

    private function massRow(Weighing $w, string $label, float $mass, ?float $nonLifting = null): WeighingEntry
    {
        return WeighingEntry::create([
            'weighing_id' => $w->id,
            'section' => WeighingEntry::SECTION_COMPONENT,
            'label' => $label,
            'mass_kg' => $mass,
            'non_lifting_kg' => $nonLifting,
        ]);
    }

    private function support(Weighing $w, string $label, float $gross, float $tare, ?float $arm = null): WeighingEntry
    {
        return WeighingEntry::create([
            'weighing_id' => $w->id,
            'section' => WeighingEntry::SECTION_SUPPORT,
            'label' => $label,
            'gross_kg' => $gross,
            'tare_kg' => $tare,
            'arm_mm' => $arm,
        ]);
    }

    private function deduction(Weighing $w, string $label, float $litres, float $density, ?float $arm = null): WeighingEntry
    {
        return WeighingEntry::create([
            'weighing_id' => $w->id,
            'section' => WeighingEntry::SECTION_DEDUCTION,
            'label' => $label,
            'volume_litres' => $litres,
            'density_kg_per_litre' => $density,
            'arm_mm' => $arm,
        ]);
    }
}
