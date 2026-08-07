<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Actions;

use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AirworthinessReview;
use Illuminate\Support\Carbon;

/**
 * Issuing an airworthiness review, and working out when it runs out.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The expiry is CALCULATED, never typed. It follows a rule that is known, and
 * anything known that a person has to type is something a person can get wrong
 * once a year, quietly, in the direction of flying longer.
 *
 * the rule:
 *
 *   Issued WITHIN 90 DAYS before the old expiry  -> the old date carries, and
 *                                                   the new one is a year on
 *                                                   from it.
 *   Issued EARLIER, or after it has already run  -> the new one is the day of
 *                                                   issue plus 364 days.
 *
 * Which is the same shape as the maintenance tolerance, and for the same reason:
 * doing something early must not cost you the remainder, but nor may it hand you
 * time you did not have. The ninety-day window is what makes an annual review
 * stay annual instead of creeping earlier every year as people book the
 * inspection a fortnight before it is due.
 *
 * The 364 rather than 365 is deliberate and not an off-by-one: a certificate
 * issued on the 29th is good through the 28th of the following year, so it has
 * never lapsed on its own anniversary.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class IssueAirworthinessReview
{
    /** How close to expiry the old date still carries. */
    private const CARRY_WINDOW_DAYS = 90;

    /** @param  array<string, mixed>  $attributes  reference, issuer, approval, ... */
    public function handle(
        Aircraft $aircraft,
        string $issuedAt,
        array $attributes = [],
        ?User $user = null,
    ): AirworthinessReview {
        $issued = Carbon::parse($issuedAt)->startOfDay();

        return AirworthinessReview::create(array_merge($attributes, [
            'aircraft_id' => $aircraft->id,
            'issued_at' => $issued->toDateString(),
            'valid_until' => $this->validUntil($aircraft, $issued)->toDateString(),
            'user_id' => $user?->id,
        ]));
    }

    /**
     * What the expiry would be, for a form to show before anything is saved.
     *
     * Shown rather than asked: somebody looking at a date they did not type is
     * far more likely to notice it is wrong than somebody typing one.
     */
    public function validUntil(Aircraft $aircraft, Carbon $issued): Carbon
    {
        $previous = $aircraft->currentReview();

        if ($previous === null) {
            return $issued->copy()->addDays(364);
        }

        $expiry = $previous->valid_until->copy()->startOfDay();

        // Inside the window, and not after it has already lapsed: the old date
        // carries, so an annual review stays annual instead of creeping earlier
        // each year.
        $withinWindow = $issued->lte($expiry)
            && $issued->gte($expiry->copy()->subDays(self::CARRY_WINDOW_DAYS));

        return $withinWindow
            ? $expiry->copy()->addYear()
            : $issued->copy()->addDays(364);
    }

    /**
     * Whether this issue date would carry the old expiry forward.
     *
     * For the screen to say WHY the date it is showing is the date it is
     * showing -- the rule is not obvious, and a date that appears without
     * explanation invites somebody to correct it.
     */
    public function carriesOldDate(Aircraft $aircraft, Carbon $issued): bool
    {
        $previous = $aircraft->currentReview();

        if ($previous === null) {
            return false;
        }

        $expiry = $previous->valid_until->copy()->startOfDay();

        return $issued->startOfDay()->lte($expiry)
            && $issued->startOfDay()->gte($expiry->copy()->subDays(self::CARRY_WINDOW_DAYS));
    }
}
