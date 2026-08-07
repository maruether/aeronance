<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Models\User;
use App\Modules\Fleet\Actions\OnboardAircraft;
use App\Modules\Fleet\Enums\CounterKind;
use App\Modules\Fleet\Enums\InstallationOrigin;
use App\Modules\Fleet\Enums\LimitKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\Installation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Taking an aircraft into the operation.
 *
 * the correction, and it reframed the whole thing: this is not migration.
 * "Selbst wenn ich ein nagelneues flugzeug kaufe sind da schon bauteile drin ...
 * Der vogel mag seit 60 Jahren fliegen, ist aber für den Betrieb neu."
 *
 * A recurring business event, then, not a one-off import -- and what makes it
 * safe is not refusing it but marking it: every line says permanently that it
 * was transcribed from somebody else's paperwork.
 */
final class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_transcribed_component_is_marked_as_such_for_ever(): void
    {
        // Both kinds of line are legitimate. Only one of them was witnessed
        // here, and an auditor asking "how do you know" deserves a different
        // answer in each case.
        $aircraft = $this->aircraft();

        $engine = app(OnboardAircraft::class)->recordFittedComponent(
            $aircraft,
            'Rotax 912',
            'Betriebszeitenübersicht des Vorbetriebs vom 12.03.2019',
            $this->mechanic(),
            attributes: ['serial_number' => 'MOTOR-A'],
        );

        $this->assertSame(InstallationOrigin::Onboarding, $engine->origin);
        $this->assertTrue($engine->wasTranscribed());
        $this->assertStringContainsString('Vorbetriebs', $engine->transcribed_from);
        $this->assertNotNull($engine->transcribed_by_name);
    }

    #[Test]
    public function a_line_with_no_source_document_is_refused(): void
    {
        // The whole safeguard. Without it this becomes a way to type any
        // component into any aircraft with nothing to check it against -- which
        // is exactly what refusing hand entry was meant to prevent, arriving
        // through a door marked "onboarding".
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/name the document/');

        app(OnboardAircraft::class)->recordFittedComponent(
            $this->aircraft(), 'Rotax 912', '   ', $this->mechanic(),
        );
    }

    #[Test]
    public function the_operating_times_come_from_the_papers(): void
    {
        // A sixty-year-old aircraft does not arrive at zero hours, and an engine
        // that has been overhauled once has two different figures.
        //
        // On a tug, because the first version of this test hung an engine on an
        // ASK 21 and got null -- correctly, since a glider keeps no engine
        // counter and there is nothing to measure the hours against.
        $aircraft = $this->tug();

        $engine = app(OnboardAircraft::class)->recordFittedComponent(
            $aircraft,
            'Rotax 912',
            'Motorlebenslauf vom 12.03.2019',
            $this->mechanic(),
            attributes: ['serial_number' => 'MOTOR-A'],
            sinceNew: [CounterKind::EngineHours->value => 1800.0],
            sinceOverhaul: [CounterKind::EngineHours->value => 400.0],
        );

        $this->assertSame(1800.0, $engine->timeSinceNew(CounterKind::EngineHours));
        $this->assertSame(400.0, $engine->timeSinceOverhaul(CounterKind::EngineHours));
    }

    #[Test]
    public function the_fitting_date_is_the_one_from_the_papers_not_today(): void
    {
        // Writing today's date would restart every calendar limit on the day of
        // onboarding and hand a fifteen-year-old tow release a fresh two years.
        $aircraft = $this->aircraft();

        $release = app(OnboardAircraft::class)->recordFittedComponent(
            $aircraft,
            'Tost Schleppkupplung',
            'Prüfschein vom 04.05.2025',
            $this->mechanic(),
            limits: [[
                'kind' => LimitKind::CalendarMonths,
                'value' => 24,
                'source' => 'Herstellerangabe Tost',
            ]],
            installedAt: now()->subMonths(23)->toDateString(),
        );

        $this->assertSame(
            now()->subMonths(23)->toDateString(),
            $release->installed_at->toDateString(),
        );

        // And the consequence: it is nearly due, not freshly fitted.
        $this->assertLessThan(45, $release->fresh()->limits->first()->remainingDays());
    }

    #[Test]
    public function the_limits_from_the_papers_come_across(): void
    {
        $aircraft = $this->aircraft();

        $release = app(OnboardAircraft::class)->recordFittedComponent(
            $aircraft,
            'Tost Schleppkupplung',
            'Prüfschein vom 04.05.2025',
            $this->mechanic(),
            limits: [
                ['kind' => LimitKind::CalendarMonths, 'value' => 24, 'tolerance_absolute' => 1],
                ['kind' => LimitKind::Starts, 'value' => 500],
            ],
        );

        $this->assertCount(2, $release->limits);
        $this->assertSame(1.0, $release->limits->firstWhere('kind', LimitKind::CalendarMonths)->tolerance());
    }

    #[Test]
    public function the_arrival_counters_are_recorded_as_readings(): void
    {
        // Through the ordinary reading path, so the aircraft's operating history
        // starts where it actually stands rather than at zero.
        $aircraft = $this->aircraft();

        app(OnboardAircraft::class)->recordArrivalCounters(
            $aircraft,
            [
                CounterKind::FlightHours->value => 4210.5,
                CounterKind::Landings->value => 8800,
            ],
            $this->mechanic(),
        );

        $aircraft = $aircraft->fresh();

        $this->assertSame(4210.5, $aircraft->currentValue(CounterKind::FlightHours));
        $this->assertSame(8800.0, $aircraft->currentValue(CounterKind::Landings));
        $this->assertNotNull($aircraft->onboarded_at);
    }

    #[Test]
    public function a_counter_the_aircraft_does_not_keep_is_ignored(): void
    {
        // A glider has no engine hours. Recording them because a form offered
        // the field would invent a counter nobody reads.
        $aircraft = $this->aircraft();

        app(OnboardAircraft::class)->recordArrivalCounters(
            $aircraft,
            [CounterKind::EngineHours->value => 900],
            $this->mechanic(),
        );

        $this->assertSame(0, $aircraft->fresh()->readings()->count());
    }

    #[Test]
    public function onboarding_is_not_the_same_date_as_being_in_service(): void
    {
        // A glider may have been flying since 1964 and been ours since March.
        // Both are true, and conflating them loses the one that answers "since
        // when are we responsible".
        $aircraft = $this->aircraft();
        $aircraft->update(['in_service_since' => '1964-05-01']);

        app(OnboardAircraft::class)->recordArrivalCounters(
            $aircraft, [CounterKind::FlightHours->value => 4210.5], $this->mechanic(),
        );

        $aircraft = $aircraft->fresh();

        $this->assertSame('1964-05-01', $aircraft->in_service_since->toDateString());
        $this->assertSame(now()->toDateString(), $aircraft->onboarded_at->toDateString());
    }

    #[Test]
    public function a_part_issued_from_our_own_store_is_not_marked(): void
    {
        // The other side of the distinction: a line we witnessed carries no
        // transcription note, because there is nothing to disclaim.
        $ordinary = Installation::create([
            'aircraft_id' => $this->aircraft()->id,
            'part_name' => 'Ölfilter',
            'installed_at' => now()->toDateString(),
        ]);

        $this->assertSame(InstallationOrigin::Stock, $ordinary->origin);
        $this->assertFalse($ordinary->wasTranscribed());
        $this->assertTrue($ordinary->origin->isOurOwnEvidence());
    }

    #[Test]
    public function transcribed_lines_can_be_listed_on_their_own(): void
    {
        // Worth being able to ask: which of this aircraft's record is our own
        // evidence, and which did we take on trust from its previous keeper.
        $aircraft = $this->aircraft();

        app(OnboardAircraft::class)->recordFittedComponent(
            $aircraft, 'Rotax 912', 'Motorlebenslauf', $this->mechanic(),
        );

        Installation::create([
            'aircraft_id' => $aircraft->id,
            'part_name' => 'Ölfilter',
            'installed_at' => now()->toDateString(),
        ]);

        $this->assertSame(2, $aircraft->fresh()->installations()->count());
        $this->assertSame(1, $aircraft->fresh()->installations()->transcribed()->count());
    }

    private function aircraft(): Aircraft
    {
        return Aircraft::create([
            'registration' => 'D-K'.strtoupper(substr(uniqid(), -4)),
            'model' => 'ASK 21',
        ]);
    }

    #[Test]
    public function the_arrival_entry_is_offered_once_and_then_not_again(): void
    {
        // It sets where this operation's record STARTS. Offering it later would
        // let somebody quietly restate an aircraft that has been on the books
        // for years, which is a different act with a different name.
        $aircraft = $this->aircraft();

        $this->assertNull($aircraft->onboarded_at);
        $this->assertFalse($aircraft->readings()->exists());

        app(OnboardAircraft::class)->recordArrivalCounters(
            $aircraft, [CounterKind::FlightHours->value => 4210.5], $this->mechanic(),
        );

        $aircraft = $aircraft->fresh();

        $this->assertNotNull($aircraft->onboarded_at);
        $this->assertTrue($aircraft->readings()->exists(), 'Both conditions now bar it.');
    }

    #[Test]
    public function a_second_arrival_entry_does_not_move_the_onboarding_date(): void
    {
        // The date belongs to the first one. Later readings are just readings.
        $aircraft = $this->aircraft();

        app(OnboardAircraft::class)->recordArrivalCounters(
            $aircraft, [CounterKind::FlightHours->value => 4210.5], $this->mechanic(),
            on: now()->subMonths(3)->toDateString(),
        );

        $first = $aircraft->fresh()->onboarded_at->toDateString();

        app(OnboardAircraft::class)->recordArrivalCounters(
            $aircraft->fresh(), [CounterKind::FlightHours->value => 4300.0], $this->mechanic(),
        );

        $this->assertSame($first, $aircraft->fresh()->onboarded_at->toDateString());
    }

    private function tug(): Aircraft
    {
        return Aircraft::create([
            'registration' => 'D-E'.strtoupper(substr(uniqid(), -4)),
            'model' => 'Robin DR 400',
            'optional_counters' => [CounterKind::EngineHours->value],
        ]);
    }

    private function mechanic(): User
    {
        return User::factory()->create(['is_active' => true]);
    }
}
