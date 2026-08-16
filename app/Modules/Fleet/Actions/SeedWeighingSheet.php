<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Actions;

use App\Modules\Fleet\Enums\SheetVariant;
use App\Modules\Fleet\Enums\Undercarriage;
use App\Modules\Fleet\Models\Weighing;
use App\Modules\Fleet\Models\WeighingEntry;

/**
 * Das leere Blatt so vorbereiten, wie das Papier gedruckt ist.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Feldtest: „Ich will das BWLV Formular quasi 1:1 haben zum digital ausfüllen."
 *
 * Ein Papierblatt kommt mit seinen Zeilen. Wer es ausfüllt, trägt Zahlen ein --
 * er schreibt nicht erst „Tragwerk rechts innen" ab. Genau das verlangte diese
 * Maske aber: ein leeres Wiederholfeld und ein Knopf „Hinzufügen".
 *
 * Die Vorlagen dafür gab es seit jeher (WeighingKind::defaultComponents und
 * defaultDeductions) -- und **niemand rief sie auf**. Zwei fertig gebaute
 * Listen, die nie ein Nutzer gesehen hat, weil die Zeile fehlte, die sie
 * anlegt.
 *
 * AN EINER STELLE, weil es zwei Anlegewege gibt: „Neu" und „Aus der letzten
 * Wägung". Der zweite hatte die Auflagen, der erste nicht -- so entstanden
 * Blätter ohne Auflagen, und ohne Auflagen zeichnet keine Skizze. Genau das
 * war der gemeldete Fehler.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SeedWeighingSheet
{
    /**
     * Fehlende Vorlagenzeilen ergänzen -- vorhandene bleiben unangetastet.
     *
     * Je Abschnitt einzeln geprüft: Wer die Auflagen im Anlegeformular schon
     * eingetragen hat, soll sie nicht doppelt bekommen, und trotzdem die
     * Bauteilzeilen erhalten.
     */
    public function handle(Weighing $weighing): Weighing
    {
        $weighing->load('entries');

        $variante = $weighing->sheet_variant ?? SheetVariant::Glider;
        $fahrwerk = $weighing->undercarriage ?? Undercarriage::defaultFor($variante);

        $this->fill($weighing, WeighingEntry::SECTION_SUPPORT, $fahrwerk->supports());
        $this->fill($weighing, WeighingEntry::SECTION_COMPONENT, $variante->kind()->defaultComponents());

        $this->fill(
            $weighing,
            WeighingEntry::SECTION_DEDUCTION,
            array_column($variante->kind()->defaultDeductions(), 'label'),
            array_column($variante->kind()->defaultDeductions(), 'density'),
        );

        return $weighing->fresh(['entries']) ?? $weighing;
    }

    /**
     * @param  list<string>  $labels
     * @param  list<float>  $densities  parallel zu $labels, für die Abzugszeilen
     */
    private function fill(Weighing $weighing, string $section, array $labels, array $densities = []): void
    {
        if ($labels === [] || $weighing->entriesOf($section)->isNotEmpty()) {
            return;
        }

        foreach ($labels as $position => $label) {
            WeighingEntry::create([
                'weighing_id' => $weighing->id,
                'section' => $section,
                'label' => $label,
                'position' => $position,
                // Die Dichten stehen auf dem Blatt selbst -- sie einzutippen
                // wäre Abschreiben von etwas, das schon gedruckt ist.
                'density_kg_per_litre' => $densities[$position] ?? null,
            ]);
        }
    }
}
