<?php

declare(strict_types=1);

namespace App\Modules\Inspection;

/**
 * Who may do what with an incoming inspection.
 *
 * Two acts, and the split matters: working through the checklist is craft, and
 * signing the result is a statement somebody answers for.
 */
final class Permissions
{
    /** See open and completed inspections. */
    public const INSPECTION_VIEW = 'inspection.view';

    /**
     * Fill in the checklist and sign the result.
     *
     * Accepting a delivery ALSO needs the warehouse's own release permission,
     * because acceptance lifts the quarantine -- and that is the warehouse's
     * rule, not this module's. See CompleteIncomingInspection.
     */
    public const INSPECTION_PERFORM = 'inspection.perform';
}
