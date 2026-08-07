<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Enums;

use App\Core\Enums\MaintenanceSubject;

/**
 * How an aircraft is driven, if it is.
 *
 * ONE VALUE, not a list -- unlike the airframe. A club aircraft has one kind of
 * power plant or none; the mixed case (a piston engine and a jet sustainer)
 * exists in aviation but not in a gliding club, and pretending otherwise would
 * make the commonest field in the form harder to fill in for no one's benefit.
 *
 * "Unpowered" is a real answer and not the absence of one. The difference
 * matters to a licence limitation: a glider with no engine cannot fall foul of
 * "ausgenommen Kolbentriebwerke", while an aircraft whose propulsion nobody
 * recorded might.
 *
 * ELECTRIC IS HERE BUT NOT IN PART-66 FOR SAILPLANES. (EU) 2025/111 added
 * subcategory B1.E for electric AEROPLANES from 13 February 2026; Subpart L
 * still knows only "Segelflugzeug" and "Motorsegler". Front-electric-sustainer
 * gliders are meanwhile everywhere, so the field records what the club has.
 */
enum Propulsion: string
{
    case Unpowered = 'unpowered';
    case Piston = 'piston';
    case Turbine = 'turbine';
    case Electric = 'electric';

    /** What a licence limitation would call it -- nothing, for a pure glider. */
    public function subject(): ?MaintenanceSubject
    {
        return match ($this) {
            self::Unpowered => null,
            self::Piston => MaintenanceSubject::Piston,
            self::Turbine => MaintenanceSubject::Turbine,
            self::Electric => MaintenanceSubject::Electric,
        };
    }

    public function label(): string
    {
        return __('fleet.propulsion.'.$this->value);
    }
}
