<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Modules\Fleet\Actions\CollectDueItems;
use App\Modules\Fleet\Enums\CounterKind;
use App\Modules\Fleet\Enums\LimitKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AirworthinessReview;
use App\Modules\Fleet\Models\ComponentLimit;
use App\Modules\Fleet\Models\CounterReading;
use App\Modules\Fleet\Models\Installation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Wann ist die Nachprüfung fällig?"
 *
 * The question the module exists to answer, and the reason the requirement was
 * deadlines in this slice rather than the next.
 */
final class DueItemsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_expiring_review_shows_up(): void
    {
        $aircraft = $this->aircraft();
        $this->review($aircraft, validUntil: now()->addDays(20));

        $due = app(CollectDueItems::class)->within(60);

        $this->assertCount(1, $due);
        $this->assertSame('review', $due->first()['kind']);
        $this->assertFalse($due->first()['overdue']);
    }

    #[Test]
    public function a_review_far_off_does_not(): void
    {
        $this->review($this->aircraft(), validUntil: now()->addMonths(10));

        $this->assertCount(0, app(CollectDueItems::class)->within(60));
    }

    #[Test]
    public function an_aircraft_with_no_review_at_all_is_reported_not_omitted(): void
    {
        // The case such lists usually miss: nothing expires if nothing exists,
        // so an aircraft nobody ever entered a review for reads as fine.
        $this->aircraft();

        $due = app(CollectDueItems::class)->within(60);

        $this->assertCount(1, $due);
        $this->assertTrue($due->first()['overdue']);
        $this->assertSame(__('fleet.due.no_review'), $due->first()['detail']);
    }

    #[Test]
    public function an_expired_review_sorts_above_an_expiring_one(): void
    {
        // Somebody scanning this wants the trouble at the top, not the alphabet.
        $ok = $this->aircraft('D-KABC');
        $bad = $this->aircraft('D-KXYZ');

        $this->review($ok, validUntil: now()->addDays(30));
        $this->review($bad, validUntil: now()->subDays(5));

        $due = app(CollectDueItems::class)->within(60);

        $this->assertSame('D-KXYZ', $due->first()['aircraft']->registration);
        $this->assertTrue($due->first()['overdue']);
    }

    #[Test]
    public function a_calendar_limit_close_to_running_out_is_listed(): void
    {
        $aircraft = $this->aircraft();
        $this->review($aircraft, validUntil: now()->addYear());

        $release = $this->towRelease($aircraft, installedAt: now()->subMonths(23));
        ComponentLimit::create([
            'installation_id' => $release->id,
            'kind' => LimitKind::CalendarMonths,
            'value' => 24,
            'source' => 'Tost',
        ]);

        $due = app(CollectDueItems::class)->within(60);

        $this->assertCount(1, $due);
        $this->assertSame('limit', $due->first()['kind']);
        $this->assertStringContainsString('Tost', $due->first()['detail']);
    }

    #[Test]
    public function a_counted_limit_reports_in_its_own_units_not_in_days(): void
    {
        // Turning launches into days would need a flying rate, and an aircraft
        // that flew 200 hours last summer may fly none this one.
        $aircraft = $this->aircraft();
        $this->review($aircraft, validUntil: now()->addYear());

        $release = $this->towRelease($aircraft);
        ComponentLimit::create([
            'installation_id' => $release->id,
            'kind' => LimitKind::Starts,
            'value' => 500,
        ]);

        $this->read($aircraft, CounterKind::Starts, 480);

        $due = app(CollectDueItems::class)->within(60);

        $this->assertCount(1, $due);
        $this->assertSame(20.0, $due->first()['remaining']);
        $this->assertNull($due->first()['due_on'], 'A count has no date.');
        $this->assertSame(__('fleet.limit.starts'), $due->first()['unit']);
    }

    #[Test]
    public function a_counted_limit_with_plenty_left_stays_quiet(): void
    {
        $aircraft = $this->aircraft();
        $this->review($aircraft, validUntil: now()->addYear());

        $release = $this->towRelease($aircraft);
        ComponentLimit::create([
            'installation_id' => $release->id,
            'kind' => LimitKind::Starts,
            'value' => 500,
        ]);

        $this->read($aircraft, CounterKind::Starts, 100);

        $this->assertCount(0, app(CollectDueItems::class)->within(60));
    }

    #[Test]
    public function a_removed_component_no_longer_falls_due(): void
    {
        $aircraft = $this->aircraft();
        $this->review($aircraft, validUntil: now()->addYear());

        $release = $this->towRelease($aircraft, installedAt: now()->subMonths(30));
        ComponentLimit::create([
            'installation_id' => $release->id,
            'kind' => LimitKind::CalendarMonths,
            'value' => 24,
        ]);

        $this->assertCount(1, app(CollectDueItems::class)->within(60));

        $release->update(['removed_at' => now()->toDateString()]);

        $this->assertCount(0, app(CollectDueItems::class)->within(60));
    }

    #[Test]
    public function an_aircraft_out_of_service_is_left_out(): void
    {
        $aircraft = $this->aircraft();
        $this->review($aircraft, validUntil: now()->subYear());

        $this->assertCount(1, app(CollectDueItems::class)->within(60));

        $aircraft->update(['is_active' => false]);

        $this->assertCount(0, app(CollectDueItems::class)->within(60));
    }

    private function aircraft(string $registration = 'D-KABC'): Aircraft
    {
        return Aircraft::create([
            'registration' => $registration,
            'model' => 'ASK 21',
            'optional_counters' => [CounterKind::Starts->value],
        ]);
    }

    private function review(Aircraft $aircraft, Carbon $validUntil): AirworthinessReview
    {
        return AirworthinessReview::create([
            'aircraft_id' => $aircraft->id,
            'certificate_reference' => 'ARC-2026-1',
            'issued_at' => now()->subYear()->toDateString(),
            'valid_until' => $validUntil->toDateString(),
        ]);
    }

    private function towRelease(Aircraft $aircraft, ?Carbon $installedAt = null): Installation
    {
        return Installation::create([
            'aircraft_id' => $aircraft->id,
            'part_name' => 'Tost Schleppkupplung',
            'serial_number' => '1378X5V',
            'installed_at' => ($installedAt ?? now())->toDateString(),
            'counters_at_installation' => [CounterKind::Starts->value => 0.0],
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
