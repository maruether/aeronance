<?php

declare(strict_types=1);

namespace App\Modules\Vereinsflieger\Enums;

/**
 * Was mit Menschen eines Mitgliedsstatus geschieht.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „passiv darf sich anmelden, die rechte werden nach memberstatus und
 * funktion gemappt."
 *
 * DAS IST KEIN ZUGANGSSCHALTER, SONDERN EINE EINORDNUNG. Zuerst stand hier
 * „passiv = Konto ohne Zugang" -- meine Auslegung, und sie war falsch. Passive
 * Mitglieder melden sich an wie alle anderen; was sie DUERFEN, entscheidet
 * ausschliesslich die Zuordnung.
 *
 * Der Unterschied zwischen „aktiv" und „passiv" ist damit, WELCHE Zuordnung
 * greift -- nicht, ob die Tuer aufgeht. Und genau deshalb ist die Einordnung
 * nuetzlich: Ein Verein, der „Ehrenmitglied" als aktives Mitglied fuehrt,
 * schreibt seine Regel EINMAL fuer „aktiv" statt fuer jede Statusnummer neu.
 *
 * Nur „ignorieren" wirkt hart: Dann entsteht gar kein Konto.
 * ─────────────────────────────────────────────────────────────────────────────
 */
enum MemberStatusHandling: string
{
    case Active = 'active';

    case Passive = 'passive';

    /** Kein Konto. Die Person kommt im Abgleich gar nicht vor. */
    case Ignore = 'ignore';

    public function label(): string
    {
        return __('vereinsflieger.status_handling.'.$this->value);
    }

    public function help(): string
    {
        return __('vereinsflieger.status_handling.help.'.$this->value);
    }

    /** Ob daraus ueberhaupt ein Konto entsteht. */
    public function createsAccount(): bool
    {
        return $this !== self::Ignore;
    }

    /**
     * Der Wert, auf den sich eine Zuordnung berufen kann.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * DAS IST DER EIGENTLICHE ZWECK DER EINORDNUNG.
     *
     * Neben der genauen Statusnummer (`status:101`) bekommt jedes Subjekt auch
     * diese Sammelgruppe (`mitglied:aktiv`). Wer „Ehrenmitglied" als aktives
     * Mitglied fuehrt, muss die Regel damit nicht ein zweites Mal schreiben --
     * und wer doch unterscheiden will, nimmt weiter die Nummer.
     *
     * "ignorieren" hat keine: Es gibt niemanden, dem man etwas zuordnen
     * koennte.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function membershipGroup(): ?string
    {
        return match ($this) {
            self::Active => 'mitglied:aktiv',
            self::Passive => 'mitglied:passiv',
            self::Ignore => null,
        };
    }
}
