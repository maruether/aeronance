<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Enums\InvoiceState;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceLine;
use App\Modules\Billing\Permissions;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Eine gestellte Rechnung stornieren -- durch eine Gutschrift.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * KORREKTUR STATT ÄNDERUNG. Das Original bleibt, wie es war, und bekommt den
 * Zustand „storniert"; daneben entsteht eine Gutschrift mit eigener Nummer
 * (GS-…), allen Positionen mit umgekehrtem Vorzeichen und einem Verweis auf
 * das Original. Beide Belege zusammen ergeben null; jeder für sich bleibt
 * lesbar. Das ist, was GoBD und § 14 UStG unter einer Rechnungskorrektur
 * verstehen.
 *
 * Eine bezahlte Rechnung lässt sich ebenfalls stornieren -- dann ist der
 * Betrag der Gutschrift dem Kunden zu erstatten, und die Gutschrift zeigt
 * das als offenen (negativen) Betrag.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class CancelInvoice
{
    public function __construct(
        private InvoiceCalculator $calculator,
        private IssueInvoice $issue,
    ) {}

    public function handle(Invoice $original, User $user, string $reason): Invoice
    {
        if (! $user->can(Permissions::ISSUE)) {
            throw new RuntimeException(sprintf('Cancelling an invoice requires the "%s" permission.', Permissions::ISSUE));
        }

        if (trim($reason) === '') {
            throw new RuntimeException('A cancellation names its reason; it is printed on the credit note.');
        }

        if ($original->correctedBy()->exists()) {
            throw new RuntimeException(sprintf('Invoice %s has already been cancelled.', $original->number));
        }

        if (! in_array($original->state, [InvoiceState::Issued, InvoiceState::Paid], true)) {
            throw new RuntimeException(sprintf(
                'Only an issued invoice is cancelled; %s is %s.',
                $original->label(),
                $original->state->label(),
            ));
        }

        if ($original->isCreditNote()) {
            throw new RuntimeException('A credit note is not cancelled; write an invoice if the credit was wrong.');
        }

        $original->load('lines');

        $creditNote = DB::transaction(function () use ($original, $user, $reason): Invoice {
            $draft = Invoice::create([
                'kind' => InvoiceKind::CreditNote,
                'customer_id' => $original->customer_id,
                'customer_name' => $original->customer_name,
                'customer_address' => $original->customer_address,
                'customer_country' => $original->customer_country,
                'customer_vat_id' => $original->customer_vat_id,
                'customer_number' => $original->customer_number,
                'buyer_reference' => $original->buyer_reference,
                'work_order_id' => $original->work_order_id,
                'work_order_number' => $original->work_order_number,
                'aircraft_registration' => $original->aircraft_registration,
                'service_from' => $original->service_from?->toDateString(),
                'service_to' => $original->service_to?->toDateString(),
                'tax_treatment' => $original->tax_treatment,
                'discount_percent' => $original->discount_percent,
                'discount_visible' => $original->discount_visible,
                'corrects_invoice_id' => $original->getKey(),
                'intro' => __('billing.invoice.intro_credit_note', [
                    'number' => $original->number,
                    'date' => $original->issued_at?->format('d.m.Y') ?? '—',
                    'reason' => trim($reason),
                ]),
                'note' => $original->note,
            ]);

            foreach ($original->lines as $line) {
                /** @var InvoiceLine $line */
                $draft->lines()->create([
                    'position' => $line->position,
                    'origin' => $line->origin,
                    'source_reference' => $line->source_reference,
                    'description' => $line->description,
                    // Drei Nachkommastellen bleiben: 1,250 h werden -1,250 h.
                    'quantity' => bcmul((string) $line->quantity, '-1', 3),
                    'unit' => $line->unit,
                    'list_unit_price' => $line->list_unit_price,
                    'discount_percent' => $line->discount_percent,
                    'discount_visible' => $line->discount_visible,
                    'tax_rate' => $line->tax_rate,
                    'needs_price' => false,
                ]);
            }

            $this->calculator->recalculate($draft->fresh());

            /*
             * Das Stellen laeuft in seiner eigenen Transaktion (Savepoint);
             * der Snapshot des Kunden wird dabei NICHT aus dem Stammsatz
             * erneuert, weil die Gutschrift den Empfaenger des Originals
             * nennen muss -- deshalb die Kopie oben und ein Entwurf, dessen
             * customer-Relation dasselbe liefert.
             */
            $issued = $this->issue->handle($draft->fresh(['lines', 'customer']), $user);

            $original->forceFill([
                'state' => InvoiceState::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => trim($reason),
                'cancelled_by_id' => $user->getKey(),
            ])->save();

            return $issued;
        });

        return $creditNote->fresh(['lines', 'corrects']);
    }
}
