<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Support;

use App\Modules\Fleet\Models\Weighing;
use App\Modules\Fleet\Models\WeighingEntry;
use Illuminate\Support\Collection;

/**
 * Working out the permitted seat loads from a weighing.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS IS AND IS NOT.
 *
 * It reproduces the standard moment balance: with an empty mass M at arm x_e and
 * a load m at arm x_s, the loaded centre of gravity is
 *
 *     X = (M·x_e + m·x_s) / (M + m)
 *
 * and the permitted load is whatever keeps X between the in-flight limits and
 * the total under the maximum mass. Solved for m rather than searched, so the
 * boundary is exact rather than to the nearest step.
 *
 * It is NOT the flight manual. Manuals differ -- some give an envelope to read
 * off a graph, some a table, some a formula with terms this does not know about
 * (tail ballast, water, a fixed trim weight that comes out with the pilot). So
 * this is a DRAFT to check against the manual, and the printed sheet says so.
 * The rule from the AD module applies here too: the tool may add work, never
 * remove it.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class LoadingPlanCalculator
{
    /** Step size for the two-seat table, in kilograms. */
    private const STEP = 10;

    public function calculate(Weighing $weighing): LoadingPlan
    {
        $result = $weighing->result();
        $seats = $weighing->entriesOf('seat');

        $emptyMass = $result->emptyMassKg;
        $emptyCg = $result->emptyCgMm;
        $forward = $weighing->flight_cg_from_mm !== null ? (float) $weighing->flight_cg_from_mm : null;
        $aft = $weighing->flight_cg_to_mm !== null ? (float) $weighing->flight_cg_to_mm : null;

        if ($emptyMass <= 0 || $emptyCg === null || $forward === null || $aft === null || $seats->isEmpty()) {
            return new LoadingPlan(notes: [__('fleet.loading.missing_inputs')], computable: false);
        }

        $maxMass = $weighing->max_mass_kg !== null ? (float) $weighing->max_mass_kg : null;

        $rows = [];

        foreach ($seats as $seat) {
            $rows[] = $this->rangeFor(
                (string) $seat->label,
                (float) $seat->arm_mm,
                $emptyMass,
                $emptyCg,
                $forward,
                $aft,
                $maxMass,
            );
        }

        return new LoadingPlan(
            seats: $rows,
            combinations: $seats->count() >= 2
                ? $this->combinations($seats, $emptyMass, $emptyCg, $forward, $aft, $maxMass)
                : [],
            notes: [__('fleet.loading.check_manual')],
            computable: true,
        );
    }

    /**
     * The permitted load in one seat, with the other empty.
     *
     * @return array<string, mixed>
     */
    private function rangeFor(
        string $label,
        float $arm,
        float $emptyMass,
        float $emptyCg,
        float $forward,
        float $aft,
        ?float $maxMass,
    ): array {
        $atForward = $this->massForCg($emptyMass, $emptyCg, $arm, $forward);
        $atAft = $this->massForCg($emptyMass, $emptyCg, $arm, $aft);

        // Which limit produces the minimum and which the maximum depends on
        // where the seat sits relative to the empty CG. Sorting rather than
        // assuming means a seat behind the CG works as well as one in front.
        $candidates = array_values(array_filter([$atForward, $atAft], fn (?float $v): bool => $v !== null));
        sort($candidates);

        $min = max(0.0, $candidates[0] ?? 0.0);
        $max = $candidates[1] ?? ($candidates[0] ?? 0.0);

        $limitedBy = 'cg';

        if ($maxMass !== null && $emptyMass + $max > $maxMass) {
            $max = $maxMass - $emptyMass;
            $limitedBy = 'mass';
        }

        return [
            'seat' => $label,
            'arm' => $arm,
            'min' => round($min, 1),
            'max' => round(max(0.0, $max), 1),
            'limited_by' => $limitedBy,
        ];
    }

    /**
     * The load that puts the centre of gravity exactly on a limit.
     *
     *     X = (M·x_e + m·x_s) / (M + m)   solved for m
     *     m = M·(X − x_e) / (x_s − X)
     *
     * Null where the seat arm sits on the limit itself: then no load moves the
     * centre of gravity to it, and there is no answer to give.
     */
    private function massForCg(float $emptyMass, float $emptyCg, float $seatArm, float $target): ?float
    {
        $denominator = $seatArm - $target;

        if (abs($denominator) < 0.0001) {
            return null;
        }

        return $emptyMass * ($target - $emptyCg) / $denominator;
    }

    /**
     * For a two-seater: what the front seat may carry for a given rear load.
     *
     * A table rather than a formula, because that is what a loading plan looks
     * like in a cockpit -- somebody reads across from the weight of the person
     * in the back.
     *
     * @param  Collection<int, WeighingEntry>  $seats
     * @return list<array<string, mixed>>
     */
    private function combinations(
        $seats,
        float $emptyMass,
        float $emptyCg,
        float $forward,
        float $aft,
        ?float $maxMass,
    ): array {
        $front = $seats[0];
        $rear = $seats[1];

        $rows = [];

        for ($rearMass = 0; $rearMass <= 110; $rearMass += self::STEP) {
            // Treat the empty aircraft plus the rear occupant as the new
            // "empty" state, then ask the same question of the front seat.
            $mass = $emptyMass + $rearMass;
            $cg = $mass > 0
                ? ($emptyMass * $emptyCg + $rearMass * (float) $rear->arm_mm) / $mass
                : $emptyCg;

            $range = $this->rangeFor(
                (string) $front->label,
                (float) $front->arm_mm,
                $mass,
                $cg,
                $forward,
                $aft,
                $maxMass,
            );

            $rows[] = [
                'rear' => (float) $rearMass,
                'front_min' => $range['min'],
                'front_max' => $range['max'],
                'possible' => $range['max'] >= $range['min'] && $range['max'] > 0,
            ];
        }

        return $rows;
    }
}
