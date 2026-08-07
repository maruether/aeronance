<?php

declare(strict_types=1);

namespace App\Core\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Die Einladung -- der einzige Weg zum ersten Passwort.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WARUM ES DIESE MAIL UEBERHAUPT BRAUCHT, und das ist die Korrektur:
 * „eine anmeldung über den VF geht nicht. das bietet der nicht an."
 *
 * Vereinsflieger sagt, WER jemand ist -- nicht, dass er es ist. Jedes Konto
 * braucht deshalb ein eigenes Passwort in Aeronance, auch die, die aus dem
 * Mitgliederabgleich entstehen. Ohne diese Mail kaeme niemand hinein ausser
 * ueber einen Administrator, der Passwoerter von Hand verteilt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * TECHNISCH IST ES EIN PASSWORT-ZURUECKSETZEN, und das ist Absicht: Derselbe
 * Laravel-Mechanismus, dieselbe Ablaufzeit, dieselbe Einmaligkeit. Ein zweiter
 * Weg mit eigenen Regeln waere ein zweiter Weg, den jemand falsch bauen kann.
 *
 * Nur der TEXT ist ein anderer: „Ihr Konto steht bereit" liest sich anders als
 * „Sie haben ein neues Passwort angefordert" -- und wer eine Zuruecksetzung
 * bekommt, die er nie angefordert hat, wird misstrauisch. Zu Recht.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class InvitationMail extends Mailable
{
    public function __construct(
        public readonly User $user,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.invitation.subject', ['organisation' => Postman::fromName()]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'core.mail.invitation',
            with: [
                'name' => $this->user->name,
                'organisation' => (string) config('aeronance.organisation.name', 'Aeronance'),
                'url' => $this->url,
                'stunden' => (int) config('auth.passwords.users.expire', 60) / 60,
            ],
        );
    }
}
