<?php

declare(strict_types=1);

namespace App\Modules\Tooling\Enums;

/**
 * Ist das Werkzeug benutzbar?
 *
 * Bewusst kurz. Ein Werkzeugbestand, der fünf Zustände kennt, wird nicht
 * gepflegt — und ein ungepflegter Bestand ist schlimmer als keiner, weil er
 * behauptet, aktuell zu sein.
 */
enum ToolState: string
{
    case InService = 'in_service';

    /**
     * Aus dem Verkehr gezogen: defekt, verliehen, zur Kalibrierung unterwegs.
     *
     * Der Grund gehört in die Bemerkung. Ihn als eigene Zustände zu führen
     * hieße, sie auseinanderhalten zu müssen, und niemand tut das zuverlässig.
     */
    case OutOfService = 'out_of_service';

    /** Weg. Bleibt in der Liste, weil ein Werkzeug, das wieder auftaucht, seine
     *  Kalibrierhistorie mitbringt. */
    case Lost = 'lost';

    /** Ausgesondert. */
    case Retired = 'retired';

    public function label(): string
    {
        return __('tooling.state.'.$this->value);
    }

    public function isUsable(): bool
    {
        return $this === self::InService;
    }

    public function color(): string
    {
        return match ($this) {
            self::InService => 'success',
            self::OutOfService => 'warning',
            self::Lost, self::Retired => 'gray',
        };
    }
}
