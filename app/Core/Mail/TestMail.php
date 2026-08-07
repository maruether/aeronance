<?php

declare(strict_types=1);

namespace App\Core\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Die Testmail.
 *
 * Ein Mailable und kein Mail::raw(), aus zwei Gruenden: Es laesst sich pruefen
 * (Mail::fake sieht rohe Nachrichten nicht), und es traegt dasselbe Layout wie
 * jede spaetere Einladung -- wer die Testmail bekommt, sieht also, wie die
 * echten aussehen werden.
 */
final class TestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.test.subject', ['organisation' => Postman::fromName()]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'core.mail.test',
            with: [
                'organisation' => (string) config('aeronance.organisation.name', 'Aeronance'),
                'zeit' => now()->format('d.m.Y H:i'),
            ],
        );
    }
}
