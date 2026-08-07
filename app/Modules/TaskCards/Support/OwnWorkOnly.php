<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Support;

use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\TaskCards\Enums\ParticipationKind;
use App\Modules\TaskCards\Models\TaskCard;

/**
 * The pilot-owner limit: only what they did themselves.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * A correction to something I had built wrongly in two places:
 *
 *   "crs darf fremdarbeiten freigeben. PO explizit nur das was er selbst gemacht
 *   hat. das steht hart in der 1321/2014 drin"
 *
 * Part-66 certifying staff release work regardless of who performed it -- that
 * is what the licence is for. A pilot-owner authorisation is something else
 * entirely: it lets somebody sign for their OWN limited maintenance on their own
 * aircraft, and for nothing else. The two are not degrees of the same thing.
 *
 * I had treated them as interchangeable wherever a qualification was wanted,
 * which quietly granted pilot-owners a certifying privilege they do not have.
 *
 * THE STRICT READING IS THE RIGHT ONE HERE. Where several people worked on a
 * card, a pilot-owner may not sign it -- the work includes somebody else's, and
 * "only what he did himself" then plainly does not hold. Reading it loosely
 * ("he did SOME of it") would let a pilot-owner certify a mechanic's work by
 * putting his own name on ten minutes of it, which is precisely the arrangement
 * the rule exists to prevent.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class OwnWorkOnly
{
    /**
     * Whether this qualification may sign for this card.
     *
     * A Part-66 licence always may. A pilot-owner authorisation may only where
     * every recorded hour is that person's own.
     */
    public static function permits(Qualification $qualification, TaskCard $card, User $user): bool
    {
        if ($qualification->type !== Qualification::TYPE_PILOT_OWNER) {
            return true;
        }

        return self::isEntirelyOwnWork($card, $user);
    }

    /**
     * Whether every hour on this card belongs to one person.
     *
     * Supervised time counts as somebody else's involvement, deliberately: being
     * supervised means another person answered for how it was done, and a
     * pilot-owner signing that off would be signing for their judgement too.
     */
    public static function isEntirelyOwnWork(TaskCard $card, User $user): bool
    {
        $times = $card->times()->get();

        if ($times->isEmpty()) {
            return false;
        }

        /*
         * Every row theirs AND every row "executed". The participation kind is
         * not decoration here: a row of the owner's saying "assisted" means
         * somebody else did the executing, whether or not that person recorded
         * time -- and "supervised" means somebody else answered for the how.
         * Checking only the user id would read both as own work.
         */
        return $times->every(fn ($time): bool => (int) $time->user_id === (int) $user->id
            && $time->participation === ParticipationKind::Executed);
    }

    /**
     * Why it was refused, in words somebody can act on.
     */
    public static function refusalReason(TaskCard $card, User $user): string
    {
        $others = $card->times()
            ->get()
            ->reject(fn ($time): bool => (int) $time->user_id === (int) $user->id)
            ->pluck('person_name')
            ->unique()
            ->implode(', ');

        if ($others === '') {
            return __('taskcards.pilot_owner.no_own_work');
        }

        return __('taskcards.pilot_owner.others_worked', ['names' => $others]);
    }
}
