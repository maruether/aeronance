<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Enums;

/**
 * What kind of part this is, in the sense of 145.A.42.
 *
 * This sits on the part TYPE, not on the stored item: which class something
 * belongs to is a master-data decision with regulatory weight -- it determines
 * what evidence the part needs -- while booking goods in is a routine act. That
 * separation is decision E5, and it is why the two are separate permissions.
 *
 * Not to be confused with LotState, which is the condition of an individual
 * batch. A standard part can be serviceable or scrapped just like a component.
 */
enum PartClassification: string
{
    /** Needs an EASA Form 1 or equivalent to be fitted. */
    case Component = 'component';

    /** Made to a recognised, publicly available specification -- nuts, bolts,
     *  rivets. No Form 1, but a certificate of conformity traceable to the
     *  specification, and only when the maintenance data calls for that part. */
    case StandardPart = 'standard_part';

    /** Oil, sealant, adhesive. Its own class with its own evidence: a statement
     *  of conformity plus the manufacturer's origin. Absent from the legacy
     *  system entirely. */
    case ConsumableMaterial = 'consumable_material';

    public function label(): string
    {
        return __('warehouse.classification.'.$this->value);
    }

    /**
     * Whether a Form 1 is the expected evidence for this class.
     *
     * A hint for the interface, not a rule: the decisive setting is
     * requiresFormOne on the part type, because there are components released
     * under 21.A.307(b) without one.
     */
    public function normallyNeedsFormOne(): bool
    {
        return $this === self::Component;
    }
}
