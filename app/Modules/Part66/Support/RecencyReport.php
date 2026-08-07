<?php

declare(strict_types=1);

namespace App\Modules\Part66\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the last two years look like, for 66.A.20(b).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * IT REPORTS FIGURES AND DOES NOT DECLARE COMPLIANCE, deliberately.
 *
 * 66.A.20(b)(1) asks for six months of maintenance experience in the preceding
 * two years before certifying privileges may be exercised. For somebody in paid
 * employment that is a straightforward reading. For a club volunteer who works
 * three Saturdays a month it is genuinely not: six months of WHAT -- calendar
 * months touched? Days present? A pro-rata reckoning of hours against a working
 * month?
 *
 * The regulation does not settle that for the volunteer case, and a number this
 * class invented would be worse than no number: somebody would rely on it. So it
 * counts what the data actually says -- days, months, hours -- and leaves the
 * conclusion to the person whose licence it is, or to their authority.
 *
 * Same principle as the airworthiness check: the tool surfaces what nobody
 * should have to dig for, and does not pretend to a judgement it cannot make.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class RecencyReport
{
    /** The window 66.A.20(b) looks back over. */
    public const WINDOW_MONTHS = 24;

    public function __construct(private readonly ExperienceLog $log) {}

    /**
     * @return array{
     *     from: Carbon,
     *     to: Carbon,
     *     days: int,
     *     months: int,
     *     hours: float,
     *     certifications: int,
     *     releases: int,
     *     reviews: int,
     *     last_worked: ?Carbon,
     *     gap_days: ?int,
     *     entries: Collection<int, ExperienceEntry>,
     * }
     */
    public function for(User $person, ?string $asOf = null): array
    {
        $to = $asOf !== null ? Carbon::parse($asOf) : now();
        /*
         * subMonthsNoOverflow, not subMonths.
         *
         * ─────────────────────────────────────────────────────────────────────
         * Carbon's subMonths lets a short month overflow forward: from the 31st
         * of a month, "minus one month" lands on the 1st of the month after the
         * one intended. At 24 the two always agree, because two whole years land
         * on the same date -- but that is a property of the constant, not of the
         * code, and 66.A.20(b) is the kind of rule that gets tightened.
         *
         * The direction of the error is what makes it worth pre-empting: an
         * overflow moves the window START forward, so the window gets SHORTER and
         * work that should count silently drops out. A mechanic would be told
         * they are out of recency while their own logbook says otherwise.
         * ─────────────────────────────────────────────────────────────────────
         */
        $from = $to->copy()->subMonthsNoOverflow(self::WINDOW_MONTHS);

        $entries = $this->log->for($person, $from->toDateString(), $to->toDateString());

        $lastWorked = $entries->last()?->date;

        return [
            'from' => $from,
            'to' => $to,

            /*
             * Distinct DAYS with work, not entries.
             *
             * Three cards on one Saturday is one day of experience, and counting
             * entries would turn a busy afternoon into three.
             */
            'days' => $entries
                ->map(fn (ExperienceEntry $e): string => $e->date->toDateString())
                ->unique()
                ->count(),

            /*
             * Distinct calendar months touched.
             *
             * The figure closest to how the regulation is worded, which is
             * exactly why it is offered rather than acted on: "six months of
             * experience" and "worked in six different months" are not the same
             * claim, and the difference matters to somebody with a licence.
             */
            'months' => $entries
                ->map(fn (ExperienceEntry $e): string => $e->date->format('Y-m'))
                ->unique()
                ->count(),

            'hours' => round($entries->sum(fn (ExperienceEntry $e): int => $e->minutes) / 60, 2),

            'certifications' => $this->log->certificationCountBy($person, $from->toDateString(), $to->toDateString()),
            'releases' => $this->log->releasesBy($person, $from->toDateString(), $to->toDateString())->count(),

            /*
             * Airworthiness reviews issued in the window.
             *
             * the reading of the "Lizenz-/ARS-Zähler" line in CLAUDE.md:
             * the number of ARCs and the hours behind them over two years, as an
             * overview for somebody holding a Part-66 licence. Reported as a
             * figure like everything else here -- what threshold keeps an ARS
             * qualification alive is not something this class decides.
             */
            'reviews' => $this->log->reviewsBy($person, $from->toDateString(), $to->toDateString())->count(),

            'last_worked' => $lastWorked,

            /*
             * How long since the last entry.
             *
             * The number somebody actually wants: a licence holder who has not
             * touched an aircraft in fourteen months has a problem approaching,
             * and a total of hours spread over two years does not show it.
             */
            'gap_days' => $lastWorked !== null
                ? (int) $lastWorked->startOfDay()->diffInDays($to->startOfDay())
                : null,

            'entries' => $entries,
        ];
    }

    /**
     * Things worth saying out loud about a report.
     *
     * Observations, not verdicts. Each one is something a person would want to
     * notice; none of them says whether the licence is in order.
     *
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    public function observations(array $report): array
    {
        $notes = [];

        if ($report['entries']->isEmpty()) {
            return [__('part66.recency.nothing_in_window', ['months' => self::WINDOW_MONTHS])];
        }

        if ($report['months'] < 6) {
            $notes[] = __('part66.recency.few_months', ['months' => $report['months']]);
        }

        if ($report['gap_days'] !== null && $report['gap_days'] > 180) {
            $notes[] = __('part66.recency.long_gap', ['days' => $report['gap_days']]);
        }

        $provisional = $report['entries']->filter(fn (ExperienceEntry $e): bool => $e->provisional)->count();

        if ($provisional > 0) {
            // Worth flagging: unreleased work can still change, so those figures
            // are not yet settled.
            $notes[] = __('part66.recency.provisional', ['count' => $provisional]);
        }

        return $notes;
    }
}
