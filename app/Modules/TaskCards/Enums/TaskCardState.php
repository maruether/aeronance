<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Enums;

/**
 * How far a card has got.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DONE IS NOT CHECKED, and the decision was that deliberately: "wer die arbeit
 * gemacht hat, meldet sie fertig. ein Qualifizierter zeichnet sie danach ab."
 *
 * It is the workshop as it actually runs. A mechanic without a licence finishes
 * the job and says so; somebody qualified looks at it afterwards and signs. Two
 * signatures, two moments, potentially two people -- and collapsing them into
 * one "done" would either lock out the mechanic or let unqualified work pass as
 * certified.
 *
 * Note what neither of them is: a release to service. That is the CRS, it
 * belongs to its own module, and it concerns the aircraft rather than the card.
 * ─────────────────────────────────────────────────────────────────────────────
 */
enum TaskCardState: string
{
    case Open = 'open';

    /** The work is finished. Says nothing about whether it was any good. */
    case Completed = 'completed';

    /** Somebody qualified has looked at it and signed. */
    case Certified = 'certified';

    /** Not going to be done -- with a reason, because that is the interesting part. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('taskcards.state.'.$this->value);
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    /** Finished but nobody has checked it. */
    public function awaitsCertification(): bool
    {
        return $this === self::Completed;
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Certified, self::Cancelled], strict: true);
    }
}
