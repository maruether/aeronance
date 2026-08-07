<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Enums;

/**
 * What kind of life limit a part type carries.
 *
 * the distinction, and the reason a single "life-limited" flag would have
 * been wrong: it would have blocked the tow release along with the spark plugs,
 * and the tow release is exactly the case that makes the whole exercise
 * worthwhile.
 *
 * The limits themselves -- hours, landings, cycles -- are not kept here. They
 * begin on installation and belong to the fleet module. This says only what
 * KIND of limit applies, which is what decides whether a removed part has a way
 * back into stock at all.
 */
enum LifeLimitType: string
{
    case None = 'none';

    /** Used until an inspection says otherwise. */
    case OnCondition = 'on_condition';

    /** Time between overhaul -- sometimes for life. Overhauled and fitted again. */
    case Tbo = 'tbo';

    /** Time between replacement -- spark plugs, hoses. Replaced and discarded. */
    case Tbr = 'tbr';

    public function label(): string
    {
        return __('warehouse.life_limit.'.$this->value);
    }

    /**
     * Whether a part of this kind may go back into stock after removal.
     *
     * A part on a replacement interval is replaced, not recovered. Letting one
     * back onto the shelf invites it being fitted again, which is the one thing
     * the interval exists to prevent.
     */
    public function allowsReuseAfterRemoval(): bool
    {
        return $this !== self::Tbr;
    }
}
