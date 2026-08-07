<?php

declare(strict_types=1);

namespace App\Modules\Inspection\Enums;

/**
 * Where an incoming inspection stands.
 *
 *   open  --->  accepted
 *         --->  rejected
 *
 * There is no way back out of either result, and that is the point: an
 * inspection is a dated statement about goods somebody made at the counter. If
 * the assessment turns out to be wrong, the answer is a new record that says so
 * -- never a quiet edit of the old one. Same rule as a release to service.
 */
enum InspectionState: string
{
    /** The goods are here, the checklist is not done. */
    case Open = 'open';

    /** Checked and taken into stock. */
    case Accepted = 'accepted';

    /**
     * Checked and NOT taken into stock.
     *
     * What physically happens to the goods -- back to the supplier, held for a
     * decision, scrapped -- is a separate act in the warehouse. This says only
     * that they were not accepted, and why.
     */
    case Rejected = 'rejected';

    public function label(): string
    {
        return __('inspection.state.'.$this->value);
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Accepted => 'success',
            self::Rejected => 'danger',
        };
    }
}
