<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Billing\Enums\InvoiceState;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoicePayment;
use App\Modules\Billing\Permissions;
use App\Modules\Billing\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Einen Zahlungseingang erfassen.
 *
 * Append-only: Eine Zahlung wird nicht geändert, sondern durch eine
 * Gegenbuchung mit negativem Betrag berichtigt. Der Zahlungsstand der
 * Rechnung ist die Summe; „bezahlt" ist der Zustand, sobald nichts mehr
 * offen ist, und er fällt zurück auf „gestellt", wenn eine Gegenbuchung
 * wieder etwas öffnet.
 *
 * Überzahlung wird abgewiesen: Was mehr hereinkommt als geschuldet, ist kein
 * Zahlungseingang auf diese Rechnung, sondern ein Fall für die Buchhaltung.
 */
final class RecordPayment
{
    public function handle(
        Invoice $invoice,
        string|float $amount,
        string $paidOn,
        User $user,
        ?string $method = null,
        ?string $reference = null,
        ?string $note = null,
    ): InvoicePayment {
        if (! $user->can(Permissions::PAYMENTS)) {
            throw new RuntimeException(sprintf('Recording a payment requires the "%s" permission.', Permissions::PAYMENTS));
        }

        if (! in_array($invoice->state, [InvoiceState::Issued, InvoiceState::Paid], true)) {
            throw new RuntimeException(sprintf('Payments are recorded on issued invoices; %s is %s.', $invoice->label(), $invoice->state->label()));
        }

        $amount = Money::of((string) $amount);

        if (Money::isZero($amount)) {
            throw new RuntimeException('A payment of zero is not a payment.');
        }

        return DB::transaction(function () use ($invoice, $amount, $paidOn, $user, $method, $reference, $note): InvoicePayment {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

            $paid = Money::add((string) $locked->paid_amount, $amount);
            $gross = (string) $locked->gross_total;

            // Bei einer Gutschrift ist brutto negativ und die "Zahlung" eine
            // Erstattung -- dieselbe Regel, gespiegelt.
            $overpaid = Money::isNegative($gross)
                ? Money::compare($paid, $gross) < 0
                : Money::compare($paid, $gross) > 0;

            if ($overpaid) {
                throw new RuntimeException(sprintf(
                    'This would overpay %s: %s of %s already settled.',
                    $locked->label(),
                    Money::format((string) $locked->paid_amount),
                    Money::format($gross),
                ));
            }

            $payment = $locked->payments()->create([
                'amount' => $amount,
                'paid_on' => Carbon::parse($paidOn)->toDateString(),
                'method' => $method,
                'reference' => $reference,
                'note' => $note,
                'recorded_by_id' => $user->getKey(),
            ]);

            $settled = Money::compare($paid, $gross) === 0;

            $locked->forceFill([
                'paid_amount' => $paid,
                'paid_at' => $settled ? Carbon::parse($paidOn)->toDateString() : null,
                'state' => $settled ? InvoiceState::Paid : InvoiceState::Issued,
            ])->save();

            return $payment;
        });
    }
}
