<?php

declare(strict_types=1);

namespace App\Modules\Directives\Enums;

/**
 * What this operation says about a directive for one aircraft.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * FOUR STATES, AND THE FOURTH IS THE INTERESTING ONE.
 *
 * Vorgabe: "es gibt aber nicht nur ja/nein sondern auch nicht zutreffend (mit
 * begründung) und nicht durchgeführt."
 *
 * `Open` is the absence of an answer -- nobody has looked at this line yet.
 * `NotCarriedOut` is an ANSWER: it applies to us, we know, and it has not been
 * done. Those two look similar in a list and mean opposite things about the
 * people involved, which is why they are separate states rather than one
 * "not done".
 *
 * The practical consequence: `NotCarriedOut` on a mandatory directive is an
 * airworthiness statement and blocks. `Open` is a reminder that somebody still
 * has to read the line.
 * ─────────────────────────────────────────────────────────────────────────────
 */
enum ComplianceState: string
{
    /** Nobody has assessed this line yet. */
    case Open = 'open';

    /** Carried out. For a recurring directive: until the interval comes round. */
    case Complied = 'complied';

    /** Assessed and does not apply -- with a reason, always. */
    case NotApplicable = 'not_applicable';

    /** Applies, and deliberately not done -- with a reason, always. */
    case NotCarriedOut = 'not_carried_out';

    public function label(): string
    {
        return __('directives.state.'.$this->value);
    }

    /**
     * Whether a reason is required.
     *
     * For both negative answers, because a reason is the entire value of them.
     * "Not applicable" without one is indistinguishable from somebody clearing
     * their list.
     */
    public function requiresReason(): bool
    {
        return $this === self::NotApplicable || $this === self::NotCarriedOut;
    }

    /** Whether somebody has looked at the line at all. */
    public function isAssessed(): bool
    {
        return $this !== self::Open;
    }

    public function color(): string
    {
        return match ($this) {
            self::Complied => 'success',
            self::NotApplicable => 'gray',
            self::NotCarriedOut => 'danger',
            self::Open => 'warning',
        };
    }
}
