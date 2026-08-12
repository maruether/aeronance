<?php

declare(strict_types=1);

namespace App\Modules\Warehouse;

/**
 * What one may do in the warehouse.
 *
 * The legacy system had four undifferentiated "warehouse" rights that were
 * always checked together -- the intent was clearly finer granularity, it just
 * never arrived. These are the verbs that intent was reaching for.
 *
 * Two of them need more than a permission. Determining that a part is
 * unserviceable, or scrapping it, takes it out of service on the strength of
 * someone's judgement, and that is reserved for qualified staff -- see
 * Authority and decision E8. The permission says the function may be operated;
 * the qualification says the person may answer for it.
 */
final class Permissions
{
    public const STOCK_VIEW = 'stock.view';

    public const STOCK_RECEIVE = 'stock.receive';

    public const STOCK_ISSUE = 'stock.issue';

    /** Precautionary: pull something out of circulation, reversible, no
     *  qualification needed -- missing paperwork is reason enough. */
    public const STOCK_QUARANTINE = 'stock.quarantine';

    /** The determination itself: unserviceable, or fit for service again.
     *  Qualified act, frozen into the record. */
    public const STOCK_QUARANTINE_CERTIFY = 'stock.quarantine.certify';

    /**
     * Releasing an ARRIVAL from its precautionary hold -- permission only,
     * no licence.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Split off from certify after the field test: the incoming chain demanded
     * a Part-66 licence for accepting goods at the door, and the verdict was
     * "sollte jeder mit berechtigung duerfen". Regulatorisch stimmt das:
     * Die Eingangspruefung (145.A.42) ist Aufgabe kompetenten Lagerpersonals
     * nach Verfahren des Betriebs -- keine Freigabe am Luftfahrzeug. Was
     * qualifiziert BLEIBT: unbrauchbar erklaeren, der Weg zurueck aus
     * unbrauchbar, ausmustern. Die urteilen ueber Zustand; hier wird Papier
     * angenommen.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public const STOCK_QUARANTINE_RELEASE = 'stock.quarantine.release';

    /** Unsalvageable and disposal. One-way, qualified act. */
    public const STOCK_SCRAP = 'stock.scrap';

    /** Move a lot to another compartment. Its own verb because it is not
     *  harmless: putting something in the quarantine store sets it aside, and
     *  taking it out again would otherwise be a release nobody made. */
    public const STOCK_TRANSFER = 'stock.transfer';

    /** Put a booking right with a counter-booking. Consequential enough to be
     *  its own verb: it changes stock figures after the fact, and the original
     *  entry stays visible beside it. */
    public const STOCK_CORRECT = 'stock.correct';

    /** Send a part away to be repaired, and book back what returns.
     *  Separate from issuing: it takes stock out that issuing may not touch --
     *  quarantined and unserviceable parts are the normal case here. */
    public const STOCK_REPAIR = 'stock.repair';

    public const STOCK_REPORT = 'stock.report';

    public const PART_TYPES_MANAGE = 'parts.types.manage';

    public const LOCATIONS_MANAGE = 'storage.locations.manage';

    /**
     * Bestellungen anlegen, einbuchen, stornieren.
     *
     * Eigenes Recht und nicht an STOCK_RECEIVE gehaengt: Wer Ware annimmt,
     * muss nicht bestellen duerfen -- und wer bestellt, steht nicht
     * zwangslaeufig am Wareneingang.
     */
    public const ORDERS_MANAGE = 'stock.orders.manage';

    public const SUPPLIERS_MANAGE = 'suppliers.manage';
}
