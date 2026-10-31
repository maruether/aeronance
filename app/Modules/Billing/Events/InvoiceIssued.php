<?php

declare(strict_types=1);

namespace App\Modules\Billing\Events;

/**
 * Eine Rechnung wurde gestellt.
 *
 * Nur Werte, keine Modelle: Wer zuhört, soll nichts von diesem Modul
 * importieren müssen.
 */
final readonly class InvoiceIssued
{
    public function __construct(
        public int $invoiceId,
        public string $number,
        public string $customerName,
        public string $grossTotal,
    ) {}
}
