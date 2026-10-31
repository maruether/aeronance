<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Actions\BuildInvoiceFromWorkOrder;
use App\Modules\Billing\Actions\CancelInvoice;
use App\Modules\Billing\Actions\IssueInvoice;
use App\Modules\Billing\Actions\RecordPayment;
use App\Modules\Billing\Enums\InvoiceState;
use App\Modules\Billing\Enums\LineOrigin;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PartPrice;
use App\Modules\Billing\Permissions;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Actions\CertifyTaskCard;
use App\Modules\TaskCards\Actions\IssuePartToCard;
use App\Modules\TaskCards\Actions\IssueRelease;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Vom freigegebenen Vorgang zur bezahlten Rechnung -- und die Stellen, an
 * denen das Modul Nein sagt.
 */
final class InvoiceLifecycleTest extends BillingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    #[Test]
    public function a_released_visit_becomes_a_draft_with_labour_rounded_up_to_the_quarter_hour(): void
    {
        $this->rates();
        $order = $this->releasedVisit(minutes: 50);

        $invoice = app(BuildInvoiceFromWorkOrder::class)->handle($order, $this->customer(), $this->clerk());

        $this->assertTrue($invoice->isDraft());
        $this->assertNull($invoice->number);
        $this->assertSame($order->number, $invoice->work_order_number);
        $this->assertSame('D-KABC', $invoice->aircraft_registration);

        $labour = $invoice->lines->firstWhere('origin', LineOrigin::Labour);
        $this->assertNotNull($labour);
        // 50 Minuten -> 60 Minuten -> 1,000 h zu 80 €.
        $this->assertSame('1.000', (string) $labour->quantity);
        $this->assertSame('80.00', (string) $labour->list_unit_price);
        $this->assertSame('80.00', (string) $labour->net_amount);
        $this->assertFalse($labour->needs_price);

        $this->assertSame('80.00', (string) $invoice->net_total);
        $this->assertSame('15.20', (string) $invoice->tax_total);
        $this->assertSame('95.20', (string) $invoice->gross_total);
    }

    #[Test]
    public function a_part_taken_from_stock_is_priced_by_its_part_number(): void
    {
        $this->rates();
        PartPrice::create(['part_number' => 'AN3-5A', 'net_price' => '1.50', 'valid_from' => now()->subMonth()->toDateString()]);

        $order = $this->openVisit();
        $card = $this->cardWithWork($order, 30);

        $bolt = PartType::create([
            'name' => 'Schraube AN3-5A',
            'classification' => PartClassification::StandardPart,
            'unit_of_measure' => 'Stk',
            'ipc_part_number' => 'AN3-5A',
        ]);
        app(ReceiveStock::class)->handle($bolt, 100, now()->toDateString());
        app(IssuePartToCard::class)->handle($card, $bolt, 4, $this->mechanic());

        app(CertifyTaskCard::class)->complete($card->fresh(), $this->mechanic(), 'Gemacht');
        app(CertifyTaskCard::class)->certify($card->fresh(), $this->inspector());
        app(IssueRelease::class)->handle($order->fresh(), $this->inspector());

        $invoice = app(BuildInvoiceFromWorkOrder::class)->handle($order->fresh(), $this->customer(), $this->clerk());

        $part = $invoice->lines->firstWhere('origin', LineOrigin::Part);
        $this->assertNotNull($part);
        $this->assertStringContainsString('AN3-5A', $part->description);
        $this->assertSame('4.000', (string) $part->quantity);
        $this->assertSame('1.50', (string) $part->list_unit_price);
        $this->assertSame('6.00', (string) $part->net_amount);
        $this->assertFalse($part->needs_price);
    }

    #[Test]
    public function a_missing_price_is_a_marked_line_that_blocks_issuing(): void
    {
        // Keine Stundensaetze angelegt.
        $order = $this->releasedVisit();
        $invoice = app(BuildInvoiceFromWorkOrder::class)->handle($order, $this->customer(), $this->clerk());

        $this->assertTrue($invoice->hasPriceGaps());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no price/');

        app(IssueInvoice::class)->handle($invoice, $this->clerk());
    }

    #[Test]
    public function an_open_visit_is_not_invoiced(): void
    {
        $this->rates();
        $order = $this->openVisit();
        $this->cardWithWork($order, 30);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/still open/');

        app(BuildInvoiceFromWorkOrder::class)->handle($order->fresh(), $this->customer(), $this->clerk());
    }

    #[Test]
    public function issuing_numbers_the_invoice_per_year_and_freezes_it(): void
    {
        $this->rates();
        $customer = $this->customer();
        $clerk = $this->clerk();

        $first = app(IssueInvoice::class)->handle(
            app(BuildInvoiceFromWorkOrder::class)->handle($this->releasedVisit(), $customer, $clerk),
            $clerk,
            '2026-10-30',
        );
        $second = app(IssueInvoice::class)->handle(
            app(BuildInvoiceFromWorkOrder::class)->handle($this->releasedVisit(aircraft: Aircraft::create(['registration' => 'D-1234', 'model' => 'LS4'])), $customer, $clerk),
            $clerk,
            '2026-11-02',
        );

        $this->assertSame('RE-202610-0001', $first->number);
        $this->assertSame('RE-202611-0002', $second->number);
        $this->assertSame(InvoiceState::Issued, $first->state);
        $this->assertSame('2026-11-13', $first->due_at->toDateString());
        $this->assertSame($clerk->name, $first->issued_by_name);

        // Eingefroren: weder Kopf noch Zeilen lassen sich aendern, loeschen auch nicht.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/frozen/');
        $first->update(['intro' => 'Nachträglich']);
    }

    #[Test]
    public function a_frozen_invoice_keeps_its_lines_and_refuses_deletion(): void
    {
        $this->rates();
        $invoice = $this->issued();

        try {
            $invoice->lines->first()->update(['description' => 'anders']);
            $this->fail('A line of an issued invoice was changed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot change', $e->getMessage());
        }

        try {
            $invoice->delete();
            $this->fail('An issued invoice was deleted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not deleted', $e->getMessage());
        }

        $this->assertNotNull(Invoice::query()->find($invoice->getKey()));
    }

    #[Test]
    public function the_number_sequence_resets_with_the_year_but_not_with_the_month(): void
    {
        $this->rates();
        $customer = $this->customer();
        $clerk = $this->clerk();

        $a = app(IssueInvoice::class)->handle(app(BuildInvoiceFromWorkOrder::class)->handle($this->releasedVisit(), $customer, $clerk), $clerk, '2026-12-31');
        $b = app(IssueInvoice::class)->handle(app(BuildInvoiceFromWorkOrder::class)->handle($this->releasedVisit(aircraft: Aircraft::create(['registration' => 'D-1234', 'model' => 'LS4'])), $customer, $clerk), $clerk, '2027-01-01');

        $this->assertSame('RE-202612-0001', $a->number);
        $this->assertSame('RE-202701-0001', $b->number);
    }

    #[Test]
    public function cancelling_writes_a_credit_note_that_mirrors_the_invoice(): void
    {
        $this->rates();
        $invoice = $this->issued();
        $clerk = $this->clerk();

        $creditNote = app(CancelInvoice::class)->handle($invoice, $clerk, 'Falscher Kunde');

        $this->assertSame('GS-'.now()->format('Ym').'-0001', $creditNote->number);
        $this->assertTrue($creditNote->isCreditNote());
        $this->assertSame($invoice->getKey(), $creditNote->corrects_invoice_id);
        $this->assertSame('-95.20', (string) $creditNote->gross_total);
        $this->assertSame('-1.000', (string) $creditNote->lines->first()->quantity);
        $this->assertStringContainsString('Falscher Kunde', (string) $creditNote->intro);

        $invoice->refresh();
        $this->assertSame(InvoiceState::Cancelled, $invoice->state);
        $this->assertSame('Falscher Kunde', $invoice->cancellation_reason);

        // Nur einmal.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already been cancelled/');
        app(CancelInvoice::class)->handle($invoice->fresh(), $clerk, 'Nochmal');
    }

    #[Test]
    public function payments_settle_the_invoice_and_refuse_to_overpay(): void
    {
        $this->rates();
        $invoice = $this->issued();
        $clerk = $this->clerk();

        app(RecordPayment::class)->handle($invoice, '50.00', '2026-11-01', $clerk, 'transfer');
        $invoice->refresh();
        $this->assertSame(InvoiceState::Issued, $invoice->state);
        $this->assertSame('45.20', $invoice->outstandingAmount());

        app(RecordPayment::class)->handle($invoice, '45.20', '2026-11-05', $clerk, 'transfer');
        $invoice->refresh();
        $this->assertSame(InvoiceState::Paid, $invoice->state);
        $this->assertSame('2026-11-05', $invoice->paid_at->toDateString());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/overpay/');
        app(RecordPayment::class)->handle($invoice->fresh(), '0.01', '2026-11-06', $clerk);
    }

    #[Test]
    public function issuing_needs_the_permission_not_just_the_screen(): void
    {
        $this->rates();
        $draft = app(BuildInvoiceFromWorkOrder::class)->handle($this->releasedVisit(), $this->customer(), $this->clerk());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(Permissions::ISSUE);

        app(IssueInvoice::class)->handle($draft, $this->userWith(Permissions::MANAGE));
    }

    #[Test]
    public function the_issuer_must_name_a_tax_id_when_vat_is_shown(): void
    {
        $this->rates();
        config()->set('aeronance.organisation.tax_number', '');
        config()->set('aeronance.organisation.vat_id', '');

        $draft = app(BuildInvoiceFromWorkOrder::class)->handle($this->releasedVisit(), $this->customer(), $this->clerk());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/tax number or VAT ID/');

        app(IssueInvoice::class)->handle($draft, $this->clerk());
    }

    private function issued(): Invoice
    {
        $clerk = $this->clerk();

        return app(IssueInvoice::class)->handle(
            app(BuildInvoiceFromWorkOrder::class)->handle($this->releasedVisit(), $this->customer(), $clerk),
            $clerk,
        );
    }
}
