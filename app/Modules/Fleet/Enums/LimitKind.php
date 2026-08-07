<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Enums;

/**
 * What a component's life is measured in.
 *
 * Either calendar time, or one of the counters. the example is the shape of
 * the whole thing: a Tost tow release runs "2 Jahre oder 500 Starts, whatever
 * comes first" -- so a component carries SEVERAL limits of DIFFERENT kinds, and
 * what falls due is whichever arrives first.
 *
 * That is why this is a kind on a limit rather than a column on a component. A
 * column per kind would have made "two years or five hundred launches" into two
 * half-filled rows that nothing compares, and the comparison is the entire point.
 */
enum LimitKind: string
{
    /** Months from installation, overhaul, or manufacture. */
    case CalendarMonths = 'calendar_months';

    /** A fixed date, where the paperwork names one rather than an interval. */
    case CalendarDate = 'calendar_date';

    case FlightHours = 'flight_hours';
    case Landings = 'landings';
    case EngineHours = 'engine_hours';
    case Starts = 'starts';
    case Cycles = 'cycles';

    public function label(): string
    {
        return __('fleet.limit.'.$this->value);
    }

    public function isCalendar(): bool
    {
        return in_array($this, [self::CalendarMonths, self::CalendarDate], strict: true);
    }

    /**
     * The aircraft counter this limit is measured against.
     *
     * Null for calendar limits, which need no counter -- the date arrives on its
     * own. For the rest, the aircraft has to be counting this, or the limit
     * cannot be evaluated at all: a launch limit on an aircraft that counts no
     * launches is a limit nobody can answer.
     */
    public function counter(): ?CounterKind
    {
        return match ($this) {
            self::CalendarMonths, self::CalendarDate => null,
            self::FlightHours => CounterKind::FlightHours,
            self::Landings => CounterKind::Landings,
            self::EngineHours => CounterKind::EngineHours,
            self::Starts => CounterKind::Starts,
            self::Cycles => CounterKind::Cycles,
        };
    }
}
