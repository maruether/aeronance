<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Actions;

use App\Modules\Warehouse\Enums\PurchaseOrderState;
use App\Modules\Warehouse\Models\PurchaseOrder;
use InvalidArgumentException;

/**
 * Eine Bestellung stornieren.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „es kann vorkommen das material nicht kommt, somit müssen
 * bestellungen stornierbar sein."
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * STORNIEREN LOESCHT NICHTS, WEDER DIE BESTELLUNG NOCH BEREITS GELIEFERTE WARE.
 *
 * Eine teilweise gelieferte Bestellung darf storniert werden -- das ist sogar
 * der haeufigste Fall: Die Haelfte kam, der Rest kommt nie. Was schon
 * eingebucht wurde, BLEIBT eingebucht; es liegt ja im Regal. Die Stornierung
 * sagt nur, dass auf den Rest niemand mehr wartet.
 *
 * Der Grund ist Pflicht. Eine stornierte Bestellung ohne Begruendung ist in
 * einem halben Jahr eine offene Frage: Kam nichts, kam etwas Falsches, hat
 * jemand doppelt bestellt? Wer den Vorgang spaeter liest, braucht genau das.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class CancelPurchaseOrder
{
    public function handle(PurchaseOrder $order, string $reason): PurchaseOrder
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(__('warehouse.order.refused.no_reason'));
        }

        if ($order->state === PurchaseOrderState::Cancelled) {
            throw new InvalidArgumentException(__('warehouse.order.refused.already_cancelled'));
        }

        /*
         * Eine vollstaendig gelieferte Bestellung zu stornieren ergibt keinen
         * Sinn -- es gibt nichts, worauf noch jemand wartet. Wer die Ware
         * zurueckschickt, bucht sie aus; das ist eine Lagerbewegung und keine
         * Aenderung an der Bestellung.
         */
        if ($order->state === PurchaseOrderState::Received) {
            throw new InvalidArgumentException(__('warehouse.order.refused.already_received'));
        }

        $order->state = PurchaseOrderState::Cancelled;
        $order->cancelled_at = now()->toDateString();
        $order->cancel_reason = trim($reason);
        $order->save();

        return $order;
    }
}
