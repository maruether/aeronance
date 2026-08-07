<?php

declare(strict_types=1);

namespace App\Modules\Directives\Enums;

/**
 * What kind of document a line is, and therefore how binding it is.
 *
 * The distinction is not cosmetic: an LTA is mandatory, a manufacturer's TM or SB
 * generally is not until an authority adopts it. Both belong on the list -- the
 * point of the overview is to have looked at everything -- but only one of them
 * grounds an aircraft when it is not carried out.
 */
enum DirectiveKind: string
{
    /** Lufttüchtigkeitsanweisung -- mandatory. */
    case Lta = 'lta';

    /** Airworthiness Directive (EASA/FAA) -- mandatory, the same thing in English. */
    case Ad = 'ad';

    /** Technische Mitteilung -- the manufacturer's word. */
    case Tm = 'tm';

    /** Service Bulletin -- the same, in English. */
    case Sb = 'sb';

    public function label(): string
    {
        return __('directives.kind.'.$this->value);
    }

    /**
     * Whether skipping it grounds the aircraft.
     *
     * A TM left undone is a decision the operation may take and answer for; an
     * LTA left undone is not. Both still appear as open items -- see
     * OutstandingDirectives -- but only the mandatory ones block.
     */
    public function isMandatory(): bool
    {
        return match ($this) {
            self::Lta, self::Ad => true,
            self::Tm, self::Sb => false,
        };
    }
}
