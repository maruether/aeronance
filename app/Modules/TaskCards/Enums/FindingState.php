<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Enums;

/**
 * What became of something that was noticed.
 *
 * Findings get their own life because that is what they have. You take out a
 * screw and see a crack; it is not part of the card you were doing, and it does
 * not go away because that card is finished.
 *
 * DEFERRED is the state worth having. "Holds until the next inspection" is a
 * real and legitimate decision -- and one that must stay visible, because the
 * whole risk of a deferred finding is that it is quietly forgotten.
 */
enum FindingState: string
{
    case Open = 'open';

    /** A card was raised for it. */
    case Scheduled = 'scheduled';

    /** Deliberately left, with a reason and by somebody who may decide that. */
    case Deferred = 'deferred';

    case Resolved = 'resolved';

    /** Looked at again and found not to be a defect after all. */
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return __('taskcards.finding_state.'.$this->value);
    }

    /** Still hanging over the aircraft. */
    public function isOutstanding(): bool
    {
        return in_array($this, [self::Open, self::Scheduled, self::Deferred], strict: true);
    }
}
