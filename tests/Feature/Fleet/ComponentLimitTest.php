<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Modules\Fleet\Enums\CounterKind;
use App\Modules\Fleet\Enums\LimitKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\ComponentLimit;
use App\Modules\Fleet\Models\CounterReading;
use App\Modules\Fleet\Models\Installation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Zwei Jahre oder 500 Starts, was zuerst eintritt."
 *
 * the Tost tow release, which is why limits are rows and not columns. A
 * component carries several limits of different kinds, and what falls due is
 * whichever arrives first -- a comparison that a column per kind makes
 * impossible to write.
 */
final class ComponentLimitTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_component_carries_several_limits_of_different_kinds(): void
    {
        $release = $this->towRelease();

        $this->assertCount(2, $release->limits);
        $this->assertEqualsCanonicalizing(
            [LimitKind::CalendarMonths, LimitKind::Starts],
            $release->limits->pluck('kind')->all(),
        );
    }

    #[Test]
    public function the_launches_fall_due_first_when_it_flies_a_lot(): void
    {
        // Fitted today, 480 launches flown, eleven months still to run on the
        // calendar. The launches are what stops it.
        $release = $this->towRelease();
        $this->read($release->aircraft, CounterKind::Starts, 480);

        $due = $release->fresh()->nextDue();

        $this->assertNotNull($due);
        $this->assertSame(LimitKind::Starts, $due['limit']->kind);
        $this->assertFalse($due['overdue']);
        $this->assertSame(20.0, $due['limit']->remaining());
    }

    #[Test]
    public function the_calendar_falls_due_first_when_it_barely_flies(): void
    {
        // The same component in a club that flies little: 40 launches in two
        // years. The calendar is what stops it, and a system that only counted
        // launches would call it good.
        $release = $this->towRelease(installedAt: now()->subMonths(23)->toDateString());
        $this->read($release->aircraft, CounterKind::Starts, 40);

        $due = $release->fresh()->nextDue();

        $this->assertNotNull($due);
        $this->assertSame(LimitKind::CalendarMonths, $due['limit']->kind);
    }

    #[Test]
    public function it_is_overdue_as_soon_as_any_one_limit_has_run_out(): void
    {
        // Not when all of them have. That is the whole meaning of "whatever
        // comes first", and getting it the other way round would let a part fly
        // two years past its calendar limit because it had launches left.
        $release = $this->towRelease(installedAt: now()->subMonths(25)->toDateString());
        $this->read($release->aircraft, CounterKind::Starts, 10);

        $release = $release->fresh();

        $this->assertTrue($release->isOverdue());
        $this->assertTrue($release->nextDue()['overdue']);
    }

    #[Test]
    public function usage_is_what_the_aircraft_did_while_the_part_was_on_it(): void
    {
        // Not the aircraft's total. Fitting a part to an aircraft with 3000
        // launches on the clock must not consume its entire life on day one.
        $aircraft = $this->aircraft();
        $this->read($aircraft, CounterKind::Starts, 3000);

        $release = $this->fit($aircraft, starts: 500);

        $this->assertSame(0.0, $release->usage(CounterKind::Starts), 'Fresh part, nothing used.');

        $this->read($aircraft, CounterKind::Starts, 3120);

        $this->assertSame(120.0, $release->fresh()->usage(CounterKind::Starts));
    }

    #[Test]
    public function a_part_that_arrives_with_history_does_not_start_at_zero(): void
    {
        // An overhauled tow release comes back with 300 launches on it. Letting
        // it start again would hand it a second full life.
        $aircraft = $this->aircraft();
        $this->read($aircraft, CounterKind::Starts, 1000);

        $release = $this->fit($aircraft, starts: 500, carried: [CounterKind::Starts->value => 300]);

        $this->read($aircraft, CounterKind::Starts, 1050);

        $release = $release->fresh();

        $this->assertSame(350.0, $release->usage(CounterKind::Starts));
        $this->assertSame(150.0, $release->limits->firstWhere('kind', LimitKind::Starts)->remaining());
    }

    #[Test]
    public function a_removed_part_stops_accruing(): void
    {
        // A part in a box does not gain launches because the aircraft kept
        // flying without it.
        $aircraft = $this->aircraft();
        $this->read($aircraft, CounterKind::Starts, 1000);
        $release = $this->fit($aircraft, starts: 500);

        $this->read($aircraft, CounterKind::Starts, 1080);

        $release->update([
            'removed_at' => now()->toDateString(),
            'counters_at_removal' => [CounterKind::Starts->value => 1080],
        ]);

        $this->read($aircraft, CounterKind::Starts, 1500);

        $this->assertSame(80.0, $release->fresh()->usage(CounterKind::Starts));
    }

    #[Test]
    public function a_counter_the_aircraft_does_not_keep_gives_no_answer(): void
    {
        // Rather than a comforting zero. A launch limit on an aircraft that
        // counts no launches is a limit nobody can answer, and saying "0 used"
        // would present that as "plenty left".
        $aircraft = Aircraft::create([
            'registration' => 'D-KNIL',
            'model' => 'ASK 21',
            // No Starts counter configured.
        ]);

        $release = Installation::create([
            'aircraft_id' => $aircraft->id,
            'part_name' => 'Schleppkupplung',
            'installed_at' => now()->toDateString(),
            'counters_at_installation' => $aircraft->currentValues(),
        ]);

        ComponentLimit::create([
            'installation_id' => $release->id,
            'kind' => LimitKind::Starts,
            'value' => 500,
        ]);

        $this->assertNull($release->fresh()->usage(CounterKind::Starts));
        $this->assertNull($release->fresh()->limits->first()->remaining());
    }

    #[Test]
    public function a_component_without_limits_is_perfectly_normal(): void
    {
        // Vorgabe: "Ein Ölfilter geht z. B. automatisch mit der Motorwartung und
        // ein neuer kommt." An empty limit list is the ordinary case.
        $filter = Installation::create([
            'aircraft_id' => $this->aircraft()->id,
            'part_name' => 'Ölfilter Rotax 912',
            'installed_at' => now()->toDateString(),
        ]);

        $this->assertNull($filter->nextDue());
        $this->assertFalse($filter->isOverdue());
    }

    #[Test]
    public function a_fixed_date_is_taken_as_given(): void
    {
        // Some certificates name a day rather than an interval, and deriving an
        // interval from it would be arithmetic on somebody else's document.
        $release = $this->towRelease();

        $limit = ComponentLimit::create([
            'installation_id' => $release->id,
            'kind' => LimitKind::CalendarDate,
            'due_on' => now()->addDays(10)->toDateString(),
        ]);

        $this->assertSame(
            now()->addDays(10)->toDateString(),
            $limit->dueDate()->toDateString(),
        );
        $this->assertSame(10, $limit->remainingDays());
        $this->assertSame(LimitKind::CalendarDate, $release->fresh()->nextDue()['limit']->kind);
    }

    private function aircraft(): Aircraft
    {
        return Aircraft::create([
            'registration' => 'D-KABC',
            'model' => 'ASK 21',
            'optional_counters' => [CounterKind::Starts->value],
        ]);
    }

    /** @param  array<string, float>  $carried */
    private function fit(Aircraft $aircraft, float $starts, array $carried = []): Installation
    {
        $installation = Installation::create([
            'aircraft_id' => $aircraft->id,
            'part_name' => 'Tost Schleppkupplung',
            'serial_number' => '1378X5V',
            'installed_at' => now()->toDateString(),
            'counters_at_installation' => $aircraft->fresh()->currentValues(),

            // Since the TSN/TSO split these are two figures. A used part with
            // 300 launches that has never been overhauled reads the same on
            // both, which is what the fallback in Installation::carried() means.
            'carried_since_new' => $carried,
            'carried_since_overhaul' => $carried,
        ]);

        ComponentLimit::create([
            'installation_id' => $installation->id,
            'kind' => LimitKind::Starts,
            'value' => $starts,
            'source' => 'Herstellerangabe Tost',
        ]);

        return $installation->fresh();
    }

    private function towRelease(?string $installedAt = null): Installation
    {
        $aircraft = $this->aircraft();

        $installation = Installation::create([
            'aircraft_id' => $aircraft->id,
            'part_name' => 'Tost Schleppkupplung',
            'serial_number' => '1378X5V',
            'installed_at' => $installedAt ?? now()->toDateString(),
            'counters_at_installation' => [CounterKind::Starts->value => 0.0],
        ]);

        ComponentLimit::create([
            'installation_id' => $installation->id,
            'kind' => LimitKind::CalendarMonths,
            'value' => 24,
            'source' => 'Herstellerangabe Tost',
        ]);

        ComponentLimit::create([
            'installation_id' => $installation->id,
            'kind' => LimitKind::Starts,
            'value' => 500,
            'source' => 'Herstellerangabe Tost',
        ]);

        return $installation->fresh();
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
