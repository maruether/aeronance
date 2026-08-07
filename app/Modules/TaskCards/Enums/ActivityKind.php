<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Enums;

/**
 * What kind of work a card is.
 *
 * One of the Part-66 fields, and the one that makes an experience logbook worth
 * reading: "300 hours of maintenance" says much less than the split between
 * inspection, repair and modification, which is what a licence assessment
 * actually looks at.
 */
enum ActivityKind: string
{
    case Inspection = 'inspection';
    case Maintenance = 'maintenance';
    case Repair = 'repair';
    case Modification = 'modification';

    /** Carrying out an airworthiness directive. */
    case AdCompliance = 'ad_compliance';

    case Other = 'other';

    public function label(): string
    {
        return __('taskcards.activity.'.$this->value);
    }
}
