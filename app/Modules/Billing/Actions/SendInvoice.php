<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Core\Mail\Postman;
use App\Models\User;
use App\Modules\Billing\Documents\InvoiceDocuments;
use App\Modules\Billing\Mail\InvoiceMail;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Permissions;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Die Rechnung per Mail an den Kunden -- das archivierte PDF, nichts Neues.
 */
final readonly class SendInvoice
{
    public function __construct(private InvoiceDocuments $documents) {}

    public function handle(Invoice $invoice, User $user, bool $withXRechnung = false): void
    {
        if (! $user->can(Permissions::ISSUE) && ! $user->can(Permissions::MANAGE)) {
            throw new RuntimeException(sprintf('Sending an invoice requires "%s".', Permissions::MANAGE));
        }

        if (! $invoice->isFrozen()) {
            throw new RuntimeException('A draft is not sent. Issue the invoice first; what goes out is the record.');
        }

        if (! Postman::canSend()) {
            throw new RuntimeException('No mail server is configured, so nothing can be sent.');
        }

        $email = trim((string) $invoice->customer?->email);

        if ($email === '') {
            throw new RuntimeException(sprintf('Customer %s has no e-mail address.', $invoice->customer_name));
        }

        $this->documents->ensureArchived($invoice);

        $attachments = array_values(array_filter([
            $this->documents->media($invoice, InvoiceDocuments::PDF),
            $withXRechnung ? $this->documents->media($invoice, InvoiceDocuments::XML) : null,
        ]));

        Mail::to($email)->send(new InvoiceMail($invoice, $attachments));

        $invoice->forceFill(['sent_at' => now()])->save();
    }
}
