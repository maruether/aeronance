<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Enums;

/**
 * Why stock moved.
 *
 * Stock is the sum of its movements -- see decision E1. There is no quantity
 * field to overwrite, so a correction is a counter-booking rather than an edit,
 * and the history is the ledger itself rather than a separate log that may or
 * may not have been kept.
 */
enum MovementType: string
{
    /** Goods in. */
    case Receipt = 'receipt';

    /** Taken out -- fitted, consumed, handed over. */
    case Issue = 'issue';

    /** Stocktaking difference or a mistake put right. Always references the
     *  movement it corrects, so both remain visible. */
    case Correction = 'correction';

    /** Off to be repaired. Out of stock, still the club's property -- which is
     *  why it is not an Issue: an issue ends the part's life in the store, this
     *  is a journey it is expected to come back from. */
    case RepairDispatch = 'repair_dispatch';

    /** Back from repair, into a lot of its own carrying the repairer's
     *  certificate. */
    case RepairReturn = 'repair_return';

    /** Scrapped: written off while still on the shelf. */
    case Scrap = 'scrap';

    /** Disposed of: physically gone. */
    case Disposal = 'disposal';

    public function label(): string
    {
        return __('warehouse.movement_type.'.$this->value);
    }

    /** Does this type add to stock or take away? */
    public function isInbound(): bool
    {
        return in_array($this, [self::Receipt, self::RepairReturn], strict: true);
    }

    /** Is the part still the club's, merely elsewhere? */
    public function isTemporaryAbsence(): bool
    {
        return $this === self::RepairDispatch;
    }
}
