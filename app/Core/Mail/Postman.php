<?php

declare(strict_types=1);

namespace App\Core\Mail;

use Illuminate\Support\Facades\Config;

/**
 * Ob und wie diese Installation Mail verschickt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe (F4): „mail kommt noch, gehört in den core, details später."
 *
 * EIN VEREIN OHNE MAILSERVER IST DER NORMALFALL, nicht die Ausnahme. Deshalb
 * ist der Versand nicht vorausgesetzt, sondern eine Eigenschaft, die man
 * abfragen kann -- und alles, was Mail braucht, fragt vorher.
 *
 * Der Unterschied ist praktisch: Ohne diese Abfrage zeigte die Anmeldeseite
 * einen „Passwort vergessen"-Link, der ins Leere fuehrt. Wer ihn drueckt,
 * bekommt eine Bestaetigung, wartet auf eine Mail, die nie kommt, und ruft
 * dann den Werkstattleiter an. Ein Link, der nichts tut, ist schlimmer als
 * keiner.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ERKANNT AM SMTP-SERVER, nicht an einem eigenen Schalter.
 *
 * Ein zweiter Schalter „Mail aktiv" waere eine zweite Wahrheit: Man koennte
 * ihn anschalten, ohne einen Server einzutragen, und haette wieder den Link,
 * der ins Leere fuehrt. Wer einen Server eintraegt, will Mail; wer keinen
 * eintraegt, will keine.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class Postman
{
    /**
     * Kann diese Installation ueberhaupt Mail verschicken?
     */
    public static function canSend(): bool
    {
        if (! self::configured()) {
            return false;
        }

        /*
         * Der "log"-Mailer schreibt in die Logdatei, statt zu verschicken.
         * Das ist im Test und in der Entwicklung richtig -- aber es ist kein
         * Versand, und die Oberflaeche darf ihn nicht dafuer halten.
         */
        return Config::string('mail.default') !== 'log';
    }

    /**
     * Ist ein Zugang hinterlegt?
     */
    public static function configured(): bool
    {
        return filled(Config::get('mail.mailers.smtp.host'))
            && filled(Config::get('mail.from.address'));
    }

    /**
     * Der Absendername -- der eingestellte oder der Name der Organisation.
     *
     * Ein Verein, der nichts einträgt, bekommt seinen eigenen Namen im
     * Postfach der Empfänger und nicht „Laravel".
     */
    public static function fromName(): string
    {
        $eingestellt = trim((string) Config::get('mail.from.name'));

        if ($eingestellt !== '') {
            return $eingestellt;
        }

        return (string) Config::get('aeronance.organisation.name', 'Aeronance');
    }
}
