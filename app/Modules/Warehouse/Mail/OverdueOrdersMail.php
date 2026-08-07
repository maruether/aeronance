<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Mail;

use App\Core\Mail\Postman;
use App\Modules\Warehouse\Models\PurchaseOrder;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/**
 * „Diese Lieferungen sind überfällig."
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „Der Hintergrund ist das ich gerade erst mit einem Lieferanten auf
 * die nase gefallen bin der sich nicht gemeldet hatte. Das hätte mir fast einen
 * Termin gerissen."
 *
 * Diese Mail ist der eigentliche Zweck des ganzen Bestellteils. Alles andere --
 * Positionen, Mengen, Zustände — ist nur, woran sie hängt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * EINE MAIL FÜR ALLE ÜBERFÄLLIGEN, nicht eine je Bestellung. Wer drei Sachen
 * bestellt hat und drei Mails bekommt, liest die dritte nicht mehr — und
 * genau darauf kommt es an: dass sie gelesen wird.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class OverdueOrdersMail extends Mailable
{
    /** @param  Collection<int, PurchaseOrder>  $orders */
    public function __construct(public readonly Collection $orders) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans_choice('warehouse.order.mail.subject', $this->orders->count(), [
                'anzahl' => $this->orders->count(),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'warehouse.mail.overdue-orders',
            with: [
                'orders' => $this->orders,
                'organisation' => Postman::fromName(),

                /*
                 * Der Weg zur Liste. Ohne Verweis muesste der Empfaenger sich
                 * erst durch die Oberflaeche suchen -- bei einer Mail, die er
                 * bekommt, WEIL etwas liegen bleibt, ist das der falsche
                 * zusaetzliche Schritt.
                 */
                'url' => url('/verwaltung/bestellungen'),
            ],
        );
    }
}
