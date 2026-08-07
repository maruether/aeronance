<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Enums;

/**
 * Who signed off external work.
 *
 * The brief leaves this open on purpose -- "Es ist dabei offen ob ich selbst freigebe
 * oder die fremdwerft" -- because both happen, and they are not the same
 * position.
 *
 * If the shop signs, the authority is theirs and their approval number is what
 * stands behind it. If we sign, somebody here has ACCEPTED work they did not
 * perform, on the strength of a report, under their own licence. That is a
 * determination, and it is the one an auditor will ask about first.
 */
enum ReleasedBy: string
{
    /** The shop released its own work. */
    case External = 'external';

    /** We released it, having accepted their report. */
    case Internal = 'internal';

    public function label(): string
    {
        return __('fleet.external.released_by.'.$this->value);
    }

    /**
     * Whether signing this way is an act somebody here answers for.
     *
     * Only the internal case. Recording that a shop signed is bookkeeping;
     * signing yourself is a judgement about work you did not watch.
     */
    public function requiresOurQualification(): bool
    {
        return $this === self::Internal;
    }
}
