<?php

declare(strict_types=1);

namespace App\Modules\Part66\Support;

use App\Models\User;
use App\Modules\Fleet\Models\AirworthinessReview;
use App\Modules\TaskCards\Models\ReleaseToService;
use App\Modules\TaskCards\Models\TaskCard;
use App\Modules\TaskCards\Models\TaskCardTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Somebody's experience, derived from the cards they worked on.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * IT ONLY READS. Nothing in this module writes an experience record, and that is
 * the point rather than a limitation.
 *
 * CLAUDE.md: "Das Erfahrungslogbuch ist eine Auswertung, keine Extra-Pflege."
 * A stored log would be a second copy of what the cards already say, and the
 * first time the two disagreed nobody could tell which was right. Deriving it
 * means the answer is always the cards -- and the cards are frozen once their
 * visit is released, so for the work that matters the derivation cannot shift
 * under anybody either.
 *
 * That freeze is why this works. Without it a derived log would be a log that
 * quietly rewrites itself, which is worse than no log.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ExperienceLog
{
    /**
     * Every line for one person, newest last.
     *
     * @return Collection<int, ExperienceEntry>
     */
    public function for(User $person, ?string $from = null, ?string $to = null): Collection
    {
        $times = TaskCardTime::query()
            ->where('user_id', $person->id)
            ->when($from !== null, fn ($q) => $q->whereDate('worked_on', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('worked_on', '<=', $to))
            ->with(['taskCard.workOrder'])
            ->orderBy('worked_on')
            ->orderBy('id')
            ->get();

        /*
         * Release numbers looked up in one query rather than per row.
         *
         * A logbook covering ten years is a few thousand rows, and one query per
         * row is how a page that works in testing takes half a minute in
         * practice.
         */
        $releases = ReleaseToService::query()
            ->whereIn('work_order_id', $times->pluck('taskCard.work_order_id')->filter()->unique())
            ->current()
            ->get()
            ->keyBy('work_order_id');

        return $times
            ->filter(fn (TaskCardTime $time): bool => $time->taskCard !== null)
            ->map(function (TaskCardTime $time) use ($releases): ExperienceEntry {
                $card = $time->taskCard;
                $order = $card->workOrder;

                return new ExperienceEntry(
                    date: $time->worked_on,
                    registration: $card->aircraft_registration,
                    model: $card->aircraft_model,
                    ataChapter: $card->ata_chapter,
                    activity: $card->activity_kind,
                    minutes: $time->minutes,
                    participation: $time->participation,
                    cardNumber: $card->number,
                    workPerformed: $card->work_performed,
                    certifiedByName: $card->certified_by_name,
                    releaseNumber: $releases->get($card->work_order_id)?->number,

                    // Frozen work is settled; anything else is a figure that may
                    // still move, and the log says which is which.
                    provisional: ! ($order?->isReleased() ?? false),
                );
            })
            ->values();
    }

    /**
     * Hours by kind of work.
     *
     * The split a licence assessment actually looks at: "300 hours of
     * maintenance" says far less than the division between inspection, repair
     * and modification.
     *
     * @param  Collection<int, ExperienceEntry>  $entries
     * @return array<string, float>
     */
    public function hoursByActivity(Collection $entries): array
    {
        return $entries
            ->groupBy(fn (ExperienceEntry $e): string => $e->activity->value)
            ->map(fn (Collection $group): float => round($group->sum(fn (ExperienceEntry $e): int => $e->minutes) / 60, 2))
            ->sortDesc()
            ->all();
    }

    /**
     * Hours by aircraft type.
     *
     * @param  Collection<int, ExperienceEntry>  $entries
     * @return array<string, float>
     */
    public function hoursByModel(Collection $entries): array
    {
        return $entries
            ->groupBy(fn (ExperienceEntry $e): string => $e->model ?? $e->registration)
            ->map(fn (Collection $group): float => round($group->sum(fn (ExperienceEntry $e): int => $e->minutes) / 60, 2))
            ->sortDesc()
            ->all();
    }

    /**
     * Hours by how the person took part.
     *
     * Kept apart because 66.A.20(b) counts what somebody DID, and assisting is
     * not the same entry as doing.
     *
     * @param  Collection<int, ExperienceEntry>  $entries
     * @return array<string, float>
     */
    public function hoursByParticipation(Collection $entries): array
    {
        return $entries
            ->groupBy(fn (ExperienceEntry $e): string => $e->participation->value)
            ->map(fn (Collection $group): float => round($group->sum(fn (ExperienceEntry $e): int => $e->minutes) / 60, 2))
            ->all();
    }

    /**
     * Releases this person signed, which is a different record from hours worked.
     *
     * @return Collection<int, ReleaseToService>
     */
    public function releasesBy(User $person, ?string $from = null, ?string $to = null): Collection
    {
        /*
         * Deliberately NOT filtered to current releases. A superseded
         * certificate was still an act of certification by the person who
         * signed it -- correcting the paperwork later does not unmake the act,
         * and this is an experience record, not a validity record. Filtering
         * here made a signer's recency count drop when a colleague corrected a
         * typo in the maintenance-data line.
         */
        return ReleaseToService::query()
            ->where('released_by', $person->id)
            ->when($from !== null, fn ($q) => $q->whereDate('released_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('released_at', '<=', $to))
            ->orderBy('released_at')
            ->get();
    }

    /**
     * Airworthiness reviews this person issued.
     *
     * The third kind of act in this module, and a separate one: working on an
     * aircraft, releasing work, and reviewing an aircraft's airworthiness are
     * three different things a person does, and only the last one keeps an ARS
     * qualification alive.
     *
     * Not filtered to still-valid certificates -- for the same reason superseded
     * releases still count. An expired ARC was an act of review when it was
     * issued; this is an experience record, not a validity record.
     *
     * @return Collection<int, AirworthinessReview>
     */
    public function reviewsBy(User $person, ?string $from = null, ?string $to = null): Collection
    {
        return AirworthinessReview::query()
            ->where('user_id', $person->id)
            ->when($from !== null, fn ($q) => $q->whereDate('issued_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('issued_at', '<=', $to))
            ->with('aircraft')
            ->orderBy('issued_at')
            ->get();
    }

    /**
     * Cards this person signed off without necessarily having worked on them.
     *
     * Certifying is its own activity and its own record -- somebody who spends a
     * year checking other people's work has experience that no hours entry
     * captures.
     */
    public function certificationCountBy(User $person, ?string $from = null, ?string $to = null): int
    {
        return TaskCard::query()
            ->where('certified_by', $person->id)
            ->when($from !== null, fn ($q) => $q->whereDate('certified_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('certified_at', '<=', $to))
            ->count();
    }

    /**
     * The earliest and latest day with any entry.
     *
     * @param  Collection<int, ExperienceEntry>  $entries
     * @return array{from: ?Carbon, to: ?Carbon}
     */
    public function span(Collection $entries): array
    {
        return [
            'from' => $entries->first()?->date,
            'to' => $entries->last()?->date,
        ];
    }
}
