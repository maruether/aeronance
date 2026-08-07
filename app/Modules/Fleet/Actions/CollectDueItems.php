<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Actions;

use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\ComponentLimit;
use App\Modules\Fleet\Models\Installation;
use Illuminate\Support\Collection;

/**
 * Everything that is due, or about to be.
 *
 * The question a fleet list exists to answer -- "wann ist die Nachprüfung
 * fällig", and its quieter cousin "was läuft mir sonst noch ab". the requirement was
 * deadlines in this slice rather than the next, and this is why: without them
 * the module is a database that cannot answer the thing it was built for.
 *
 * Four sources, one list:
 *
 *  - the airworthiness review, which is the one everybody knows about;
 *  - component limits that have run out or are close, of whatever kind;
 *  - documents and weighings that carry a date;
 *  - aircraft with no valid review at all, which is worse than an expiring one
 *    and would otherwise be invisible -- nothing expires if nothing exists.
 *
 * That last case is the one such lists usually miss. An aircraft nobody has ever
 * entered a review for produces no expiring row, so it silently reads as fine.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * BUT ONLY THE ARC IS TREATED THAT WAY, and the distinction is the:
 *
 *   "vorsicht mit fälligkeiten ... ist oft ein 'kommt drauf an' thema. Manche
 *   lfz brauchen z.B. alle 4 Jahre eine wägung, andere nur bei bedarf. Das gilt
 *   für alle dokumente und bauteile."
 *
 * So a weighing with no expiry date is not an overdue weighing. It is a weighing
 * that does not expire, and reporting its absence would fill the list with work
 * nobody owes -- which is the fastest way to teach people to stop reading it.
 * The airworthiness review is the one thing every aircraft always owes, so it
 * alone is reported when missing.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class CollectDueItems
{
    /**
     * @return Collection<int, array{
     *     aircraft: Aircraft,
     *     kind: string,
     *     what: string,
     *     detail: string,
     *     due_on: ?string,
     *     remaining: ?float,
     *     unit: ?string,
     *     overdue: bool,
     * }>
     */
    public function within(int $days = 60): Collection
    {
        $items = collect();

        $aircraft = Aircraft::active()
            ->with(['airworthinessReviews', 'installations.limits', 'documents', 'weighings'])
            ->get();

        foreach ($aircraft as $one) {
            $items = $items->merge($this->reviewOf($one, $days));
            $items = $items->merge($this->limitsOf($one, $days));
            $items = $items->merge($this->papersOf($one, $days));
        }

        // Overdue first, then by how little is left. Somebody scanning this
        // wants the trouble at the top, not the alphabet.
        return $items
            ->sortBy(fn (array $item): array => [$item['overdue'] ? 0 : 1, $item['due_on'] ?? '9999-12-31'])
            ->values();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reviewOf(Aircraft $aircraft, int $days): array
    {
        $review = $aircraft->currentReview();

        if ($review === null) {
            // No review on file at all. Reported as overdue rather than left
            // out: an aircraft with no ARC is not an aircraft with nothing
            // expiring.
            return [[
                'aircraft' => $aircraft,
                'kind' => 'review',
                'what' => __('fleet.review.singular'),
                'detail' => __('fleet.due.no_review'),
                'due_on' => null,
                'remaining' => null,
                'unit' => null,
                'overdue' => true,
            ]];
        }

        $remaining = $review->daysRemaining();

        if ($remaining > $days) {
            return [];
        }

        return [[
            'aircraft' => $aircraft,
            'kind' => 'review',
            'what' => __('fleet.review.singular'),
            'detail' => (string) $review->certificate_reference,
            'due_on' => $review->valid_until->toDateString(),
            'remaining' => (float) $remaining,
            'unit' => __('fleet.due.days'),
            'overdue' => $remaining < 0,
        ]];
    }

    /**
     * Documents and weighings -- but only the ones that carry a date.
     *
     * No date means no deadline. Not a missing deadline: an absent one. The
     * difference is the whole of the warning, and getting it wrong in the
     * other direction would put every aircraft on the list for a weighing it
     * does not owe.
     *
     * @return list<array<string, mixed>>
     */
    private function papersOf(Aircraft $aircraft, int $days): array
    {
        $rows = [];

        foreach ($aircraft->documents as $document) {
            if (! $document->expires()) {
                continue;
            }

            $remaining = $document->daysRemaining();

            if ($remaining === null || $remaining > $days) {
                continue;
            }

            $rows[] = [
                'aircraft' => $aircraft,
                'kind' => 'document',
                'what' => $document->type->label(),
                'detail' => $document->title,
                'due_on' => $document->valid_until->toDateString(),
                'remaining' => (float) $remaining,
                'unit' => __('fleet.due.days'),
                'overdue' => $remaining < 0,
            ];
        }

        $weighing = $aircraft->weighings->first();

        // Only when a validity was entered. A club that weighs on demand owes
        // nothing on a date, and nagging about it would be noise.
        if ($weighing !== null && $weighing->valid_until !== null) {
            $remaining = (int) now()->startOfDay()->diffInDays($weighing->valid_until->startOfDay(), false);

            if ($remaining <= $days) {
                $rows[] = [
                    'aircraft' => $aircraft,
                    'kind' => 'weighing',
                    'what' => __('fleet.weighing.singular'),
                    'detail' => $weighing->weighed_at->format('d.m.Y'),
                    'due_on' => $weighing->valid_until->toDateString(),
                    'remaining' => (float) $remaining,
                    'unit' => __('fleet.due.days'),
                    'overdue' => $remaining < 0,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function limitsOf(Aircraft $aircraft, int $days): array
    {
        $rows = [];

        foreach ($aircraft->installations->whereNull('removed_at') as $installation) {
            foreach ($installation->limits as $limit) {
                $row = $this->rowFor($aircraft, $installation, $limit, $days);

                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rowFor(Aircraft $aircraft, Installation $installation, ComponentLimit $limit, int $days): ?array
    {
        $overdue = $limit->isOverdue();

        $status = $limit->status($days);

        if ($limit->kind->isCalendar()) {
            $remaining = $limit->remainingDays();

            if ($remaining === null || (! $overdue && $remaining > $days)) {
                return null;
            }

            return [
                'aircraft' => $aircraft,
                'kind' => 'limit',
                'what' => $installation->label(),
                'detail' => $limit->describe().($limit->source ? ' — '.$limit->source : ''),
                'due_on' => $limit->dueDate()?->toDateString(),
                'remaining' => (float) $remaining,
                'unit' => __('fleet.due.days'),

                // Past its date, tolerated or not -- the row appears either way,
                // and the status says which.
                'overdue' => $overdue,
                'status' => $status,
            ];
        }

        $remaining = $limit->remaining();

        if ($remaining === null) {
            return null;
        }

        /*
         * A counted limit has no date, so "within 60 days" cannot be asked of
         * it. Reporting the last tenth of its life instead is a judgement, and
         * a deliberately crude one -- turning launches into days would need a
         * flying rate, and an aircraft that flew 200 hours last summer may fly
         * none this one. Better a rough rule that admits what it is than
         * arithmetic dressed as a forecast.
         */
        $threshold = $limit->value !== null ? (float) $limit->value * 0.1 : 0.0;

        if (! $overdue && $remaining > $threshold) {
            return null;
        }

        return [
            'aircraft' => $aircraft,
            'kind' => 'limit',
            'what' => $installation->label(),
            'detail' => $limit->describe().($limit->source ? ' — '.$limit->source : ''),
            'due_on' => null,
            'remaining' => $remaining,
            'unit' => $limit->kind->label(),
            'overdue' => $overdue,
            'status' => $status,
        ];
    }
}
