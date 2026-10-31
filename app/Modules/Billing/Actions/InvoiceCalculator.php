<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceLine;
use App\Modules\Billing\Support\Money;
use RuntimeException;

/**
 * Rechnet die Beträge einer Rechnung -- Zeile für Zeile, dann die Summen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * REIHENFOLGE: Listenpreis − Rabatt = Nettopreis je Einheit; × Menge = Netto
 * der Zeile; × Steuersatz = Steuer der Zeile. Dann der Gesamtrabatt auf die
 * Zwischensumme, und die Steuer wird im selben Verhältnis gekürzt. Steuer
 * entsteht nur, wenn die steuerliche Behandlung sie vorsieht; der auf der
 * Zeile gespeicherte Satz bleibt trotzdem stehen, damit ein Wechsel zurück
 * auf Regelbesteuerung nichts verliert.
 *
 * NUR ENTWÜRFE. Eine gestellte Rechnung wird nicht nachgerechnet -- ihre
 * Beträge sind Teil des Belegs.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class InvoiceCalculator
{
    public function recalculate(Invoice $invoice): Invoice
    {
        if ($invoice->isFrozen()) {
            throw new RuntimeException('An issued invoice is not recalculated; its amounts are part of the record.');
        }

        $taxesApply = $invoice->tax_treatment->taxesApply();
        $invoice->load('lines');

        $subtotal = '0.00';
        $taxSum = '0.00';

        foreach ($invoice->lines as $position => $line) {
            $this->recalculateLine($line, $taxesApply);
            $line->position = $position + 1;
            $line->save();

            $subtotal = Money::add($subtotal, (string) $line->net_amount);
            $taxSum = Money::add($taxSum, (string) $line->tax_amount);
        }

        $discount = Money::percent($subtotal, $invoice->discount_percent !== null ? (string) $invoice->discount_percent : null);
        $net = Money::sub($subtotal, $discount);
        $tax = $taxesApply
            ? Money::sub($taxSum, Money::percent($taxSum, $invoice->discount_percent !== null ? (string) $invoice->discount_percent : null))
            : '0.00';

        $invoice->net_total = $net;
        $invoice->tax_total = $tax;
        $invoice->gross_total = Money::add($net, $tax);
        $invoice->save();

        return $invoice;
    }

    private function recalculateLine(InvoiceLine $line, bool $taxesApply): void
    {
        $list = Money::of((string) $line->list_unit_price);
        $unit = Money::sub($list, Money::percent($list, $line->discount_percent !== null ? (string) $line->discount_percent : null));
        $net = Money::mul($unit, (string) $line->quantity);
        $rate = $taxesApply ? (string) ($line->tax_rate ?? PriceList::defaultTaxRate()) : '0';
        $tax = $taxesApply ? Money::percent($net, $rate) : '0.00';

        $line->unit_price = $unit;
        $line->net_amount = $net;
        $line->tax_amount = $tax;
        $line->gross_amount = Money::add($net, $tax);

        if ($line->tax_rate === null) {
            $line->tax_rate = PriceList::defaultTaxRate();
        }
    }
}
