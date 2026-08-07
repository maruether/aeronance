<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Core\Mail\Postman;
use App\Core\Mail\TestMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Eine Testmail -- damit der Zugang bewiesen ist, bevor jemand ihn braucht.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER FALL, DEN DAS VERHINDERT: Ein Verein traegt SMTP-Daten ein, jemand
 * vertippt sich beim Passwort, und niemand merkt es. Wochen spaeter braucht
 * ein Mitglied ein neues Passwort, drueckt „vergessen", bekommt eine
 * Bestaetigung -- und wartet. Der Fehler steht im Log, das keiner liest.
 *
 * Der Zugang ist entweder bewiesen oder unbekannt. Diese Unterscheidung kostet
 * eine Minute und spart einen Anruf.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class MailTestCommand extends Command
{
    protected $signature = 'aeronance:mail-test {empfaenger : Wohin die Testmail gehen soll}';

    protected $description = 'Verschickt eine Testmail und meldet, was der Mailserver geantwortet hat.';

    public function handle(): int
    {
        $empfaenger = (string) $this->argument('empfaenger');

        if (! filter_var($empfaenger, FILTER_VALIDATE_EMAIL)) {
            $this->components->error(sprintf('"%s" ist keine E-Mail-Adresse.', $empfaenger));

            return self::FAILURE;
        }

        if (! Postman::configured()) {
            /*
             * Kein Fehlschlag im Sinne von „kaputt", sondern eine Ansage: Es
             * ist nichts eingerichtet. Wer das nicht unterscheidet, sucht den
             * Fehler in der Verbindung statt in den Einstellungen.
             */
            $this->components->warn(
                'Kein SMTP-Zugang hinterlegt. Unter Einstellungen → E-Mail eintragen; '
                .'ohne ihn verschickt die Anwendung nichts.'
            );

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Sende an %s über %s:%s als "%s" <%s>',
            $empfaenger,
            (string) config('mail.mailers.smtp.host'),
            (string) config('mail.mailers.smtp.port'),
            Postman::fromName(),
            (string) config('mail.from.address'),
        ));

        try {
            Mail::to($empfaenger)->send(new TestMail);
        } catch (Throwable $e) {
            /*
             * Die Begruendung des Servers wird DURCHGEREICHT. „Versand
             * fehlgeschlagen" allein zwingt denjenigen, der es liest, in die
             * Logdatei -- und genau das war der Zustand, den dieser Befehl
             * abschaffen soll.
             */
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(
            'Abgeschickt. Wenn nichts ankommt, liegt es nicht mehr an dieser Anwendung -- '
            .'dann beim Empfänger nachsehen (Spam) oder beim Anbieter.'
        );

        return self::SUCCESS;
    }
}
