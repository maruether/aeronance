<?php

declare(strict_types=1);

namespace App\Modules\Part66\Support;

use App\Modules\TaskCards\Enums\ActivityKind;
use App\Modules\TaskCards\Enums\ParticipationKind;
use Illuminate\Support\Carbon;

/**
 * One line of somebody's experience log.
 *
 * A value object rather than a table, and that is the whole design: CLAUDE.md
 * says the log is "eine Auswertung, keine Extra-Pflege". A second table would be
 * a second thing to keep right, and the first time it drifted from the cards
 * nobody would know which one was true.
 *
 * The fields are the ones CLAUDE.md named for the very first card -- date,
 * registration, model, ATA chapter, kind of work, duration, executed/assisted,
 * certifying person -- which is why they were put on that first card rather than
 * added later.
 */
final readonly class ExperienceEntry
{
    public function __construct(
        public Carbon $date,
        public string $registration,
        public ?string $model,
        public ?string $ataChapter,
        public ActivityKind $activity,
        public int $minutes,
        public ParticipationKind $participation,
        public string $cardNumber,
        public ?string $workPerformed,
        public ?string $certifiedByName,
        public ?string $releaseNumber,
        /**
         * Whether the entry can still change.
         *
         * True until the visit is released. Released work is frozen, so its log
         * lines are as fixed as the cards behind them -- which is what makes a
         * derived log trustworthy at all. Anything still open is a provisional
         * figure and says so.
         */
        public bool $provisional,
    ) {}

    public function hours(): float
    {
        return round($this->minutes / 60, 2);
    }

    public function duration(): string
    {
        return sprintf('%d:%02d', intdiv($this->minutes, 60), $this->minutes % 60);
    }
}
