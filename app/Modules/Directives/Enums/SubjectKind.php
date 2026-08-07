<?php

declare(strict_types=1);

namespace App\Modules\Directives\Enums;

/**
 * What a directive is about.
 *
 * All three of the cases the brief named. Engine and propeller are technically the
 * component case -- they are a part with a serial number -- but they are kept
 * apart because manufacturers publish them as separate lists with their own
 * numbering, and a person looking for "the engine LTAs" wants exactly those.
 */
enum SubjectKind: string
{
    /** Applies to a type, so to every registration of it. */
    case AircraftModel = 'aircraft_model';

    /** Applies to a part, so to every aircraft carrying one. */
    case Component = 'component';

    case Engine = 'engine';

    case Propeller = 'propeller';

    public function label(): string
    {
        return __('directives.subject.'.$this->value);
    }

    /**
     * Whether the subject is identified by a serial number rather than a type.
     *
     * Decides which fields matter and how applicability is worked out: a type
     * directive hits every aircraft of that model, a serial one only those
     * carrying an affected part.
     */
    public function isSerialBased(): bool
    {
        return $this !== self::AircraftModel;
    }
}
