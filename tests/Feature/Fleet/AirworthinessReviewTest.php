<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Modules\Fleet\Actions\IssueAirworthinessReview;
use App\Modules\Fleet\Models\Aircraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * When an airworthiness review runs out.
 *
 * the rule, and it is the same shape as the maintenance tolerance:
 *
 *   issued within 90 days before the old expiry -> the old date carries
 *   issued earlier, or after it has lapsed      -> day of issue plus 364
 *
 * Calculated and never typed. Anything known that a person types is something a
 * person can get wrong once a year, quietly, in the direction of flying longer.
 */
final class AirworthinessReviewTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_first_review_runs_364_days(): void
    {
        // Not 365: issued on the 29th, good through the 28th of the following
        // year, so it never lapses on its own anniversary.
        $aircraft = $this->aircraft();

        $review = app(IssueAirworthinessReview::class)->handle($aircraft, '2026-07-29');

        $this->assertSame('2027-07-28', $review->valid_until->toDateString());
    }

    #[Test]
    public function inside_the_ninety_day_window_the_old_date_carries(): void
    {
        // The point of the window: an annual review stays annual instead of
        // creeping earlier every year as people book the inspection a fortnight
        // before it is due.
        $aircraft = $this->aircraft();
        app(IssueAirworthinessReview::class)->handle($aircraft, '2025-07-29');

        $old = $aircraft->fresh()->currentReview()->valid_until->toDateString();
        $this->assertSame('2026-07-28', $old);

        // Thirty days early.
        $review = app(IssueAirworthinessReview::class)->handle($aircraft->fresh(), '2026-06-28');

        $this->assertSame('2027-07-28', $review->valid_until->toDateString(), 'A year on from the old date.');
    }

    #[Test]
    public function exactly_ninety_days_early_still_carries(): void
    {
        $aircraft = $this->aircraft();
        app(IssueAirworthinessReview::class)->handle($aircraft, '2025-07-29');

        $ninetyDaysBefore = Carbon::parse('2026-07-28')->subDays(90)->toDateString();

        $review = app(IssueAirworthinessReview::class)->handle($aircraft->fresh(), $ninetyDaysBefore);

        $this->assertSame('2027-07-28', $review->valid_until->toDateString());
    }

    #[Test]
    public function a_day_too_early_and_the_new_date_wins(): void
    {
        // Ninety-one days out: the certificate is good for its own 364 days,
        // and the aircraft loses the remainder of the old one. Which is the
        // correct direction to lose it in.
        $aircraft = $this->aircraft();
        app(IssueAirworthinessReview::class)->handle($aircraft, '2025-07-29');

        $tooEarly = Carbon::parse('2026-07-28')->subDays(91);

        $review = app(IssueAirworthinessReview::class)->handle($aircraft->fresh(), $tooEarly->toDateString());

        $this->assertSame(
            $tooEarly->copy()->addDays(364)->toDateString(),
            $review->valid_until->toDateString(),
        );
    }

    #[Test]
    public function after_it_has_lapsed_the_new_date_wins_too(): void
    {
        // No reward for being late: the old date is gone, so the new
        // certificate starts its own 364 days from the day it was issued.
        $aircraft = $this->aircraft();
        app(IssueAirworthinessReview::class)->handle($aircraft, '2025-07-29');

        $review = app(IssueAirworthinessReview::class)->handle($aircraft->fresh(), '2026-09-15');

        $this->assertSame('2027-09-14', $review->valid_until->toDateString());
    }

    #[Test]
    public function the_screen_can_say_why_the_date_is_what_it_is(): void
    {
        // The rule is not obvious, and a date that appears without explanation
        // invites somebody to correct it.
        $aircraft = $this->aircraft();
        app(IssueAirworthinessReview::class)->handle($aircraft, '2025-07-29');

        $action = app(IssueAirworthinessReview::class);

        $this->assertTrue($action->carriesOldDate($aircraft->fresh(), Carbon::parse('2026-06-28')));
        $this->assertFalse($action->carriesOldDate($aircraft->fresh(), Carbon::parse('2026-01-01')));
        $this->assertFalse($action->carriesOldDate($aircraft->fresh(), Carbon::parse('2026-09-15')));
    }

    #[Test]
    public function carrying_the_date_forward_repeatedly_stays_on_the_same_day(): void
    {
        // Three years of reviews booked a month early each time, and the expiry
        // must still land on the same calendar day.
        $aircraft = $this->aircraft();
        app(IssueAirworthinessReview::class)->handle($aircraft, '2025-07-29');

        foreach (['2026-06-28', '2027-06-28', '2028-06-28'] as $issued) {
            app(IssueAirworthinessReview::class)->handle($aircraft->fresh(), $issued);
        }

        $this->assertSame('2029-07-28', $aircraft->fresh()->currentReview()->valid_until->toDateString());
    }

    private function aircraft(): Aircraft
    {
        return Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);
    }
}
