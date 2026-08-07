<?php

declare(strict_types=1);

namespace App\Modules\Inspection\Enums;

use App\Modules\Warehouse\Enums\PartClassification;

/**
 * The checklist an arriving delivery is worked through.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * IT IS FIXED IN CODE, AND THAT IS A DECISION, NOT LAZINESS.
 *
 * A checklist a club can edit is a checklist a club can shorten, and the item
 * that gets deleted first is always the certificate -- it is the one that costs
 * time when the paperwork is missing. The whole point of an incoming inspection
 * is that the awkward question gets asked before the part is in the rack, not
 * after it is on the aircraft. What a club may do is mark an item as not
 * applicable WITH A REASON, which leaves a trace; deleting it leaves none.
 *
 * The list follows what 145.A.42 and its AMC actually ask at goods-in: is this
 * the right part, is it certified by somebody entitled to certify it, does the
 * paperwork match the thing in the box, and did it survive the journey.
 * ─────────────────────────────────────────────────────────────────────────────
 */
enum CheckItem: string
{
    /**
     * Right part at all.
     *
     * First, because everything after it is wasted effort if the box holds
     * something else -- and because a part number that differs in one character
     * is the classic way a wrong component gets into a rack.
     */
    case PartNumber = 'part_number';

    /** Quantity against delivery note and, if there is one, the order. */
    case Quantity = 'quantity';

    /**
     * Certificate present, complete, signed.
     *
     * EASA Form 1, FAA 8130-3, a conformity declaration for standard parts --
     * which document is the right one depends on what arrived, so the check is
     * "the right one for this, and complete", not "a Form 1".
     */
    case Certificate = 'certificate';

    /**
     * Issued by somebody entitled to issue it.
     *
     * A Form 1 without a valid approval number is a piece of paper. This is the
     * check that is skipped most often and matters most: the certificate is only
     * worth what the issuer's approval is worth.
     */
    case Issuer = 'issuer';

    /**
     * Serial or batch number ON THE PART matches the certificate.
     *
     * The link between paper and metal. Without it the traceability chain has a
     * gap right at its start -- and every later record inherits the gap.
     */
    case Identification = 'identification';

    /** Transport damage, packaging, preservation, ESD. */
    case Condition = 'condition';

    /**
     * Enough shelf life left to be worth taking in.
     *
     * A sealant with three weeks to run is not a bargain. Only asked where the
     * part type actually has a limit.
     */
    case ShelfLife = 'shelf_life';

    public function label(): string
    {
        return __('inspection.check.'.$this->value.'.label');
    }

    public function hint(): string
    {
        return __('inspection.check.'.$this->value.'.hint');
    }

    /**
     * Which items are asked for THIS delivery.
     *
     * Asking about a certificate for a bag of rivets trains people to tick
     * "entfällt" without reading -- and once that habit is there, it is applied
     * to the items that do matter. So the list is trimmed to what can genuinely
     * be answered.
     *
     * @return list<self>
     */
    public static function forDelivery(
        PartClassification $classification,
        bool $certificateRequired,
        bool $hasShelfLife,
        bool $serialTracked,
    ): array {
        $items = [self::PartNumber, self::Quantity];

        /*
         * The certificate pair is asked whenever a document is expected. For a
         * standard part that is the conformity declaration, not a Form 1 --
         * which is why the item is called "certificate" and not "form one".
         */
        if ($certificateRequired || $classification->normallyNeedsFormOne()) {
            $items[] = self::Certificate;
            $items[] = self::Issuer;
        }

        /*
         * Matching paper against metal only means something where the metal
         * carries a number: a serial, or a batch on the lot. Loose standard
         * parts carry neither.
         */
        if ($serialTracked || $classification !== PartClassification::StandardPart) {
            $items[] = self::Identification;
        }

        $items[] = self::Condition;

        if ($hasShelfLife) {
            $items[] = self::ShelfLife;
        }

        return $items;
    }
}
