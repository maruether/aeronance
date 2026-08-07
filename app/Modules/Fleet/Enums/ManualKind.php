<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Enums;

/**
 * Welche Art Unterlage.
 *
 * Bewusst kurz gehalten und mit einem Sammelfach: Die Bezeichnungen
 * unterscheiden sich je nach Hersteller und Muster erheblich — was bei einem
 * Segelflugzeug „Wartungshandbuch" heißt, ist beim nächsten die
 * „Instandhaltungsanweisung". Eine lange Liste würde erzwingen, für jedes
 * Papier eine passende Schublade zu suchen, wo keine passt.
 */
enum ManualKind: string
{
    /** Wartungshandbuch / Aircraft Maintenance Manual. */
    case Maintenance = 'maintenance';

    /** Ersatzteilkatalog / Illustrated Parts Catalogue. */
    case Parts = 'parts';

    /** Reparaturhandbuch / Structural Repair Manual. */
    case Repair = 'repair';

    /** Flughandbuch — nicht Instandhaltung, aber es gehört zum Luftfahrzeug. */
    case FlightManual = 'flight_manual';

    /** Instandhaltungsprogramm (AMP/IHP). */
    case Programme = 'programme';

    case Other = 'other';

    public function label(): string
    {
        return __('fleet.manual.kind.'.$this->value);
    }
}
