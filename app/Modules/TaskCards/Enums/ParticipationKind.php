<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Enums;

/**
 * How somebody took part in a job.
 *
 * Part-66 66.A.20(b) counts experience by what a person DID, not by what they
 * were present for. Two mechanics on one card for three hours each is six hours
 * of work and two different logbook entries -- and if one of them was assisting,
 * that is a different entry again.
 *
 * Which is why this is a column and not a note: the experience logbook is meant
 * to be an evaluation of the cards rather than a second thing to keep, and it
 * can only be that if the cards carry the distinction.
 */
enum ParticipationKind: string
{
    /** Did the work. */
    case Executed = 'executed';

    /** Helped somebody else do it. */
    case Assisted = 'assisted';

    /** Supervised without doing it -- counts differently again. */
    case Supervised = 'supervised';

    public function label(): string
    {
        return __('taskcards.participation.'.$this->value);
    }
}
