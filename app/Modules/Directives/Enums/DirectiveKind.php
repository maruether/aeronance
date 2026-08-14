<?php

declare(strict_types=1);

namespace App\Modules\Directives\Enums;

/**
 * What kind of document a line is, and therefore how binding it is.
 *
 * The distinction is not cosmetic: an LTA is mandatory, a manufacturer's TM or SB
 * generally is not until an authority adopts it. Both belong on the list -- the
 * point of the overview is to have looked at everything -- but only one of them
 * grounds an aircraft when it is not carried out.
 */
enum DirectiveKind: string
{
    /** Lufttüchtigkeitsanweisung -- mandatory. */
    case Lta = 'lta';

    /** Airworthiness Directive (EASA/FAA) -- mandatory, the same thing in English. */
    case Ad = 'ad';

    /** Technische Mitteilung -- the manufacturer's word. */
    case Tm = 'tm';

    /** Service Bulletin -- the same, in English. */
    case Sb = 'sb';

    public function label(): string
    {
        return __('directives.kind.'.$this->value);
    }

    /**
     * Die Auswahl, wo ein Mensch die Art angibt: ZWEI Paare, nicht vier Werte.
     *
     * Feldtest: "eine TM und eine SB sind das gleiche, nur Deutsch/englisch."
     * Gespeichert bleiben alle vier (die Quellen schreiben das Wort des
     * Dokuments, und das soll die Liste auch zeigen) -- aber niemand soll
     * zwischen Synonymen WÄHLEN müssen. Beim Bearbeiten einer bestehenden
     * englischen Zeile kommt deren eigener Wert dazu, damit das Formular den
     * gespeicherten Stand nicht verliert.
     *
     * @return array<string, string>
     */
    public static function choices(?self $current = null): array
    {
        $choices = [
            self::Lta->value => __('directives.kind_family.lta'),
            self::Tm->value => __('directives.kind_family.tm'),
        ];

        if ($current !== null && ! isset($choices[$current->value])) {
            $choices[$current->value] = $current->label();
        }

        return $choices;
    }

    /**
     * Die Werte, die ein Familien-Filter einschliesst.
     *
     * @return list<string>
     */
    public static function familyValues(string $family): array
    {
        return match (self::tryFrom($family)) {
            self::Lta, self::Ad => [self::Lta->value, self::Ad->value],
            self::Tm, self::Sb => [self::Tm->value, self::Sb->value],

            /*
             * Unbekannt trifft NICHTS statt versehentlich alles Englische:
             * Der Filterzustand kommt aus dem Browser und wird von Filament
             * nicht gegen die Auswahlliste geprüft -- ein 'ad' im Zustand
             * zeigte sonst still die TM/SB-Familie, ohne Filterchip.
             */
            null => [],
        };
    }

    /**
     * Die Art aus der Nummer selbst, wo das Dokument sie nennt.
     *
     * "TM 300/12", "SB 090702", "LTA 03-001", "AD-2020-15": Das führende
     * Kürzel IST die Art -- das ist keine Raterei, sondern das eigene Wort
     * des Dokuments. Ohne Kürzel (nackte Behördennummern) antwortet null,
     * und der Import nimmt den angegebenen Vorgabewert.
     */
    public static function fromNumber(string $number): ?self
    {
        if (preg_match('/^(lta|ad|tm|sb)(?=[\s\-.:0-9])/i', trim($number), $m) !== 1) {
            return null;
        }

        return self::from(mb_strtolower($m[1]));
    }

    /**
     * Whether skipping it grounds the aircraft.
     *
     * A TM left undone is a decision the operation may take and answer for; an
     * LTA left undone is not. Both still appear as open items -- see
     * OutstandingDirectives -- but only the mandatory ones block.
     */
    public function isMandatory(): bool
    {
        return match ($this) {
            self::Lta, self::Ad => true,
            self::Tm, self::Sb => false,
        };
    }
}
