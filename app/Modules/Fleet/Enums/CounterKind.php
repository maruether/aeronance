<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Enums;

/**
 * The things that get counted.
 *
 * Two of these are not optional. Vorgabe: flight time and landings "muss
 * gesetzlich geregelt erfasst werden" -- every aircraft carries them, and an
 * installation that let somebody switch them off would be an installation that
 * lets somebody stop keeping a required record.
 *
 * The rest are per aircraft, and the reason is a detail that would have been
 * easy to get wrong: NOT EVERY AIRCRAFT WITH AN ENGINE HAS AN ENGINE COUNTER.
 * Deriving "has engine time" from "has an engine" would have invented readings
 * nobody takes.
 *
 * Starts and cycles are here even though no aircraft is required to carry them,
 * because a COMPONENT limit may be expressed in them -- a Tost release runs on
 * launches, a turbine on cycles. A component's usage is the aircraft's counter
 * between fitting and removal, so the aircraft has to be counting the thing its
 * components are limited by.
 */
enum CounterKind: string
{
    /** Flight time. Kept in hours with two decimals, as the logs do. */
    case FlightHours = 'flight_hours';

    case Landings = 'landings';

    /** Engine running time -- only where an aircraft actually has the counter. */
    case EngineHours = 'engine_hours';

    /** Launches. What a tow release is limited by. */
    case Starts = 'starts';

    /** Load or thermal cycles. Turbines, and anything else that counts them. */
    case Cycles = 'cycles';

    public function label(): string
    {
        return __('fleet.counter.'.$this->value);
    }

    public function unit(): string
    {
        return __('fleet.counter_unit.'.$this->value);
    }

    /**
     * Required by law to be kept, so every aircraft has it.
     */
    public function isMandatory(): bool
    {
        return in_array($this, [self::FlightHours, self::Landings], strict: true);
    }

    /** Whole numbers, or fractions? */
    public function isWhole(): bool
    {
        return $this !== self::FlightHours && $this !== self::EngineHours;
    }

    /** @return list<self> */
    public static function mandatory(): array
    {
        return array_values(array_filter(self::cases(), fn (self $k): bool => $k->isMandatory()));
    }

    /** @return list<self> */
    public static function optional(): array
    {
        return array_values(array_filter(self::cases(), fn (self $k): bool => ! $k->isMandatory()));
    }
}
