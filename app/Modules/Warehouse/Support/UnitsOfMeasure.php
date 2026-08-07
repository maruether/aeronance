<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Support;

/**
 * Die Einheiten, in denen ein Lager zählt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe (F17): „kann ne liste sein, wir müssen aber alles abdecken."
 *
 * Vorher war das ein freies Textfeld. Damit standen „Stk", „St." und „stk"
 * nebeneinander und zählten als drei Einheiten -- und eine Auswertung über
 * Bestände wurde zur Sortierarbeit.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DREI ENTSCHEIDUNGEN, DIE EINE LÄNGERE LISTE ALLEIN NICHT LÖST:
 *
 *  1. ZOLL UND FUSS SIND KEINE ZIERDE. Luftfahrtteile kommen in imperialen
 *     Massen -- Schlauchdurchmesser, Kabellängen, Blechstärken. Wer nur
 *     metrisch anbietet, zwingt zum Umrechnen von Hand, und genau da entstehen
 *     Fehler.
 *
 *  2. ES WIRD NICHT UMGERECHNET. Ein Bestand von „3,048 m Draht" statt
 *     „10 ft" ist beim Nachzählen im Regal nicht wiederzuerkennen. Die Einheit
 *     ist eine Angabe über die Ware, keine Rechengrösse.
 *
 *  3. EIGENE EINHEITEN BLEIBEN MÖGLICH. Eine feste Liste, die etwas nicht
 *     kennt, führt dazu, dass jemand „St" nimmt und die wahre Einheit in die
 *     Bezeichnung schreibt -- dann steht sie an einer Stelle, an der keine
 *     Auswertung sie findet. Der Freitext ist der Ventil dafür, nicht der
 *     Regelfall.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class UnitsOfMeasure
{
    /**
     * Die Vorschläge, nach Art gruppiert.
     *
     * Gruppiert, weil eine flache Liste von zwanzig Kürzeln niemand liest --
     * und weil die Gruppe beim Anlegen die Rückfrage beantwortet, ob ein Teil
     * nach Länge oder nach Stück geführt wird.
     *
     * @return array<string, array<string, string>>
     */
    public static function grouped(): array
    {
        return [
            'count' => [
                'St' => 'Stück',
                'Paar' => 'Paar',
                'Satz' => 'Satz',
            ],
            'length' => [
                'mm' => 'Millimeter',
                'cm' => 'Zentimeter',
                'm' => 'Meter',
                'in' => 'Zoll',
                'ft' => 'Fuß',
            ],
            'area' => [
                'cm²' => 'Quadratzentimeter',
                'm²' => 'Quadratmeter',
            ],
            'volume' => [
                'ml' => 'Milliliter',
                'l' => 'Liter',
                'gal' => 'Gallone (US)',
            ],
            'mass' => [
                'g' => 'Gramm',
                'kg' => 'Kilogramm',
                'lb' => 'Pfund (lbs)',
            ],
        ];
    }

    /**
     * Alle bekannten Kürzel, flach.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return array_merge(...array_values(self::grouped()));
    }

    public static function isKnown(string $unit): bool
    {
        return array_key_exists($unit, self::all());
    }

    /**
     * Was in der Oberfläche steht: Kürzel und ausgeschriebener Name.
     *
     * „St (Stück)" statt nur „St" -- das Kürzel ist das, was im Regal steht,
     * der Name das, was die Frage beantwortet.
     *
     * @return array<string, array<string, string>>
     */
    public static function options(): array
    {
        $gruppen = [];

        foreach (self::grouped() as $gruppe => $einheiten) {
            $beschriftet = [];

            foreach ($einheiten as $kuerzel => $name) {
                $beschriftet[$kuerzel] = sprintf('%s (%s)', $kuerzel, $name);
            }

            $gruppen[__('warehouse.unit_group.'.$gruppe)] = $beschriftet;
        }

        return $gruppen;
    }

    /**
     * Die Einheiten, die in diesem Bestand tatsächlich vorkommen.
     *
     * Also die bekannten PLUS die selbst eingetragenen -- damit eine eigene
     * Einheit nach dem Speichern nicht aus der Auswahl verschwindet und beim
     * nächsten Bearbeiten stillschweigend durch eine andere ersetzt wird.
     *
     * @return array<string, array<string, string>>
     */
    public static function optionsIncluding(?string $current): array
    {
        $gruppen = self::options();

        if ($current === null || $current === '' || self::isKnown($current)) {
            return $gruppen;
        }

        $gruppen[__('warehouse.unit_group.own')] = [$current => $current];

        return $gruppen;
    }
}
