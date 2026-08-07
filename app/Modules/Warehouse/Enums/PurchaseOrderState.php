<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Enums;

/**
 * Wo eine Bestellung steht.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * VIER ZUSTAENDE, UND `partially_received` IST DER WICHTIGE.
 *
 * Vorgabe: „erst wenn alles abgehakt ist ist die bestellung erledigt." Eine
 * Bestellung, die nur offen oder erledigt sein kann, zwingt bei der ersten
 * Teillieferung zu einer Luege -- entweder man haelt sie offen und vergisst,
 * was schon da ist, oder man schliesst sie und verliert den Rest.
 *
 * `cancelled` ist kein Fehlerzustand: „es kann vorkommen das material nicht
 * kommt". Eine stornierte Bestellung bleibt stehen, damit spaeter noch
 * nachvollziehbar ist, worauf jemand monatelang gewartet hat.
 * ─────────────────────────────────────────────────────────────────────────────
 */
enum PurchaseOrderState: string
{
    case Open = 'open';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    /**
     * Wartet diese Bestellung noch auf Ware?
     *
     * Die eine Frage, die „offene Bestellungen" und die Erinnerung stellen.
     */
    public function isOutstanding(): bool
    {
        return $this === self::Open || $this === self::PartiallyReceived;
    }

    public function label(): string
    {
        return __('warehouse.order.state.'.$this->value);
    }

    public function colour(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::PartiallyReceived => 'info',
            self::Received => 'success',
            self::Cancelled => 'gray',
        };
    }
}
