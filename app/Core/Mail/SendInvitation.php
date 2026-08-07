<?php

declare(strict_types=1);

namespace App\Core\Mail;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

/**
 * Eine Einladung verschicken -- oder begruenden, warum nicht.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER RUECKGABEWERT IST EIN GRUND, KEIN BOOLEAN.
 *
 * "false" beantwortet die Frage "warum nicht" nicht, und genau die stellt
 * jemand, der auf den Knopf gedrueckt hat und nichts passieren sieht. Fehlt
 * die Mailadresse? Ist gar kein Mailserver eingerichtet? Zwei verschiedene
 * Antworten, zwei verschiedene naechste Schritte.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SendInvitation
{
    public const SENT = 'sent';

    public const NO_MAILER = 'no_mailer';

    public const NO_ADDRESS = 'no_address';

    public const FAILED = 'failed';

    public function handle(User $user): string
    {
        if (! Postman::canSend()) {
            return self::NO_MAILER;
        }

        /*
         * Konten aus einem Provider koennen eine Platzhalter-Adresse tragen
         * (@invalid.local, siehe LinkExternalIdentity) -- an die zu senden
         * hiesse, eine Zustellung zu behaupten, die nicht stattfindet.
         */
        if (blank($user->email) || str_ends_with((string) $user->email, '@invalid.local')) {
            return self::NO_ADDRESS;
        }

        /*
         * DERSELBE MECHANISMUS WIE BEIM ZURUECKSETZEN -- Laravels
         * Password-Broker. Gleiche Ablaufzeit, gleiche Einmaligkeit, gleiche
         * Pruefung beim Einloesen. Ein zweiter Weg mit eigenen Regeln waere ein
         * zweiter Weg, den jemand falsch bauen kann.
         */
        $token = Password::broker()->createToken($user);

        $url = Filament::getResetPasswordUrl($token, $user);

        try {
            Mail::to($user->email)->send(new InvitationMail($user, $url));
        } catch (\Throwable) {
            /*
             * Die Begruendung wird NICHT durchgereicht. Sie enthaelt bei SMTP
             * regelmaessig die Empfaengeradresse und manchmal Serverantworten
             * mit Zugangsdaten -- und dieser Text landet in einer Meldung auf
             * dem Bildschirm. Wer die Ursache sucht, findet sie im Log und
             * ueber aeronance:mail-test.
             */
            return self::FAILED;
        }

        return self::SENT;
    }
}
