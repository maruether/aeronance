<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Enums;

/**
 * What a running total is counted from.
 *
 * The distinction the brief flagged as missing: usually irrelevant for maintenance,
 * decisive for some paperwork -- and decisive for the limits, which is where it
 * bites first.
 *
 * A TBO is measured since the last overhaul; that is what the O stands for. A
 * hard life limit is measured since new: twelve thousand hours means the part is
 * finished at twelve thousand, whatever has been done to it in between. Reading
 * a TBO against TSN would condemn a freshly overhauled engine, and reading a
 * life limit against TSO would fly one for ever.
 */
enum UsageBasis: string
{
    /** TSN -- total since manufacture. Nothing resets it. */
    case SinceNew = 'since_new';

    /** TSO -- since the last overhaul. An overhaul sets it back to nil. */
    case SinceOverhaul = 'since_overhaul';

    public function label(): string
    {
        return __('fleet.basis.'.$this->value);
    }

    public function abbreviation(): string
    {
        return $this === self::SinceNew ? 'TSN' : 'TSO';
    }
}
