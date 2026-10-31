<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

/**
 * Wie die Umsatzsteuer auf einer Rechnung behandelt wird.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER HINWEISTEXT KOMMT AUS DER SPRACHDATEI, NICHT AUS EINEM FREITEXTFELD.
 * § 14 Abs. 4 UStG verlangt bei Steuerbefreiung einen Hinweis auf den Grund,
 * bei Reverse Charge den Satz „Steuerschuldnerschaft des Leistungsempfängers",
 * bei § 19 den Hinweis auf die Kleinunternehmerregelung. Wer das tippt,
 * tippt es irgendwann falsch -- „USt. entfällt" ohne Paragraph ist kein
 * ordnungsgemäßer Hinweis.
 *
 * Gebaut sind die Fälle, die vorkommen (Entscheidung 2026-10-30: 19 % ist
 * die Vorgabe). Die Luftfahrt-Befreiung nach § 4 Nr. 2 i. V. m. § 8 UStG
 * steht bereit, weil eine Werft sie für Kunden mit Luftfahrtunternehmen
 * brauchen kann; der Verein braucht sie nicht.
 * ─────────────────────────────────────────────────────────────────────────────
 */
enum TaxTreatment: string
{
    case Standard = 'standard';
    case SmallBusiness = 'small_business';
    case ReverseCharge = 'reverse_charge';
    case ExemptAviation = 'exempt_aviation';
    case ExemptOther = 'exempt_other';

    public function label(): string
    {
        return __('billing.tax_treatment.'.$this->value);
    }

    /** Wird auf dieser Rechnung Umsatzsteuer ausgewiesen? */
    public function taxesApply(): bool
    {
        return $this === self::Standard;
    }

    /** Der Pflichthinweis auf dem Dokument, oder null bei Regelbesteuerung. */
    public function notice(): ?string
    {
        return $this === self::Standard ? null : __('billing.tax_notice.'.$this->value);
    }

    /**
     * EN 16931 BT-118 / UNCL 5305: S Regel, AE Reverse Charge, E befreit.
     * Für § 19 UStG gibt es keinen eigenen Code; die XRechnung erwartet „E"
     * mit Begründung.
     */
    public function categoryCode(): string
    {
        return match ($this) {
            self::Standard => 'S',
            self::ReverseCharge => 'AE',
            self::SmallBusiness, self::ExemptAviation, self::ExemptOther => 'E',
        };
    }

    /** Die Vorgabe der Organisation: Kleinunternehmer oder Regelbesteuerung. */
    public static function organisationDefault(): self
    {
        return (bool) config('aeronance.billing.small_business', false)
            ? self::SmallBusiness
            : self::Standard;
    }
}
