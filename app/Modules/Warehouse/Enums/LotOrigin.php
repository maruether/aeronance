<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Enums;

/**
 * Where a lot came from.
 *
 * The distinction decides what evidence backs the part, and therefore where it
 * may go afterwards.
 */
enum LotOrigin: string
{
    /** Bought. Backed by a Form 1, a certificate of conformity, or nothing. */
    case Supplier = 'supplier';

    /**
     * Taken out of an aircraft.
     *
     * Backed by a determination that it was serviceable when removed. Unless
     * somebody with a component rating issues a Form 1 for it -- which most
     * clubs cannot -- that evidence only carries it back into the aircraft it
     * came from. See docs/AUSGEBAUTE-TEILE.md.
     */
    case Removal = 'removal';

    /**
     * Came back from a repair.
     *
     * Whether it travels freely afterwards depends entirely on what came back
     * with it. A Form 1 from the repairing organisation discharges the
     * restriction the part carried before -- that is the point of sending it
     * away. Without one, nothing has changed and the restriction stands.
     */
    case Repair = 'repair';

    public function label(): string
    {
        return __('warehouse.origin.'.$this->value);
    }

    /**
     * Can a lot of this origin be tied to one aircraft?
     *
     * Bought stock never is. Anything that has been in an aircraft may be,
     * depending on the paperwork that came with it -- see
     * StockLot::mayBeFittedTo().
     */
    public function mayCarryAircraftRestriction(): bool
    {
        return in_array($this, [self::Removal, self::Repair], strict: true);
    }
}
