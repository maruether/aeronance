<?php

declare(strict_types=1);

namespace App\Modules\Billing;

/**
 * Wer darf was mit Kunden und Rechnungen.
 *
 * Vier Rechte für vier verschiedene Handlungen. Die Trennung, die zählt, ist
 * die zwischen MANAGE und ISSUE: Einen Entwurf schreiben kann jeder im Büro;
 * eine Rechnung STELLEN verbraucht eine Nummer und erzeugt einen Beleg, den
 * niemand mehr ändern kann. Das ist der Schritt, für den jemand geradesteht.
 */
final class Permissions
{
    /** Kunden, Preise und Rechnungen lesen. */
    public const VIEW = 'billing.view';

    /** Kunden und Preise pflegen, Rechnungsentwürfe anlegen und ändern. */
    public const MANAGE = 'billing.manage';

    /** Rechnung stellen, stornieren, Gutschrift erteilen -- Nummern verbrauchen. */
    public const ISSUE = 'billing.issue';

    /** Zahlungseingänge erfassen. */
    public const PAYMENTS = 'billing.payments';
}
