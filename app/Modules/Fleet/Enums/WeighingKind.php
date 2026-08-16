<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Enums;

/**
 * Which weighing sheet applies.
 *
 * Two, not three. The BWLV publishes separate forms for Flugzeug and
 * Motorsegler, and they are the same document with a different heading -- same
 * sections, same columns, same arithmetic. Modelling that difference would have
 * been modelling a title.
 */
enum WeighingKind: string
{
    /**
     * Weighed component by component, with a second figure per row for the
     * non-lifting parts, which carry a limit of their own.
     */
    case Glider = 'glider';

    /**
     * Weighed on supports and reduced by what can be flown off -- usable fuel
     * and oil, per tank, at a fixed density.
     */
    case Powered = 'powered';

    public function label(): string
    {
        return __('fleet.weighing.kind.'.$this->value);
    }

    public function usesComponents(): bool
    {
        return $this === self::Glider;
    }

    public function usesDeductions(): bool
    {
        return $this === self::Powered;
    }

    /**
     * The rows the BWLV sheet has pre-printed, so a new report starts as the
     * paper does rather than as an empty table.
     *
     * @return list<string>
     */
    public function defaultComponents(): array
    {
        return $this === self::Glider ? [
            'Tragwerk rechts innen',
            'Tragwerk rechts außen',
            'Tragwerk links innen (+ Bolzen)',
            'Tragwerk links außen',
            'Rumpf mit Seitenruder und Hauben',
            'Ausrüstung laut Verzeichnis',
            'Trimmgewichte in Nase',
            'Trimmgewichte in Seitenflosse',
            'Höhenleitwerk',
            'Tragwerkstreben (50 % N.T.)',
        ] : [];
    }

    /** @return list<array{label: string, density: float}> */
    public function defaultDeductions(): array
    {
        if ($this !== self::Powered) {
            return [];
        }

        // The densities are printed on the form itself.
        return [
            ['label' => 'Rumpfbehälter I', 'density' => 0.72],
            ['label' => 'Rumpfbehälter II', 'density' => 0.72],
            ['label' => 'Flügelbehälter I', 'density' => 0.72],
            ['label' => 'Flügelbehälter II', 'density' => 0.72],
            ['label' => 'Schmierstoffbehälter', 'density' => 0.89],
        ];
    }

    /*
     * defaultSupports() stand hier und ist nach Undercarriage gewandert: Wie
     * viele Waegepunkte es gibt, haengt am Fahrwerk und nicht an der Blattart.
     * Beim einraedrigen Segelflugzeug stimmte die alte Zuordnung zufaellig,
     * beim Motorsegler mit Bugrad nicht.
     */
}
