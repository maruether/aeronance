<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Enums;

/**
 * The papers an aircraft carries.
 *
 * A fixed list rather than free text, because these feed the due list and a
 * typo'd category is a category nobody ever filters on again. "Other" exists so
 * the list does not have to be complete to be usable.
 */
enum DocumentType: string
{
    /** Instandhaltungsprogramm -- attached, not filled in. */
    case Amp = 'amp';

    /** The weighing report as a filed document, where the calculation lives
     *  elsewhere or the report predates this system. */
    case WeighingReport = 'weighing_report';

    case NoiseCertificate = 'noise';
    case RadioLicence = 'radio';
    case Insurance = 'insurance';
    case Registration = 'registration';
    case FlightManual = 'flight_manual';
    case Other = 'other';

    public function label(): string
    {
        return __('fleet.document.type.'.$this->value);
    }

    /**
     * Whether this kind normally runs out.
     *
     * Only a hint for the form -- it prefills nothing and refuses nothing.
     * the rule stands: whether a given aircraft owes a given paper on an
     * interval is a "kommt drauf an", and the answer belongs to whoever is
     * entering it, not to a table of assumptions.
     */
    public function usuallyExpires(): bool
    {
        return in_array($this, [
            self::NoiseCertificate,
            self::RadioLicence,
            self::Insurance,
        ], strict: true);
    }
}
