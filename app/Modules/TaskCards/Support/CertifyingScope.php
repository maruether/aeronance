<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Support;

use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\TaskCards\Models\TaskCard;

/**
 * How far a signature reaches -- three steps, not two.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * OwnWorkOnly answers one question: whose work may this person sign for? The brief
 * settled it and it has not changed:
 *
 *   "crs darf fremdarbeiten freigeben. PO explizit nur das was er selbst gemacht
 *   hat. das steht hart in der 1321/2014 drin"
 *
 * There is a second question underneath it -- WHAT may this person sign for --
 * and it only became visible with the MA.803(b) cap. Vorgabe: "das non-complex
 * ist die eintragung 'no maintance exeding MA.803(b)', also P/O. Die Leute
 * dürfen damit nicht mehr Sachen freigeben als ein P/O, aber für Fremdarbeiten."
 *
 *   ┌───────────────────────────┬──────────────────┬──────────────────────────┐
 *   │                           │ others' work?    │ scope of the release     │
 *   ├───────────────────────────┼──────────────────┼──────────────────────────┤
 *   │ Part-66, unrestricted     │ yes              │ the whole licence        │
 *   │ Part-66 + MA.803(b) cap   │ YES              │ pilot-owner tasks only   │
 *   │ pilot-owner authorisation │ no, only his own │ pilot-owner tasks only   │
 *   └───────────────────────────┴──────────────────┴──────────────────────────┘
 *
 * The middle row is the new one, and it is not "half a pilot-owner": the cap
 * limits the SCOPE and leaves the privilege to sign for other people's work
 * untouched. Reading it as a pilot-owner would take away something the licence
 * grants; reading it as an unrestricted licence would grant something it does
 * not.
 *
 * This class composes the two rules and does not replace either -- OwnWorkOnly
 * still owns the ownership question and is still the only place it is answered.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class CertifyingScope
{
    /**
     * Why this qualification may not sign this card -- null if it may.
     *
     * Returns the reason rather than a boolean because every caller needs it:
     * "nicht zulässig" without a reason is how people learn to click past
     * refusals.
     */
    public static function refusalFor(Qualification $qualification, TaskCard $card, User $user): ?string
    {
        if ($qualification->type === Qualification::TYPE_PILOT_OWNER) {
            if (! OwnWorkOnly::permits($qualification, $card, $user)) {
                return OwnWorkOnly::refusalReason($card, $user);
            }

            /*
             * A pilot-owner is bound to the same task list as the capped
             * licence. Only an explicit "no" refuses here, though: an unassessed
             * card keeps working as it always has, because the ownership rule
             * above is already doing the work -- somebody signing for their own
             * afternoon on their own aircraft is the case ML.A.803 describes.
             * The capped licence has no such second rule, which is why it is
             * stricter about the unassessed card below.
             */
            if ($card->within_pilot_owner_scope === false) {
                return __('taskcards.pilot_owner.beyond_scope');
            }

            return null;
        }

        /*
         * The endorsed limitations, which are licence-wide.
         *
         * Vorgabe: "Die Zellentypen können eingeschränkt werden und zählen über
         * die gesamte Lizenz. Wenn ich beantrage bekomme ich z.B. die
         * Einschränkung 'ausgenommen Zellen in Metallbauweise', da ist egal ob
         * das L1 oder L2 ist." So this is checked once, against the aircraft,
         * and not per category.
         *
         * Checked BEFORE the MA.803(b) cap because it is the harder no: a cap
         * narrows what may be signed, an exclusion removes the aircraft from the
         * licence's reach entirely.
         */
        $aircraft = $card->workOrder?->aircraft;

        if ($aircraft !== null) {
            $subject = $aircraft->workSubject();

            foreach ($qualification->limitations as $limitation) {
                if ($limitation->blocks($subject)) {
                    return __('taskcards.limitation.blocks', [
                        'limitation' => $limitation->label(),
                        'registration' => (string) $aircraft->registration,
                    ]);
                }
            }
        }

        if ($qualification->isCappedToPilotOwnerScope()) {
            if ($card->within_pilot_owner_scope === null) {
                return __('taskcards.ma803b.not_assessed', ['card' => $card->number]);
            }

            if ($card->within_pilot_owner_scope === false) {
                return __('taskcards.ma803b.beyond_scope', ['card' => $card->number]);
            }
        }

        return null;
    }

    public static function permits(Qualification $qualification, TaskCard $card, User $user): bool
    {
        return self::refusalFor($qualification, $card, $user) === null;
    }
}
