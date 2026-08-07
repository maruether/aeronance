<?php

declare(strict_types=1);

namespace App\Modules\Tooling\Enums;

/**
 * Warum eine Kalibrierung eine Nachprüfung auslöst.
 *
 * Zwei sehr verschiedene Fälle, die dieselbe Frage aufwerfen — „was wurde in
 * dieser Zeit damit gearbeitet?" — aber mit unterschiedlichem Gewicht und
 * unterschiedlichem Zeitraum:
 *
 *   Overdue        Die Kalibrierung kam zu spät. Der Zeitraum reicht vom
 *                  abgelaufenen Fälligkeitsdatum bis zur Messung. Das Werkzeug
 *                  war dabei möglicherweise völlig in Ordnung — nachgewiesen
 *                  war es nur nicht. EASA lässt dafür sogar ausdrücklich
 *                  befristete Verlängerungen zu.
 *
 *   OutOfTolerance Das Werkzeug war außer Toleranz. Der Zeitraum reicht zurück
 *                  bis zur letzten Messung MIT Befund „in Toleranz", denn ab
 *                  wann es abgewichen ist, weiß niemand. Das ist der Fall, den
 *                  die Vorschrift meint.
 *
 * Treffen beide zu, gewinnt OutOfTolerance: längerer Zeitraum, härterer Befund.
 */
enum GapReason: string
{
    case Overdue = 'overdue';

    case OutOfTolerance = 'out_of_tolerance';

    public function label(): string
    {
        return __('tooling.gap_reason.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Overdue => 'warning',
            self::OutOfTolerance => 'danger',
        };
    }
}
