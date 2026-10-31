<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Core\Modules\ModuleManager;
use App\Modules\Billing\Actions\BuildInvoiceFromWorkOrder;
use App\Modules\Billing\Actions\IssueInvoice;
use App\Modules\Billing\Documents\InvoiceDocuments;
use App\Modules\Billing\Documents\XRechnungWriter;
use App\Modules\Billing\Models\Customer;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Permissions;
use App\Modules\Fleet\Models\Aircraft;
use DOMDocument;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * Das Blatt, das PDF, die XRechnung -- und wer sie sehen darf.
 */
final class InvoiceDocumentsTest extends BillingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->rates();
    }

    #[Test]
    public function the_sheet_shows_the_mandatory_particulars_and_refuses_strangers(): void
    {
        $invoice = $this->issued();

        $this->actingAs($this->userWith(Permissions::VIEW))
            ->get(route('billing.invoice', $invoice))
            ->assertSuccessful()
            ->assertSee('Werft Musterhausen GmbH')
            ->assertSee('Flugplatzstraße 1')
            ->assertSee('DE123456789')
            ->assertSee($invoice->number)
            ->assertSee('Luftsportverein Beispiel e.V.')
            ->assertSee('Umsatzsteuer 19 %')
            ->assertSee('95,20')
            ->assertSee('Zahlbar ohne Abzug bis')
            ->assertSee('IBAN DE02120300000000202051');

        $this->actingAs($this->userWith())
            ->get(route('billing.invoice', $invoice))
            ->assertForbidden();
    }

    #[Test]
    public function a_hidden_discount_is_not_printed_while_a_visible_one_is(): void
    {
        $customer = $this->customer(['default_discount_percent' => '10', 'discount_visible' => false]);
        $hidden = $this->issued($customer);

        $html = app(InvoiceDocuments::class)->html($hidden);
        $this->assertStringContainsString('72,00', $html);          // 80 - 10 %
        $this->assertStringNotContainsString('Listenpreis', $html);
        $this->assertStringNotContainsString('80,00', $html);

        $visibleCustomer = $this->customer(['default_discount_percent' => '10', 'discount_visible' => true]);
        $visible = $this->issued($visibleCustomer, registration: 'D-1234');

        $html = app(InvoiceDocuments::class)->html($visible);
        $this->assertStringContainsString('Listenpreis', $html);
        $this->assertStringContainsString('80,00', $html);
        $this->assertStringContainsString('10 %', $html);
        $this->assertStringContainsString('72,00', $html);
    }

    #[Test]
    public function issuing_archives_a_pdf_and_an_xrechnung_that_the_routes_serve(): void
    {
        $invoice = $this->issued();

        $pdf = app(InvoiceDocuments::class)->media($invoice, InvoiceDocuments::PDF);
        $xml = app(InvoiceDocuments::class)->media($invoice, InvoiceDocuments::XML);

        $this->assertNotNull($pdf);
        $this->assertNotNull($xml);
        $this->assertSame($invoice->number.'.pdf', $pdf->file_name);
        $this->assertStringStartsWith('%PDF', Storage::disk('documents')->get($pdf->getPathRelativeToRoot()));

        $viewer = $this->userWith(Permissions::VIEW);

        $this->actingAs($viewer)->get(route('billing.invoice.pdf', $invoice))
            ->assertSuccessful()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($viewer)->get(route('billing.invoice.xml', $invoice))
            ->assertSuccessful()
            ->assertHeader('Content-Type', 'application/xml');

        // Ein zweiter Aufruf erzeugt kein zweites Archiv.
        app(InvoiceDocuments::class)->ensureArchived($invoice->fresh());
        $this->assertCount(2, $invoice->fresh()->getMedia(Invoice::DOCUMENTS));
    }

    #[Test]
    public function the_xrechnung_is_well_formed_and_carries_the_figures_of_the_sheet(): void
    {
        $invoice = $this->issued();
        $xml = app(XRechnungWriter::class)->write($invoice);

        $doc = new DOMDocument;
        $this->assertTrue($doc->loadXML($xml));

        $this->assertStringContainsString(XRechnungWriter::CUSTOMIZATION, $xml);
        $this->assertStringContainsString('<cbc:ID>'.$invoice->number.'</cbc:ID>', $xml);
        $this->assertStringContainsString('<cbc:InvoiceTypeCode>380</cbc:InvoiceTypeCode>', $xml);
        $this->assertStringContainsString('<cbc:BuyerReference>', $xml);
        $this->assertStringContainsString('<cbc:TaxInclusiveAmount currencyID="EUR">95.20</cbc:TaxInclusiveAmount>', $xml);
        $this->assertStringContainsString('<cbc:TaxAmount currencyID="EUR">15.20</cbc:TaxAmount>', $xml);
        $this->assertStringContainsString('unitCode="HUR"', $xml);
        $this->assertStringContainsString('<cbc:PaymentMeansCode>58</cbc:PaymentMeansCode>', $xml);
        $this->assertStringContainsString('<cbc:PostalZone>79100</cbc:PostalZone>', $xml);
        $this->assertStringContainsString('<cbc:CompanyID>DE123456789</cbc:CompanyID>', $xml);
    }

    #[Test]
    public function nothing_is_served_while_the_module_is_off(): void
    {
        $invoice = $this->issued();

        app(ModuleManager::class)->disable('billing');
        app(ModuleManager::class)->forgetCache();

        $this->actingAs($this->userWith(Permissions::VIEW))
            ->get(route('billing.invoice', $invoice))
            ->assertNotFound();
    }

    private function issued(?Customer $customer = null, string $registration = 'D-KABC'): Invoice
    {
        $clerk = $this->clerk();
        $aircraft = Aircraft::firstOrCreate(['registration' => $registration], ['model' => 'ASK 21']);

        return app(IssueInvoice::class)->handle(
            app(BuildInvoiceFromWorkOrder::class)->handle($this->releasedVisit(aircraft: $aircraft), $customer ?? $this->customer(), $clerk),
            $clerk,
        );
    }
}
