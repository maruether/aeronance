<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Enums;

/**
 * How a limit stands.
 *
 * Four states, not two, and the third is the one that was missing: an inspection
 * four days past a twelve-month interval is not in the same condition as one
 * forgotten for a year, and a list that shows them alike teaches people to
 * ignore the colour.
 */
enum LimitStatus: string
{
    case Ok = 'ok';

    /** Coming up, inside the warning window. */
    case Due = 'due';

    /** Past the date, but inside the permitted overrun. */
    case InTolerance = 'in_tolerance';

    /** Past everything. */
    case Overdue = 'overdue';

    public function label(): string
    {
        return __('fleet.limit_status.'.$this->value);
    }

    public function colour(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Due => 'warning',
            self::InTolerance => 'warning',
            self::Overdue => 'danger',
        };
    }

    /** Anything that wants somebody's attention. */
    public function needsAttention(): bool
    {
        return $this !== self::Ok;
    }

    /** Anything past its due date, tolerated or not. */
    public function isPastDue(): bool
    {
        return in_array($this, [self::InTolerance, self::Overdue], strict: true);
    }
}
