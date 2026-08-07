<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * What a piece of work touches -- and therefore what a licence limitation can
 * exclude.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ONE VOCABULARY FOR TWO SIDES. The licence says "ausgenommen Zellen in
 * Metallbauweise"; the aircraft says "Zelle aus Metall". Unless both say the
 * same word, the comparison is a string match somebody will get wrong. So this
 * enum is the word, the licence side uses it in QualificationLimitation, and the
 * fleet maps its own vocabulary onto it (AirframeConstruction, Propulsion).
 *
 * The brief named the areas: Zelle (Holz / Metall / FVK), Motor (Kolben / Elektro /
 * Jet), Avionik. They are not a list the regulation gives -- point 66.A.50
 * leaves the WORDING of a limitation open, because limitations come out of a
 * conversion report (66.A.70) or out of missing experience (66.A.45/66.A.50),
 * not out of a table. What is fixed is only what a club can actually record
 * about its aircraft, and that is this list.
 * ─────────────────────────────────────────────────────────────────────────────
 */
enum MaintenanceSubject: string
{
    // Airframe construction. A glider is routinely more than one of these: an
    // ASK 13 is a welded steel tube fuselage with wooden wings.
    case Wood = 'wood';
    case Metal = 'metal';
    case Composite = 'composite';

    // Propulsion.
    case Piston = 'piston';
    case Turbine = 'turbine';

    /**
     * Electric propulsion.
     *
     * NOT a Part-66 category of its own for sailplanes. The regulation caught up
     * with electric flight only for aeroplanes: (EU) 2025/111 added subcategory
     * B1.E and knowledge module 18, applicable from 13 February 2026. Subpart L,
     * which is what a gliding club holds, still distinguishes only sailplanes
     * from powered sailplanes and says nothing about how the propeller is
     * driven -- although front-electric-sustainer gliders are now common.
     *
     * So this value is a deliberate addition, not a regulatory category: a club
     * that wants to record "diese Person arbeitet nicht an Elektroantrieben" can
     * do so, and nobody should read it as an entry that appears on a licence.
     */
    case Electric = 'electric';

    /**
     * Avionics.
     *
     * Recorded and frozen into certificates, but NOT enforced -- see
     * WorkSubject. Whether a job touched avionics is a property of the job, and
     * this system does not record it: the ATA chapter is free text and gliding
     * often keeps none at all. Guessing from it would produce a refusal nobody
     * can explain, which is worse than an honest gap.
     */
    case Avionics = 'avionics';

    /** airframe | propulsion | avionics -- for grouping, not for rules. */
    public function area(): string
    {
        return match ($this) {
            self::Wood, self::Metal, self::Composite => 'airframe',
            self::Piston, self::Turbine, self::Electric => 'propulsion',
            self::Avionics => 'avionics',
        };
    }

    public function label(): string
    {
        return __('qualifications.subject.'.$this->value);
    }

    /** How the exclusion reads on the licence: "ausgenommen ...". */
    public function exclusionLabel(): string
    {
        return __('qualifications.exclusion.'.$this->value);
    }

    /** @return list<self> */
    public static function airframe(): array
    {
        return [self::Wood, self::Metal, self::Composite];
    }

    /** @return list<self> */
    public static function propulsion(): array
    {
        return [self::Piston, self::Turbine, self::Electric];
    }
}
