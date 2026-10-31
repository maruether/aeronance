<?php

declare(strict_types=1);

namespace App\Modules\Billing\Mail;

use App\Core\Mail\Postman;
use App\Modules\Billing\Models\Invoice;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Die Rechnung an den Kunden, PDF im Anhang, XRechnung dazu, wenn gewünscht.
 */
final class InvoiceMail extends Mailable
{
    /** @param  list<Media>  $attachmentsFromArchive */
    public function __construct(
        public readonly Invoice $invoice,
        private readonly array $attachmentsFromArchive,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('billing.mail.subject', [
                'kind' => $this->invoice->kind->label(),
                'number' => (string) $this->invoice->number,
                'organisation' => Postman::fromName(),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'billing.mail.invoice',
            with: [
                'invoice' => $this->invoice,
                'organisation' => Postman::fromName(),
            ],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return array_map(
            static fn (Media $media): Attachment => Attachment::fromStorageDisk($media->disk, $media->getPathRelativeToRoot())
                ->as($media->file_name)
                ->withMime($media->mime_type),
            $this->attachmentsFromArchive,
        );
    }
}
