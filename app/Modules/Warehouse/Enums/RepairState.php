<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Enums;

/**
 * How far a repair has got.
 *
 * Deliberately short. This is not a repair workflow -- the warehouse only needs
 * to answer where the part is and whether it is coming back. What happens at the
 * shop is the shop's business, and would be the component repair module's if
 * that is ever built.
 */
enum RepairState: string
{
    /** Gone. Off the shelf, still the club's property. */
    case Dispatched = 'dispatched';

    /** Back, booked into a lot of its own. */
    case Returned = 'returned';

    /** Not coming back: beyond repair, lost, or not worth the invoice. */
    case WrittenOff = 'written_off';

    public function label(): string
    {
        return __('warehouse.repair.state.'.$this->value);
    }

    public function isOpen(): bool
    {
        return $this === self::Dispatched;
    }
}
