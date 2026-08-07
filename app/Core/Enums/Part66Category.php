<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * The licence categories and subcategories of point 66.A.3 of Annex III
 * (Part-66) to Regulation (EU) No 1321/2014.
 *
 * A CLOSED LIST, which is why it is an enum: the regulation names these and only
 * these, and a licence that says something else is a licence somebody typed
 * wrongly. What the regulation leaves open -- the limitations endorsed on the
 * licence -- is deliberately NOT modelled here; see QualificationLimitation.
 *
 * ONE PERSON, SEVERAL CATEGORIES. The licence document has a column of ticked
 * boxes, not a single value: an L1 holder commonly also holds L2, and a B1.2
 * holder may hold B2 as well. That is why the qualification carries a list.
 *
 * B1.E is the newest entry and the one worth a note: it was added by Commission
 * Implementing Regulation (EU) 2025/111 for aeroplanes with electric power
 * plants and applies from 13 February 2026. Subpart L has no electric equivalent
 * -- see the comment on MaintenanceSubject::Electric.
 */
enum Part66Category: string
{
    // 66.A.3(a) -- category A, line maintenance.
    case A1 = 'A1';
    case A2 = 'A2';
    case A3 = 'A3';
    case A4 = 'A4';

    // 66.A.3(b) -- category B1.
    case B1_1 = 'B1.1';
    case B1_2 = 'B1.2';
    case B1_3 = 'B1.3';
    case B1_4 = 'B1.4';

    /** Electric-powered aeroplanes, added by (EU) 2025/111, applicable 13.02.2026. */
    case B1_E = 'B1.E';

    // 66.A.3(c) to (e) -- avionics and the light-aircraft categories.
    case B2 = 'B2';
    case B2L = 'B2L';
    case B3 = 'B3';

    // 66.A.3(f) -- subpart L, the one that matters for gliding.
    case L1C = 'L1C';
    case L1 = 'L1';
    case L2C = 'L2C';
    case L2 = 'L2';
    case L3H = 'L3H';
    case L3G = 'L3G';
    case L4H = 'L4H';
    case L4G = 'L4G';
    case L5 = 'L5';

    // 66.A.3(g) -- category C, base maintenance.
    case C = 'C';

    /**
     * The letter group, for grouping in a list rather than for any rule.
     *
     * Nothing decides anything on this: the privileges follow from the
     * subcategory, not from its first letter.
     */
    public function group(): string
    {
        return match (true) {
            str_starts_with($this->value, 'A') => 'a',
            str_starts_with($this->value, 'B') => 'b',
            str_starts_with($this->value, 'L') => 'l',
            default => 'c',
        };
    }

    /**
     * The translation key.
     *
     * Derived from the case NAME rather than the value, because a value like
     * "B1.1" would be read by the translator as nested array keys.
     */
    public function translationKey(): string
    {
        return mb_strtolower($this->name);
    }

    public function label(): string
    {
        return $this->value;
    }

    /** What the category covers, in words -- 66.A.3. */
    public function description(): string
    {
        return __('qualifications.category.'.$this->translationKey());
    }

    /** "L2 — Motorsegler und ELA1-Flugzeuge", for a select. */
    public function fullLabel(): string
    {
        return $this->value.' — '.$this->description();
    }

    /**
     * Categories that are relevant to sailplanes and light aircraft.
     *
     * Offered first in the interface. Not a rule and not a filter -- a club with
     * a B1.2 holder in it is normal, and the full list stays available.
     *
     * @return list<self>
     */
    public static function light(): array
    {
        return [self::L1C, self::L1, self::L2C, self::L2, self::B3, self::B2L];
    }
}
