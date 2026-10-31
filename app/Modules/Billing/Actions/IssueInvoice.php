<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Billing\Documents\InvoiceDocuments;
use App\Modules\Billing\Enums\InvoiceState;
use App\Modules\Billing\Enums\TaxTreatment;
use App\Modules\Billing\Events\InvoiceIssued;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * Eine Rechnung stellen: Nummer vergeben, einfrieren, Dokumente archivieren.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * VIER VERWEIGERUNGEN, alle vor der Nummer:
 *
 *   1. Keine Position, oder eine ohne Preis -- eine Rechnung über 0 € für
 *      eine vergessene Position ist die teuerste Art, nichts zu berechnen.
 *   2. Empfänger ohne Anschrift -- § 14 Abs. 4 Nr. 1 UStG.
 *   3. Aussteller ohne Anschrift oder, bei Regelbesteuerung, ohne
 *      Steuernummer und ohne USt-IdNr. -- § 14 Abs. 4 Nr. 1 und 2.
 *   4. Keine Berechtigung zum Stellen -- das ist der Schritt, der eine Nummer
 *      verbraucht und einen Beleg erzeugt, den niemand mehr ändert.
 *
 * DER SNAPSHOT DES KUNDEN WIRD BEIM STELLEN AUFGEFRISCHT. Der Entwurf
 * spiegelt den Kunden; was auf dem Beleg steht, ist der Kunde im Moment der
 * Unterschrift. Danach ändert sich am Beleg nichts mehr, auch wenn der Kunde
 * umzieht.
 *
 * DIE DOKUMENTE ENTSTEHEN NACH DEM COMMIT. Scheitert die PDF-Erzeugung, ist
 * die Rechnung trotzdem gestellt -- die Nummer ist verbraucht, und das muss
 * sie bleiben. InvoiceDocuments::ensureArchived() holt die Dateien beim
 * nächsten Zugriff nach.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class IssueInvoice
{
    public function __construct(
        private InvoiceNumbers $numbers,
        private InvoiceCalculator $calculator,
        private InvoiceDocuments $documents,
    ) {}

    public function handle(Invoice $invoice, User $user, ?string $issuedAt = null): Invoice
    {
        if (! $user->can(Permissions::ISSUE)) {
            throw new RuntimeException(sprintf('Issuing an invoice requires the "%s" permission.', Permissions::ISSUE));
        }

        if (! $invoice->isDraft()) {
            throw new RuntimeException(sprintf('Invoice %s has already been issued.', $invoice->number));
        }

        $invoice->load(['lines', 'customer']);

        if ($invoice->lines->isEmpty()) {
            throw new RuntimeException('An invoice without a single line is not issued.');
        }

        if ($invoice->hasPriceGaps()) {
            throw new RuntimeException(
                'A line still has no price. Every line is priced before the invoice is issued -- '
                .'a forgotten one is money nobody asks for twice.'
            );
        }

        // Eine Gutschrift nennt den Empfaenger des Originals, nicht den
        // Kunden von heute -- ihr Snapshot ist die Kopie aus CancelInvoice.
        if ($invoice->customer !== null && $invoice->corrects_invoice_id === null) {
            $invoice->fill(BuildInvoiceFromWorkOrder::snapshotOf($invoice->customer));
        }

        if (trim((string) $invoice->customer_name) === '' || trim((string) $invoice->customer_address) === '') {
            throw new RuntimeException('The recipient needs a name and an address on the invoice (§ 14 Abs. 4 Nr. 1 UStG).');
        }

        self::assertIssuerIsComplete($invoice->tax_treatment);

        $when = ($issuedAt !== null ? Carbon::parse($issuedAt) : now())->startOfDay();
        $terms = $invoice->customer?->effectivePaymentTermsDays()
            ?? (int) config('aeronance.billing.payment_terms_days', 14);

        $this->calculator->recalculate($invoice);

        $issued = DB::transaction(function () use ($invoice, $user, $when, $terms): Invoice {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

            if (! $locked->isDraft()) {
                throw new RuntimeException('This invoice was issued by somebody else a moment ago.');
            }

            $locked->fill([
                'number' => $this->numbers->next($locked->kind, $when),
                'issued_at' => $when->toDateString(),
                'due_at' => $when->copy()->addDays($terms)->toDateString(),
                'issued_by' => $user->getKey(),
                'issued_by_name' => $user->name,
                'state' => InvoiceState::Issued,
            ]);
            $locked->save();

            return $locked;
        });

        $this->documents->ensureArchived($issued);

        Event::dispatch(new InvoiceIssued(
            invoiceId: (int) $issued->getKey(),
            number: (string) $issued->number,
            customerName: (string) $issued->customer_name,
            grossTotal: (string) $issued->gross_total,
        ));

        return $issued->fresh(['lines', 'customer']);
    }

    /** Was der Aussteller eingetragen haben muss, bevor er eine Rechnung schreibt. */
    public static function assertIssuerIsComplete(TaxTreatment $treatment): void
    {
        $organisation = (array) config('aeronance.organisation', []);

        if (trim((string) ($organisation['name'] ?? '')) === '' || trim((string) ($organisation['address'] ?? '')) === '') {
            throw new RuntimeException(
                'The organisation needs a name and an address before it issues invoices -- '
                .'see the settings, group "Organisation".'
            );
        }

        if ($treatment->taxesApply()
            && trim((string) ($organisation['tax_number'] ?? '')) === ''
            && trim((string) ($organisation['vat_id'] ?? '')) === '') {
            throw new RuntimeException(
                'An invoice that shows VAT needs the issuer\'s tax number or VAT ID '
                .'(§ 14 Abs. 4 Nr. 2 UStG) -- see the settings, group "Organisation".'
            );
        }
    }
}
