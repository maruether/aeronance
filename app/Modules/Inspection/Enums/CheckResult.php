<?php

declare(strict_types=1);

namespace App\Modules\Inspection\Enums;

/**
 * The answer to one checklist item.
 *
 * There is deliberately no "unknown" and no empty answer that counts as done: a
 * checklist with blanks in it is the paperwork equivalent of a shrug. An
 * unanswered item is simply a null column, and an inspection with nulls cannot
 * be signed.
 */
enum CheckResult: string
{
    case Pass = 'pass';

    case Fail = 'fail';

    /**
     * Does not apply to this delivery.
     *
     * Needs a note, always -- see CompleteIncomingInspection. "Entfällt" without
     * a reason is indistinguishable from "could not be bothered", and six months
     * later nobody can tell which it was.
     */
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return __('inspection.result.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pass => 'success',
            self::Fail => 'danger',
            self::NotApplicable => 'gray',
        };
    }

    public function needsNote(): bool
    {
        return $this !== self::Pass;
    }
}
