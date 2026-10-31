<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

/**
 * Rechnung oder Gutschrift.
 *
 * Eine Gutschrift ist hier eine Rechnung mit negativen Beträgen und eigenem
 * Nummernkreis, die auf das Original zeigt (`corrects_invoice_id`). Die
 * Stornorechnung ist der Sonderfall „alles zurück"; beide heißen auf dem
 * Papier Gutschrift, wie es das UStG seit 2013 für die Rechnungskorrektur
 * durch den Aussteller vorsieht.
 */
enum InvoiceKind: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';

    public function label(): string
    {
        return __('billing.kind.'.$this->value);
    }

    /** Welche Einstellung das Präfix des Nummernkreises liefert. */
    public function prefix(): string
    {
        return (string) config(match ($this) {
            self::Invoice => 'aeronance.billing.number_prefix_invoice',
            self::CreditNote => 'aeronance.billing.number_prefix_credit_note',
        }, $this === self::Invoice ? 'RE' : 'GS');
    }

    /** EN 16931 BT-3: 380 Rechnung, 381 Gutschrift. */
    public function typeCode(): string
    {
        return match ($this) {
            self::Invoice => '380',
            self::CreditNote => '381',
        };
    }
}
