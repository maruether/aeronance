<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Actions;

use App\Models\User;
use App\Modules\Fleet\Enums\WeighingKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\Weighing;
use App\Modules\Fleet\Models\WeighingEntry;
use Illuminate\Support\Facades\DB;

/**
 * Starting a new weighing from the last one.
 *
 * Vorgabe: "wenn ich zum gleichen lfz eine neue wägung erstelle, sollten die
 * Handbuchwerte von der letzten schon drinstehen. die ändern sich in der regel
 * nicht. Ausnahme ist da der Bezugspunkt, der sollte immer gemessen werden."
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT IS CARRIED AND WHAT IS NOT, and the line between them is the whole idea.
 *
 * Carried: everything that comes out of the flight manual or the type
 * certificate. Mass limits, centre of gravity ranges, cockpit load, seat arms --
 * and the DEFINITION of the datum, "Flügelvorderkante Wurzelrippe" or whatever
 * the sheet says, because that is a property of the type and does not move.
 * These are copied from a document, and re-typing them every four years is four
 * opportunities to transpose a digit.
 *
 * Not carried: everything that was MEASURED. Above all the DISTANCES FROM THE
 * SCALES TO THE DATUM -- the point, and worth being exact about, because I
 * first read it as the datum itself: the datum is defined once and stays where
 * it is, while where the scales stood in relation to it is established afresh
 * every time and genuinely does change.
 *
 * Carrying a measurement forward would let a mistake from 2022 quietly become
 * the 2026 result, and nothing in the sheet would ever show it.
 *
 * So: paper values are copied, measurements start empty. A prefilled field one
 * is supposed to check is a field nobody checks.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class PrepareWeighing
{
    /**
     * The fields taken from the flight manual, and therefore worth carrying.
     *
     * @var list<string>
     */
    private const FROM_THE_MANUAL = [
        // Where the datum IS -- a property of the type, not a measurement.
        'datum_reference',
        'reference_line',

        'cg_range_from_mm',
        'cg_range_to_mm',
        'flight_cg_from_mm',
        'flight_cg_to_mm',
        'max_mass_kg',
        'max_mass_water_kg',
        'max_non_lifting_kg',
        'cockpit_load_min_kg',
        'cockpit_load_max_kg',
    ];

    /**
     * The starting values for a new sheet, ready to hand to a form.
     *
     * @return array<string, mixed>
     */
    public function defaultsFor(Aircraft $aircraft): array
    {
        $previous = $this->lastSignedOff($aircraft);

        if ($previous === null) {
            return [];
        }

        $defaults = ['kind' => $previous->kind->value];

        foreach (self::FROM_THE_MANUAL as $field) {
            $defaults[$field] = $previous->{$field};
        }

        /*
         * Deliberately absent: front_support_arm_mm and support_distance_mm --
         * where the scales stood relative to the datum. Measured every time, and
         * they do change. A prefilled field one is supposed to check is a field
         * nobody checks.
         */

        return $defaults;
    }

    /**
     * Creates the new sheet, with the manual values and the seats carried over.
     */
    public function from(Aircraft $aircraft, ?User $user = null, ?WeighingKind $kind = null): Weighing
    {
        $previous = $this->lastSignedOff($aircraft);

        return DB::transaction(function () use ($aircraft, $previous, $user, $kind): Weighing {
            $weighing = Weighing::create(array_merge($this->defaultsFor($aircraft), [
                'aircraft_id' => $aircraft->id,
                'weighed_at' => now()->toDateString(),
                'user_id' => $user?->id,

                // Explicit wins, then whatever the last sheet was, then the
                // glider form -- which is what a club fleet mostly is, and the
                // form asks anyway before anybody fills anything in.
                'kind' => $kind ?? $previous?->kind ?? WeighingKind::Glider,
            ]));

            /*
             * ─────────────────────────────────────────────────────────────────
             * DIE AUFLAGEN ENTSTEHEN MIT DEM BLATT -- vorher tat es das nicht,
             * und das war der Grund, warum niemand je eine Wägeskizze sah.
             *
             * WeighingKind::defaultSupports() gab es seit jeher (zwei beim
             * Segelflugzeug, drei beim Motorflugzeug), aufgerufen hat es
             * niemand. Wer ein Blatt anlegte, bekam einen leeren Auflagen-Teil
             * und musste die Zeilen von Hand hinzufügen -- über einen Knopf,
             * den kaum jemand fand. Und ohne Auflagen zeichnet keine der
             * beiden Skizzen, weder in der Maske noch im Druck.
             *
             * Feldtest, dreimal gemeldet: "Die Wägebricht grafiken laut
             * formular vom bwlv fehlen immer noch in eingabe und druck."
             *
             * Die Beschriftungen kommen aus der Wägungsart, weil das Formular
             * sie so vorgibt; die Zahlen bleiben leer -- die trägt ein, wer
             * wiegt.
             * ─────────────────────────────────────────────────────────────────
             */
            foreach ($weighing->kind->defaultSupports() as $position => $label) {
                WeighingEntry::create([
                    'weighing_id' => $weighing->id,
                    'section' => WeighingEntry::SECTION_SUPPORT,
                    'label' => $label,
                    'position' => $position,
                ]);
            }

            if ($previous === null) {
                return $weighing;
            }

            // Seat arms come from the manual too, so they travel with the rest.
            foreach ($previous->entriesOf(WeighingEntry::SECTION_SEAT) as $seat) {
                WeighingEntry::create([
                    'weighing_id' => $weighing->id,
                    'section' => WeighingEntry::SECTION_SEAT,
                    'label' => $seat->label,
                    'arm_mm' => $seat->arm_mm,
                    'position' => $seat->position,
                ]);
            }

            // The row LABELS of the previous sheet, without their figures: the
            // aircraft has the same components as last time, and typing out
            // "Tragwerk rechts innen" again is not the part worth repeating.
            foreach ($previous->entriesOf(WeighingEntry::SECTION_COMPONENT) as $component) {
                WeighingEntry::create([
                    'weighing_id' => $weighing->id,
                    'section' => WeighingEntry::SECTION_COMPONENT,
                    'label' => $component->label,
                    'position' => $component->position,
                ]);
            }

            return $weighing->fresh();
        });
    }

    /**
     * The last sheet worth copying from.
     *
     * Signed-off ones only. A draft somebody abandoned halfway may hold figures
     * that were never checked, and carrying those forward would launder them
     * into the next report.
     */
    public function lastSignedOff(Aircraft $aircraft): ?Weighing
    {
        return Weighing::query()
            ->where('aircraft_id', $aircraft->id)
            ->whereNotNull('signed_off_at')
            ->with('entries')
            ->orderByDesc('weighed_at')
            ->orderByDesc('id')
            ->first();
    }
}
