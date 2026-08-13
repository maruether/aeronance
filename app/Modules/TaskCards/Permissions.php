<?php

declare(strict_types=1);

namespace App\Modules\TaskCards;

/**
 * What one may do with work orders and cards.
 *
 * The split that matters is between COMPLETE and CERTIFY, and it is the:
 * "wer die arbeit gemacht hat, meldet sie fertig. ein Qualifizierter zeichnet
 * sie danach ab." Two verbs, because they are two acts by potentially two
 * people -- and because a mechanic without a licence must be able to finish his
 * own card without either being locked out or being able to sign it off.
 */
final class Permissions
{
    public const WORK_ORDERS_VIEW = 'workorders.view';

    /** Open and close a visit. */
    public const WORK_ORDERS_MANAGE = 'workorders.manage';

    /** Write cards and record hours. */
    public const CARDS_WORK = 'workorders.cards.work';

    /**
     * Sign a card off.
     *
     * Qualification-bound -- see Authority. Saying that work was done properly
     * is a judgement, which is the whole reason it is a second signature and
     * not the same one.
     */
    public const CARDS_CERTIFY = 'workorders.cards.certify';

    /**
     * Eine kritische Arbeit unabhängig kontrollieren.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * AUSDRÜCKLICH NICHT AN EINE LIZENZ GEBUNDEN, und das ist die schwierigste
     * Entscheidung an diesem Modul.
     *
     * Verlangte die Kontrolle eine Part-66-Lizenz, wäre sie in genau den
     * Vereinen unmöglich, in denen es einen einzigen Lizenzinhaber gibt — und
     * der ist meistens derjenige, der die Arbeit gemacht hat. Die Kontrolle
     * fiele dann nicht strenger aus, sondern aus. Eine übersprungene Kontrolle
     * schützt niemanden.
     *
     * Was zählt, ist das zweite Augenpaar: jemand, der die Arbeit NICHT gemacht
     * hat und weiß, worauf er sieht. Wer eine Lizenz hat, dessen Nummer wird
     * mitgeschrieben — verlangt wird sie nicht.
     */
    public const CARDS_INSPECT = 'workorders.cards.inspect';

    public const FINDINGS_RECORD = 'workorders.findings.record';

    /**
     * Report a finding from outside the workshop.
     *
     * The P/O tier -- Vorgabe: "Ein Befundbericht sollte durch jeden P/O oder
     * höher angelegt werden können." Its own permission and not a broader
     * FINDINGS_RECORD, because the two flows differ in what they may say:
     * a report only OBSERVES (always blocking, never on a card), while the
     * workshop path also places a finding on the visit it surfaced in. A club
     * hands this to whichever roles cover its pilot-owners and up.
     */
    public const FINDINGS_REPORT = 'workorders.findings.report';

    /**
     * Defer a finding.
     *
     * Its own verb, and the one with teeth: deciding that a crack holds until
     * the next inspection is a determination somebody answers for. Noticing
     * something and deciding to live with it are different acts.
     */
    public const FINDINGS_DEFER = 'workorders.findings.defer';
}
