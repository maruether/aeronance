<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Support;

use App\Modules\Fleet\Enums\WeighingKind;
use App\Modules\Fleet\Models\Weighing;
use App\Modules\Fleet\Models\WeighingEntry;

/**
 * The arithmetic of a weighing sheet.
 *
 * Kept out of the model on purpose: this is the part somebody will want to check
 * line by line against the paper, and it should be readable without knowing
 * anything about Eloquent.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE TWO PRINTED FORMULAS ARE ONE FORMULA.
 *
 * The glider sheet draws
 *
 *     X = (G2 · b) / G − a        and        X = (G2 · b) / G + a
 *
 * with two little diagrams, which makes them look like two cases to choose
 * between. They differ only in whether the datum sits ahead of the front support
 * or behind it. With the arm to the front support carried SIGNED, it is
 *
 *     X = (G2 · b) / G + a
 *
 * and the sign does the choosing. Two boxes on the paper, one equation here, and
 * one fewer thing for somebody to pick wrongly at eleven at night.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class WeighingCalculator
{
    public function calculate(Weighing $weighing): WeighingResult
    {
        return $weighing->kind === WeighingKind::Glider
            ? $this->glider($weighing)
            : $this->powered($weighing);
    }

    /**
     * A glider: the components add up, and the supports place the result.
     */
    private function glider(Weighing $weighing): WeighingResult
    {
        $components = $weighing->entriesOf('component');

        $emptyMass = $components->sum(fn (WeighingEntry $e): float => (float) $e->mass_kg);

        /*
         * The second column. The mass of the non-lifting parts has a limit of
         * its own on the type certificate, which is why the sheet carries it
         * alongside rather than deriving it -- a wing is lifting, a fuselage is
         * not, and no arithmetic on the totals can tell them apart.
         */
        $nonLifting = $components->whereNotNull('non_lifting_kg')->isEmpty()
            ? null
            : $components->sum(fn (WeighingEntry $e): float => (float) $e->non_lifting_kg);

        $cg = $this->centreOfGravity($weighing);

        return new WeighingResult(
            emptyMassKg: round($emptyMass, 2),
            emptyCgMm: $cg,
            nonLiftingMassKg: $nonLifting !== null ? round($nonLifting, 2) : null,
            usefulLoadKg: $this->usefulLoad($weighing, $emptyMass),
            findings: $this->findings($weighing, $emptyMass, $cg, $nonLifting),
        );
    }

    /**
     * An aeroplane or motor glider: the supports weigh it, the tanks come off.
     */
    private function powered(Weighing $weighing): WeighingResult
    {
        $supports = $weighing->entriesOf('support');

        // Summe I -- what stood on the scales.
        $gross = $supports->sum(fn (WeighingEntry $e): float => $e->netto());

        // Summe II -- what can be flown off, so it is not part of the empty mass.
        $deductions = $weighing->entriesOf('deduction')
            ->sum(fn (WeighingEntry $e): float => $e->deductedMass());

        $emptyMass = $gross - $deductions;

        $cg = $this->momentCentreOfGravity($weighing, $emptyMass);

        return new WeighingResult(
            emptyMassKg: round($emptyMass, 2),
            emptyCgMm: $cg,
            nonLiftingMassKg: null,
            usefulLoadKg: $this->usefulLoad($weighing, $emptyMass),
            findings: $this->findings($weighing, $emptyMass, $cg, null),
        );
    }

    /**
     * Two supports, one lever: X = (G2 · b) / G + a, with a signed.
     */
    private function centreOfGravity(Weighing $weighing): ?float
    {
        $supports = $weighing->entriesOf('support')->values();

        if ($supports->count() < 2 || $weighing->support_distance_mm === null) {
            return null;
        }

        $front = (float) $supports[0]->netto();
        $rear = (float) $supports[1]->netto();
        $total = $front + $rear;

        if ($total <= 0) {
            return null;
        }

        $a = (float) ($weighing->front_support_arm_mm ?? 0);
        $b = (float) $weighing->support_distance_mm;

        return round(($rear * $b) / $total + $a, 2);
    }

    /**
     * Moments over mass -- the powered sheet's own method.
     *
     * The deductions carry arms too: taking fuel off a wing tank moves the
     * centre of gravity as well as lightening it, and subtracting only the mass
     * would put the empty CG in the wrong place by exactly the amount that
     * matters.
     */
    private function momentCentreOfGravity(Weighing $weighing, float $emptyMass): ?float
    {
        if ($emptyMass <= 0) {
            return null;
        }

        $moment = $weighing->entriesOf('support')
            ->sum(fn (WeighingEntry $e): float => $e->netto() * (float) ($e->arm_mm ?? 0));

        $moment -= $weighing->entriesOf('deduction')
            ->sum(fn (WeighingEntry $e): float => $e->deductedMass() * (float) ($e->arm_mm ?? 0));

        return round($moment / $emptyMass, 2);
    }

    private function usefulLoad(Weighing $weighing, float $emptyMass): ?float
    {
        if ($weighing->max_mass_kg === null) {
            return null;
        }

        return round((float) $weighing->max_mass_kg - $emptyMass, 2);
    }

    /**
     * What is wrong with the result, in words somebody can act on.
     *
     * Reported rather than refused: a weighing that comes out of range is a real
     * measurement of a real aircraft, and it has to be recorded before anybody
     * can do anything about it. Refusing to save it would mean the only copy is
     * on the sheet in somebody's hand.
     *
     * @return list<string>
     */
    private function findings(Weighing $weighing, float $emptyMass, ?float $cg, ?float $nonLifting): array
    {
        $findings = [];

        if ($cg !== null && $weighing->cg_range_from_mm !== null && $weighing->cg_range_to_mm !== null) {
            if ($cg < (float) $weighing->cg_range_from_mm || $cg > (float) $weighing->cg_range_to_mm) {
                $findings[] = __('fleet.weighing.finding.cg_out_of_range', [
                    'value' => number_format($cg, 1, ',', '.'),
                    'from' => number_format((float) $weighing->cg_range_from_mm, 1, ',', '.'),
                    'to' => number_format((float) $weighing->cg_range_to_mm, 1, ',', '.'),
                ]);
            }
        }

        if ($weighing->max_mass_kg !== null && $emptyMass >= (float) $weighing->max_mass_kg) {
            $findings[] = __('fleet.weighing.finding.no_useful_load');
        }

        if ($nonLifting !== null && $weighing->max_non_lifting_kg !== null
            && $nonLifting > (float) $weighing->max_non_lifting_kg) {
            $findings[] = __('fleet.weighing.finding.non_lifting_exceeded', [
                'value' => number_format($nonLifting, 1, ',', '.'),
                'max' => number_format((float) $weighing->max_non_lifting_kg, 1, ',', '.'),
            ]);
        }

        return $findings;
    }
}
