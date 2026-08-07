<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Actions;

use App\Modules\Fleet\Enums\LimitKind;
use App\Modules\Fleet\Enums\UsageBasis;
use App\Modules\Fleet\Models\ComponentLimit;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Recording that a limit's work has been done, and moving it on.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The whole of this class is one asymmetric rule, and it is the:
 *
 *   DONE LATE, within tolerance  ->  the OLD due date is the anchor.
 *   DONE EARLY                   ->  the ACTUAL date is the anchor.
 *
 * They lean the same way, which is the tell that they are one rule rather than
 * two special cases. Both refuse to hand back time.
 *
 * Anchoring a late job to the day it happened would push every future interval
 * out by the overrun -- ten per cent a year, and after a decade a whole interval
 * has quietly gone missing. Nobody would ever notice, because each single step
 * looks reasonable. That is exactly what makes it worth writing down.
 *
 * Anchoring an early job to the old due date would do the reverse: fly the
 * component on time it never earned. Doing the work early costs the unused
 * remainder, and that is the correct direction to lose it in.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class RecordMaintenance
{
    /**
     * @param  float|null  $atValue  the counter reading at completion, for counted limits
     */
    public function handle(
        ComponentLimit $limit,
        ?string $doneAt = null,
        ?float $atValue = null,
    ): ComponentLimit {
        $when = $doneAt !== null ? Carbon::parse($doneAt) : now();

        if ($when->isFuture()) {
            throw new InvalidArgumentException(
                'Work cannot be recorded as done on a date that has not arrived.'
            );
        }

        if ($limit->kind === LimitKind::CalendarDate) {
            throw new InvalidArgumentException(
                'A limit that names a fixed date does not recur -- there is nothing to '
                .'move it on to. Enter the next one.'
            );
        }

        return $limit->kind->isCalendar()
            ? $this->moveCalendarOn($limit, $when)
            : $this->moveCountedOn($limit, $when, $atValue);
    }

    private function moveCalendarOn(ComponentLimit $limit, Carbon $when): ComponentLimit
    {
        $due = $limit->dueDate();

        // Late but tolerated: the old due date carries the next interval, so the
        // overrun is spent rather than banked.
        $late = $due !== null && $when->startOfDay()->gt($due->startOfDay());

        $limit->update([
            'last_done_at' => $when->toDateString(),
            'last_due_at' => $due?->toDateString(),
        ]);

        if ($late && $due !== null) {
            $limit->update(['last_done_at' => $due->toDateString()]);
        }

        return $limit->fresh();
    }

    private function moveCountedOn(ComponentLimit $limit, Carbon $when, ?float $atValue): ComponentLimit
    {
        $used = $limit->installation?->usage(
            $limit->kind->counter(),
            $limit->basis ?? UsageBasis::SinceOverhaul,
        );

        if ($used === null) {
            throw new InvalidArgumentException(
                'This aircraft does not keep the counter the limit is measured in, so '
                .'there is nothing to move it on from.'
            );
        }

        $actual = $atValue ?? $used;

        // Where the interval WAS due, on the component's own running total.
        $anchor = $limit->last_done_value !== null ? (float) $limit->last_done_value : 0.0;
        $due = $anchor + (float) $limit->value;

        $late = $actual > $due;

        $limit->update([
            'last_done_at' => $when->toDateString(),
            'last_done_value' => $late ? $due : $actual,
            'last_due_value' => $due,
        ]);

        return $limit->fresh();
    }
}
