<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Models\User;
use App\Modules\Fleet\Actions\FitComponent;
use App\Modules\Fleet\Enums\CounterKind;
use App\Modules\Fleet\Enums\LimitKind;
use App\Modules\Fleet\Enums\UsageBasis;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\ComponentLimit;
use App\Modules\Fleet\Models\CounterReading;
use App\Modules\Fleet\Models\Installation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two engines, one manufacturer, two different answers.
 *
 * the case, and the reason TSN and TSO had to become separate numbers:
 *
 *   Engine A goes to the manufacturer, who overhauls it and resets the TSO to
 *   nil. The TSN carries on.
 *   Engine B goes to the SAME manufacturer for a repair, and the TSO is not
 *   reset.
 *
 * Identical journeys. So nothing about the journey may decide -- only the paper
 * that comes back with it.
 */
final class OverhaulTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_overhaul_zeroes_the_tso_and_leaves_the_tsn_alone(): void
    {
        // Engine A. 1800 hours since new when it went away, overhauled, back on
        // the aircraft: TSN still 1800, TSO nil.
        $aircraft = $this->tug();
        $this->read($aircraft, CounterKind::EngineHours, 1800);

        $first = $this->fitEngine($aircraft, sinceNew: 0.0);
        $this->read($aircraft, CounterKind::EngineHours, 3600);

        app(FitComponent::class)->remove($first, $this->mechanic(), 'Zur Grundüberholung');

        $this->assertSame(1800.0, $first->fresh()->timeSinceNew(CounterKind::EngineHours));

        $refitted = app(FitComponent::class)->handle(
            $aircraft->fresh(),
            'Rotax 912',
            $this->mechanic(),
            attributes: ['serial_number' => 'MOTOR-A'],
            overhauled: true,
            overhaulReference: 'GÜ-Bericht 2026-114',
        );

        $this->assertSame(1800.0, $refitted->timeSinceNew(CounterKind::EngineHours), 'TSN carries on.');
        $this->assertSame(0.0, $refitted->timeSinceOverhaul(CounterKind::EngineHours), 'TSO starts again.');
        $this->assertTrue($refitted->wasOverhauled());
    }

    #[Test]
    public function a_repair_without_an_overhaul_changes_neither(): void
    {
        // Engine B. Same shop, same journey, no overhaul -- so the TSO must
        // survive the trip. Zeroing it here would hand the engine a second full
        // run between overhauls it never earned.
        $aircraft = $this->tug();
        $this->read($aircraft, CounterKind::EngineHours, 1800);

        $first = $this->fitEngine($aircraft, sinceNew: 0.0, serial: 'MOTOR-B');
        $this->read($aircraft, CounterKind::EngineHours, 3600);

        app(FitComponent::class)->remove($first, $this->mechanic(), 'Zylinderkopf undicht');

        $refitted = app(FitComponent::class)->handle(
            $aircraft->fresh(),
            'Rotax 912',
            $this->mechanic(),
            attributes: ['serial_number' => 'MOTOR-B'],
            // No overhaul claimed, so nothing is reset.
        );

        $this->assertSame(1800.0, $refitted->timeSinceNew(CounterKind::EngineHours));
        $this->assertSame(1800.0, $refitted->timeSinceOverhaul(CounterKind::EngineHours), 'The TSO survived the trip.');
        $this->assertFalse($refitted->wasOverhauled());
    }

    #[Test]
    public function the_two_engines_side_by_side(): void
    {
        // The case stated as one test, because the whole point is that these two
        // must not come out the same. A 2000-hour TBO: engine A has its full run
        // ahead of it, engine B has 200 hours left. Same shop, same week.
        $aircraft = $this->tug();
        $this->read($aircraft, CounterKind::EngineHours, 5000);

        $a = $this->refitWithTbo($aircraft, 'MOTOR-A', overhauled: true);
        $b = $this->refitWithTbo($aircraft, 'MOTOR-B', overhauled: false);

        $this->assertSame(2000.0, $a->limits->first()->remaining());
        $this->assertSame(200.0, $b->limits->first()->remaining());

        $this->assertFalse($a->isOverdue());
        $this->assertFalse($b->isOverdue());
    }

    #[Test]
    public function a_tbo_measures_since_overhaul_and_a_life_limit_since_new(): void
    {
        // Reading a TBO against TSN would condemn a freshly overhauled engine;
        // reading a life limit against TSO would fly one for ever.
        $aircraft = $this->tug();
        $this->read($aircraft, CounterKind::EngineHours, 5000);

        $engine = app(FitComponent::class)->handle(
            $aircraft->fresh(),
            'Rotax 912',
            $this->mechanic(),
            attributes: ['serial_number' => 'MOTOR-C'],
            carriedSinceNew: [CounterKind::EngineHours->value => 4800.0],
            carriedSinceOverhaul: [CounterKind::EngineHours->value => 0.0],
        );

        $tbo = ComponentLimit::create([
            'installation_id' => $engine->id,
            'kind' => LimitKind::EngineHours,
            'basis' => UsageBasis::SinceOverhaul,
            'value' => 2000,
            'source' => 'TBO Herstellerangabe',
        ]);

        $life = ComponentLimit::create([
            'installation_id' => $engine->id,
            'kind' => LimitKind::EngineHours,
            'basis' => UsageBasis::SinceNew,
            'value' => 5000,
            'source' => 'Lebensdauergrenze',
        ]);

        $this->assertSame(2000.0, $tbo->fresh()->remaining(), 'Freshly overhauled.');
        $this->assertSame(200.0, $life->fresh()->remaining(), 'But nearly at the end of its life.');

        // And the one that matters is the one that arrives first.
        $due = $engine->fresh()->nextDue();
        $this->assertSame(UsageBasis::SinceNew, $due['limit']->basis);
    }

    #[Test]
    public function claiming_an_overhaul_needs_the_document_that_says_so(): void
    {
        // Zeroing a component's life is an assertion. An assertion whose only
        // backing is a ticked box is the kind an audit asks about.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/requires the document/');

        app(FitComponent::class)->handle(
            $this->tug(),
            'Rotax 912',
            $this->mechanic(),
            attributes: ['serial_number' => 'MOTOR-D'],
            overhauled: true,
        );
    }

    #[Test]
    public function a_part_that_never_had_an_overhaul_reads_the_same_both_ways(): void
    {
        // the second remark: most components have no overhaul concept at
        // all, and for those TSO simply IS TSN. An absent figure must fall back
        // rather than count as nil -- nil would declare every part
        // factory-fresh at every refit.
        $aircraft = $this->tug();
        $this->read($aircraft, CounterKind::EngineHours, 1000);

        $part = app(FitComponent::class)->handle(
            $aircraft->fresh(),
            'Schleppkupplung',
            $this->mechanic(),
            attributes: ['serial_number' => 'KUPPLUNG-1'],
            carriedSinceNew: [CounterKind::EngineHours->value => 400.0],
        );

        $this->assertSame(400.0, $part->timeSinceNew(CounterKind::EngineHours));
        $this->assertSame(400.0, $part->timeSinceOverhaul(CounterKind::EngineHours));
    }

    #[Test]
    public function history_follows_the_part_to_another_aircraft(): void
    {
        // A component is the same component wherever it has been. Scoping the
        // carry-forward to one aircraft would restart the life of every part
        // that moved -- quietly, and in the direction that flatters the part.
        $first = $this->tug('D-EFGH');
        $this->read($first, CounterKind::EngineHours, 1000);

        $fitted = $this->fitEngine($first, sinceNew: 500.0, serial: 'MOTOR-E');
        $this->read($first, CounterKind::EngineHours, 1300);
        app(FitComponent::class)->remove($fitted, $this->mechanic(), 'Umbau');

        $second = $this->tug('D-IJKL');
        $this->read($second, CounterKind::EngineHours, 9000);

        $refitted = app(FitComponent::class)->handle(
            $second->fresh(),
            'Rotax 912',
            $this->mechanic(),
            attributes: ['serial_number' => 'MOTOR-E'],
        );

        $this->assertSame(800.0, $refitted->timeSinceNew(CounterKind::EngineHours));
        $this->assertSame(0.0, $refitted->usage(CounterKind::EngineHours, UsageBasis::SinceNew) - 800.0);
    }

    private function tug(string $registration = 'D-EFGH'): Aircraft
    {
        return Aircraft::create([
            'registration' => $registration,
            'model' => 'Robin DR 400',
            'optional_counters' => [CounterKind::EngineHours->value],
        ]);
    }

    private function mechanic(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    private function fitEngine(Aircraft $aircraft, float $sinceNew, string $serial = 'MOTOR-A'): Installation
    {
        return app(FitComponent::class)->handle(
            $aircraft->fresh(),
            'Rotax 912',
            $this->mechanic(),
            attributes: ['serial_number' => $serial],
            carriedSinceNew: [CounterKind::EngineHours->value => $sinceNew],
            carriedSinceOverhaul: [CounterKind::EngineHours->value => $sinceNew],
        );
    }

    private function refitWithTbo(Aircraft $aircraft, string $serial, bool $overhauled): Installation
    {
        $engine = app(FitComponent::class)->handle(
            $aircraft->fresh(),
            'Rotax 912',
            $this->mechanic(),
            attributes: ['serial_number' => $serial],
            carriedSinceNew: [CounterKind::EngineHours->value => 3600.0],
            carriedSinceOverhaul: $overhauled ? null : [CounterKind::EngineHours->value => 1800.0],
            overhauled: $overhauled,
            overhaulReference: $overhauled ? 'GÜ-Bericht 2026-114' : null,
        );

        ComponentLimit::create([
            'installation_id' => $engine->id,
            'kind' => LimitKind::EngineHours,
            'basis' => UsageBasis::SinceOverhaul,
            'value' => 2000,
            'source' => 'TBO',
        ]);

        return $engine->fresh();
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
