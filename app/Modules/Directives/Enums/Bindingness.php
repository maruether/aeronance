<?php

declare(strict_types=1);

namespace App\Modules\Directives\Enums;

/**
 * How binding a line is -- kept apart from WHAT KIND of document it is.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: "beachte bitte auch den status der tm: optional, mandatory, SB ... nur
 * optional darf den status nicht durchgeführt erhalten."
 *
 * The two are genuinely independent, which is why the first version was wrong to
 * derive one from the other: a manufacturer's TM is a recommendation until an
 * authority adopts it, and then the SAME document becomes mandatory without
 * changing its number or its kind. Deriving bindingness from the kind meant an
 * adopted TM could never be marked mandatory.
 *
 * The consequence is the important part: a mandatory line can never be marked
 * "not carried out". You cannot declare that you are skipping a mandatory
 * directive -- there is no such declaration to make. Either it is done, or it is
 * not applicable, or the aircraft does not fly.
 *
 * THREE CASES, NOT TWO. Diamond's catalogue made the gap visible: beside 295
 * mandatory and 333 optional bulletins sit 85 the manufacturer marks RECOMMENDED
 * -- and Vorgabe: "Empfohlen bedeutet Optional, aber der hersteller empfielt es.
 * Das ist eine eigene Kategorie."
 *
 * Both halves matter. Legally it behaves like optional: the operation may decide
 * against it and answer for that. But folding it INTO optional would throw away
 * the manufacturer's own advice, and a club deciding what to do with its winter
 * is entitled to see the difference between "you may" and "we advise you to".
 * ─────────────────────────────────────────────────────────────────────────────
 */
enum Bindingness: string
{
    /** Must be complied with. No opting out exists. */
    case Mandatory = 'mandatory';

    /**
     * The manufacturer advises it. Declining is allowed, and is a decision.
     *
     * Its own case rather than a flavour of Optional, because the difference is
     * information the club is entitled to: "you may skip this" and "we advise
     * you to do this, and you may skip it" are not the same sentence.
     */
    case Recommended = 'recommended';

    /** Free choice. Nobody is pressing for it. */
    case Optional = 'optional';

    public function label(): string
    {
        return __('directives.bindingness.'.$this->value);
    }

    /**
     * Whether "not carried out" may be recorded.
     *
     * Everything that is not mandatory -- see the class comment. Written as a
     * negation on purpose: a fourth case added later is far more likely to be
     * declinable than to be binding, and a list of allowed cases would silently
     * treat it as binding while a list of forbidden ones asks the question.
     *
     * A mandatory directive left undone stays unassessed or incomplete, which is
     * a red flag, not a decision somebody may sign.
     */
    public function permitsRefusal(): bool
    {
        return $this !== self::Mandatory;
    }

    public function color(): string
    {
        return match ($this) {
            self::Mandatory => 'danger',

            // Its own colour, or the category would be invisible in the very
            // list it exists for.
            self::Recommended => 'warning',
            self::Optional => 'gray',
        };
    }
}
