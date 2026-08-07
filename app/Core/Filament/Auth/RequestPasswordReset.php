<?php

declare(strict_types=1);

namespace App\Core\Filament\Auth;

use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as Base;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * „Passwort vergessen" -- ohne zu verraten, wer ein Konto hat.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „Die Anzeige für den user beim anfragen des reset ist unabhängig
 * davon ob es die mail gibt oder nicht."
 *
 * FILAMENT MACHT DAS AB WERK NICHT. Bei einer unbekannten Adresse zeigt es
 * „Wir konnten keinen Benutzer mit dieser E-Mail-Adresse finden" -- in Rot.
 * Damit ist das Formular ein Werkzeug, mit dem sich Mitgliedschaften abfragen
 * lassen: Adresse eintippen, Farbe der Meldung ablesen. Bei einem Verein sind
 * das Namen von Menschen, und die Mitgliedschaft in einem Luftsportverein ist
 * eine Information, die niemanden ausserhalb angeht.
 *
 * Hier sieht jeder dieselbe Meldung: „Falls die Adresse bei uns liegt, ist die
 * Mail unterwegs."
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * EINE AUSNAHME, UND SIE VERRAET NICHTS: die Sperre nach zu vielen Versuchen.
 * Sie haengt an der Adresse, nicht am Konto -- wer sie ausloest, erfaehrt
 * daraus nur, dass er selbst zu oft gedrueckt hat. Und wer sie nicht erklaert
 * bekommt, drueckt weiter und wartet auf eine Mail, die erst in einer Minute
 * kommen darf.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class RequestPasswordReset extends Base
{
    protected function getFailureNotification(string $status): ?Notification
    {
        if ($status === Password::RESET_THROTTLED) {
            return parent::getFailureNotification($status);
        }

        // Dieselbe Meldung wie bei Erfolg -- und zwar wirklich dieselbe, nicht
        // eine aehnliche: Zwei Formulierungen liessen sich wieder auseinander-
        // halten.
        return $this->neutralNotification();
    }

    protected function getSentNotification(string $status): ?Notification
    {
        return $this->neutralNotification();
    }

    private function neutralNotification(): Notification
    {
        return Notification::make()
            ->title(__('auth.reset.sent_title'))
            ->body(__('auth.reset.sent_body'))
            ->success();
    }
}
