<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Actions\InvoiceCalculator;
use App\Modules\Billing\Enums\TaxTreatment;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\Money;
use PHPUnit\Framework\Attributes\Test;

/**
 * Die Rechenregeln -- Rabatt, Gesamtrabatt, Steuer, Kleinunternehmer.
 */
final class InvoiceMathTest extends BillingTestCase
{
    #[Test]
    public function money_rounds_half_up_and_never_uses_float_arithmetic(): void
    {
        $this->assertSame('0.30', Money::add('0.10', '0.20'));
        $this->assertSame('2.00', Money::round('1.995'));
        $this->assertSame('1.99', Money::round('1.994'));
        $this->assertSame('-2.00', Money::round('-1.995'));
        $this->assertSame('12.35', Money::percent('64.98', '19'));
        $this->assertSame('1.234,50', Money::format('1234.5'));
        $this->assertSame('3.75', Money::mul('1.50', '2.5'));
    }

    #[Test]
    public function a_line_discount_lowers_the_unit_price_and_the_tax_follows(): void
    {
        $invoice = $this->draft(['tax_treatment' => TaxTreatment::Standard]);
        $invoice->lines()->create([
            'description' => 'Teil', 'quantity' => 2, 'list_unit_price' => '100.00',
            'discount_percent' => '10', 'tax_rate' => '19',
        ]);

        app(InvoiceCalculator::class)->recalculate($invoice->fresh());
        $line = $invoice->fresh('lines')->lines->first();

        $this->assertSame('90.00', (string) $line->unit_price);
        $this->assertSame('180.00', (string) $line->net_amount);
        $this->assertSame('34.20', (string) $line->tax_amount);
        $this->assertSame('214.20', (string) $line->gross_amount);
    }

    #[Test]
    public function an_invoice_discount_cuts_net_and_tax_in_the_same_proportion(): void
    {
        $invoice = $this->draft(['tax_treatment' => TaxTreatment::Standard, 'discount_percent' => '5']);
        $invoice->lines()->create(['description' => 'A', 'quantity' => 1, 'list_unit_price' => '100.00', 'tax_rate' => '19']);
        $invoice->lines()->create(['description' => 'B', 'quantity' => 1, 'list_unit_price' => '100.00', 'tax_rate' => '7']);

        $invoice = app(InvoiceCalculator::class)->recalculate($invoice->fresh());

        $this->assertSame('200.00', $invoice->subtotal());
        $this->assertSame('10.00', $invoice->discountAmount());
        $this->assertSame('190.00', (string) $invoice->net_total);
        // (19 + 7) * 0.95
        $this->assertSame('24.70', (string) $invoice->tax_total);
        $this->assertSame('214.70', (string) $invoice->gross_total);
    }

    #[Test]
    public function a_small_business_shows_no_tax_but_keeps_the_rate_on_the_line(): void
    {
        $invoice = $this->draft(['tax_treatment' => TaxTreatment::SmallBusiness]);
        $invoice->lines()->create(['description' => 'A', 'quantity' => 1, 'list_unit_price' => '100.00', 'tax_rate' => '19']);

        $invoice = app(InvoiceCalculator::class)->recalculate($invoice->fresh());

        $this->assertSame('0.00', (string) $invoice->tax_total);
        $this->assertSame('100.00', (string) $invoice->gross_total);
        $this->assertSame('19.00', (string) $invoice->lines->first()->tax_rate);
        $this->assertSame('Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.', $invoice->tax_treatment->notice());
    }

    #[Test]
    public function hidden_and_visible_discounts_are_the_same_money(): void
    {
        $invoice = $this->draft();
        $invoice->lines()->create(['description' => 'sichtbar', 'quantity' => 1, 'list_unit_price' => '50.00', 'discount_percent' => '20', 'discount_visible' => true, 'tax_rate' => '19']);
        $invoice->lines()->create(['description' => 'verborgen', 'quantity' => 1, 'list_unit_price' => '50.00', 'discount_percent' => '20', 'discount_visible' => false, 'tax_rate' => '19']);

        $invoice = app(InvoiceCalculator::class)->recalculate($invoice->fresh());

        [$visible, $hidden] = $invoice->lines->all();
        $this->assertSame((string) $visible->unit_price, (string) $hidden->unit_price);
        $this->assertSame('40.00', (string) $hidden->unit_price);
        // Der Listenpreis bleibt auch auf der verborgenen Zeile gespeichert.
        $this->assertSame('50.00', (string) $hidden->list_unit_price);
        $this->assertTrue($hidden->hasDiscount());
    }

    /** @param  array<string, mixed>  $attributes */
    private function draft(array $attributes = []): Invoice
    {
        $customer = $this->customer();

        return Invoice::create([
            'customer_id' => $customer->getKey(),
            'customer_name' => $customer->name,
            'customer_address' => $customer->address,
            ...$attributes,
        ]);
    }
}
