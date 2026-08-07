<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Enums;

/**
 * What kind of component a type describes.
 *
 * The three the requirement was for, plus a catch-all. Winches are deliberately absent:
 * he asked whether the LBA's winch volume also lists rope winches installed IN an
 * aircraft, and it does not -- 07-2-winden.pdf is headed "Startgeräte / Launching
 * Devices" and lists ground winches only (Rhön, Tost-Doppeltrommelwinde, System
 * Dunkel). Ground equipment is not maintained under an aircraft's programme, so
 * there is nothing here for it to be.
 */
enum ComponentKind: string
{
    case Engine = 'engine';

    case Propeller = 'propeller';

    /** Tow release / Schleppkupplung -- the Tost couplings and their kin. */
    case TowRelease = 'tow_release';

    /** Instruments, undercarriages, anything else with its own paperwork. */
    case Other = 'other';

    public function label(): string
    {
        return __('fleet.component_type.kind.'.$this->value);
    }

    /**
     * Whether this kind usually carries its own running times.
     *
     * Advisory only -- it decides what the form suggests, never what it permits.
     * An engine and a tow release both do (the "2 Jahre oder 500 Starts");
     * a bracket does not, and pretending otherwise would put an empty limit on
     * every part somebody records.
     */
    public function usuallyHasLimits(): bool
    {
        return $this !== self::Other;
    }
}
