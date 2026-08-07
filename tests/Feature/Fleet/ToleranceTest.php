<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Modules\Fleet\Actions\RecordMaintenance;
use App\Modules\Fleet\Enums\CounterKind;
use App\Modules\Fleet\Enums\LimitKind;
use App\Modules\Fleet\Enums\LimitStatus;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\ComponentLimit;
use App\Modules\Fleet\Models\CounterReading;
use App\Modules\Fleet\Models\Installation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Permitted overrun, and where the next interval starts from.
 *
 * the rule, and it is asymmetric on purpose:
 *
 *   done LATE within tolerance -> the OLD due date anchors the next one
 *   done EARLY                 -> the ACTUAL date anchors it
 *
 * Both refuse to hand back time, which is the tell that they are one rule. The
 * failure mode of getting either wrong is that nobody notices: each single step
 * looks reasonable and the schedule drifts a decade later.
 */
final class ToleranceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function four_states_and_not_two(): void
    {
        // An inspection four days past a twelve-month interval is not in the
        // same condition as one forgotten for a year.
        $limit = $this->calendarLimit(months: 12, installedMonthsAgo: 13, toleranceMonths: 2);

        $this->assertSame(LimitStatus::InTolerance, $limit->status());
        $this->assertTrue($limit->isOverdue(), 'Past its date...');
        $this->assertFalse($limit->isBeyondTolerance(), '...but not past everything.');
    }

    #[Test]
    public function past_the_tolerance_as_well_is_a_different_colour(): void
    {
        $limit = $this->calendarLimit(months: 12, installedMonthsAgo: 14, toleranceMonths: 1);

        $this->assertSame(LimitStatus::Overdue, $limit->status());
        $this->assertTrue($limit->isBeyondTolerance());
    }

    #[Test]
    public function no_tolerance_entered_means_none(): void
    {
        // An ARC never has one, and an airworthiness directive generally does
        // not. Leaving the field empty must not quietly grant ten per cent.
        $limit = $this->calendarLimit(months: 12, installedMonthsAgo: 13, toleranceMonths: null);

        $this->assertSame(0.0, $limit->tolerance());
        $this->assertSame(LimitStatus::Overdue, $limit->status());
    }

    #[Test]
    public function where_both_forms_are_given_the_smaller_wins(): void
    {
        // "10 % oder 1 Monat": ten per cent of twelve months is more than a
        // month, so the month is the answer.
        $calendar = $this->calendarLimit(months: 12, installedMonthsAgo: 1, toleranceMonths: 1, tolerancePercent: 10);
        $this->assertSame(1.0, $calendar->tolerance());

        // And the other way round: ten per cent of a hundred hours is ten
        // hours, which is less than the twenty entered.
        $counted = $this->countedLimit(value: 100, tolerancePercent: 10, toleranceAbsolute: 20);
        $this->assertSame(10.0, $counted->tolerance());
    }

    #[Test]
    public function done_late_the_next_interval_runs_from_the_old_due_date(): void
    {
        // THE rule. Anchoring to the day it happened would push every future
        // interval out by the overrun -- ten per cent a year, and after a decade
        // a whole interval has gone missing without anybody noticing.
        $limit = $this->calendarLimit(months: 12, installedMonthsAgo: 13, toleranceMonths: 2);
        $installed = $limit->installation->installed_at->copy();

        $originalDue = $limit->dueDate()->toDateString();
        $this->assertSame($installed->copy()->addMonths(12)->toDateString(), $originalDue);

        // Done today -- a month past due, inside the tolerance.
        app(RecordMaintenance::class)->handle($limit, now()->toDateString());

        $limit = $limit->fresh();

        $this->assertSame($originalDue, $limit->anchorDate()->toDateString(), 'Anchored to the old due date.');
        $this->assertSame(
            $installed->copy()->addMonths(24)->toDateString(),
            $limit->dueDate()->toDateString(),
            'So the next one lands 24 months after installation, not 24 months after today.',
        );
    }

    #[Test]
    public function done_early_the_next_interval_runs_from_the_day_it_was_done(): void
    {
        // The other half. Anchoring an early job to the old due date would fly
        // the component on time it never earned.
        $limit = $this->calendarLimit(months: 12, installedMonthsAgo: 10, toleranceMonths: 1);

        // Two months early.
        app(RecordMaintenance::class)->handle($limit, now()->toDateString());

        $limit = $limit->fresh();

        $this->assertSame(now()->toDateString(), $limit->anchorDate()->toDateString());
        $this->assertSame(
            now()->addMonths(12)->toDateString(),
            $limit->dueDate()->toDateString(),
            'The two months given up by doing it early are gone, which is the right way to lose them.',
        );
    }

    #[Test]
    public function the_drift_does_not_accumulate_over_several_cycles(): void
    {
        // The whole point stated as arithmetic: use the full tolerance three
        // times running and the schedule must still sit on the original grid.
        $limit = $this->calendarLimit(months: 12, installedMonthsAgo: 40, toleranceMonths: 1);
        $installed = $limit->installation->installed_at->copy();

        foreach ([13, 25, 37] as $monthsAfterInstall) {
            app(RecordMaintenance::class)->handle(
                $limit->fresh(),
                $installed->copy()->addMonths($monthsAfterInstall)->toDateString(),
            );
        }

        $this->assertSame(
            $installed->copy()->addMonths(48)->toDateString(),
            $limit->fresh()->dueDate()->toDateString(),
            'Still on the original twelve-month grid after three tolerated overruns.',
        );
    }

    #[Test]
    public function a_counted_limit_moves_on_the_same_way(): void
    {
        $aircraft = $this->aircraft();
        $release = $this->installation($aircraft);

        $limit = ComponentLimit::create([
            'installation_id' => $release->id,
            'kind' => LimitKind::Starts,
            'value' => 500,
            'tolerance_absolute' => 25,
        ]);

        // 510 launches: over the 500, inside the 25 allowed.
        $this->read($aircraft, CounterKind::Starts, 510);

        $this->assertSame(LimitStatus::InTolerance, $limit->fresh()->status());

        app(RecordMaintenance::class)->handle($limit->fresh(), atValue: 510.0);

        // Anchored at 500, not 510 -- so the next one falls at 1000.
        $this->assertSame(500.0, (float) $limit->fresh()->last_done_value);
        $this->assertSame(490.0, $limit->fresh()->remaining());
    }

    #[Test]
    public function a_counted_limit_done_early_anchors_where_it_was_done(): void
    {
        $aircraft = $this->aircraft();
        $release = $this->installation($aircraft);

        $limit = ComponentLimit::create([
            'installation_id' => $release->id,
            'kind' => LimitKind::Starts,
            'value' => 500,
            'tolerance_absolute' => 25,
        ]);

        $this->read($aircraft, CounterKind::Starts, 460);

        app(RecordMaintenance::class)->handle($limit->fresh(), atValue: 460.0);

        $this->assertSame(460.0, (float) $limit->fresh()->last_done_value);
        $this->assertSame(500.0, $limit->fresh()->remaining(), 'A full interval from where it was done.');
    }

    #[Test]
    public function a_fixed_date_does_not_recur(): void
    {
        $release = $this->installation($this->aircraft());

        $limit = ComponentLimit::create([
            'installation_id' => $release->id,
            'kind' => LimitKind::CalendarDate,
            'due_on' => now()->addDays(10)->toDateString(),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not recur/');

        app(RecordMaintenance::class)->handle($limit);
    }

    #[Test]
    public function work_cannot_be_recorded_before_it_happens(): void
    {
        $limit = $this->calendarLimit(months: 12, installedMonthsAgo: 6, toleranceMonths: 1);

        $this->expectException(InvalidArgumentException::class);

        app(RecordMaintenance::class)->handle($limit, now()->addWeek()->toDateString());
    }

    private function aircraft(): Aircraft
    {
        return Aircraft::create([
            // Unique per call: several helpers build their own aircraft, and a
            // registration is unique by design.
            'registration' => 'D-K'.strtoupper(substr(uniqid(), -4)),
            'model' => 'ASK 21',
            'optional_counters' => [CounterKind::Starts->value],
        ]);
    }

    private function installation(Aircraft $aircraft, int $monthsAgo = 0): Installation
    {
        return Installation::create([
            'aircraft_id' => $aircraft->id,
            'part_name' => 'Tost Schleppkupplung',
            'installed_at' => now()->subMonths($monthsAgo)->toDateString(),
            'counters_at_installation' => [CounterKind::Starts->value => 0.0],
        ]);
    }

    private function calendarLimit(
        int $months,
        int $installedMonthsAgo,
        ?float $toleranceMonths,
        ?float $tolerancePercent = null,
    ): ComponentLimit {
        $installation = $this->installation($this->aircraft(), $installedMonthsAgo);

        return ComponentLimit::create([
            'installation_id' => $installation->id,
            'kind' => LimitKind::CalendarMonths,
            'value' => $months,
            'tolerance_absolute' => $toleranceMonths,
            'tolerance_percent' => $tolerancePercent,
        ]);
    }

    private function countedLimit(float $value, ?float $tolerancePercent, ?float $toleranceAbsolute): ComponentLimit
    {
        $installation = $this->installation($this->aircraft());

        return ComponentLimit::create([
            'installation_id' => $installation->id,
            'kind' => LimitKind::Starts,
            'value' => $value,
            'tolerance_percent' => $tolerancePercent,
            'tolerance_absolute' => $toleranceAbsolute,
        ]);
    }

    private function read(Aircraft $aircraft, CounterKind $kind, float $value): void
    {
        CounterReading::create([
            'aircraft_id' => $aircraft->id,
            'kind' => $kind,
            'value' => $value,
            'read_at' => now()->toDateString(),
        ]);
    }
}
