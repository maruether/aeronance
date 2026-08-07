<?php

declare(strict_types=1);

namespace App\Modules\Tooling\Enums;

/**
 * Der Befund einer Kalibrierung — „as found", also der Zustand VOR einer
 * etwaigen Justage.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DAS IST DAS FELD, AN DEM DIE VORSCHRIFT HÄNGT, und es fehlte.
 *
 * EASA-FAQ 116318 zu überfälligen Werkzeugen: „If the tool / equipment **fails
 * during next regular calibration** / inspection, the completed tasks may
 * require to be verified / performed again."
 *
 * Der Auslöser für die Nachprüfung ist also nicht die Verspätung, sondern der
 * Durchfaller. Ein Werkzeug, das zu spät, aber einwandfrei gemessen wurde, ist
 * ein Verwaltungsthema; eines, das außer Toleranz war, stellt jede Arbeit in
 * Frage, die seit der letzten guten Messung damit gemacht wurde — und dieser
 * Zeitraum ist meist erheblich länger als die Verspätung.
 *
 * Der „as left"-Zustand nach einer Justage steht auf dem Schein und gehört in
 * die Bemerkung. Für die Nachprüfpflicht zählt allein, wie das Werkzeug
 * ANKAM.
 * ─────────────────────────────────────────────────────────────────────────────
 */
enum CalibrationResult: string
{
    case InTolerance = 'in_tolerance';

    case OutOfTolerance = 'out_of_tolerance';

    public function label(): string
    {
        return __('tooling.result.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::InTolerance => 'success',
            self::OutOfTolerance => 'danger',
        };
    }

    public function isFailure(): bool
    {
        return $this === self::OutOfTolerance;
    }
}
