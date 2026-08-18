<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Actions;

use App\Modules\Fleet\Enums\SheetVariant;
use App\Modules\Fleet\Enums\Undercarriage;
use App\Modules\Fleet\Models\Weighing;
use App\Modules\Fleet\Models\WeighingEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ein angefangenes Blatt auf die richtige Blattart umstellen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WARUM ES DAS GEBEN MUSS. Wer sein Blatt vor dieser Fassung angelegt hat, hat
 * womöglich ein Segelflugblatt für ein Flugzeug -- genau der gemeldete Fall
 * („für die D-EICC ... die massenübersicht segelflugzeug"). Ohne einen Weg
 * zurück bliebe nur: löschen und neu anlegen. Eine Reparatur, die für
 * vorhandene Daten nicht gilt, ist für den, der sie gemeldet hat, keine.
 *
 * WAS MIT DEN ZEILEN PASSIERT, und die Regel ist bewusst grob:
 *
 *   Ein Abschnitt, in dem KEINE Zahl steht, ist noch die Vorlage -- er wird
 *   durch die Vorlage der neuen Blattart ersetzt. Steht irgendwo in ihm eine
 *   Zahl, bleibt der ganze Abschnitt unangetastet.
 *
 * Zeilenweise zu entscheiden wäre feiner und schlechter: Man bekäme einen
 * Abschnitt aus zwei Vorlagen -- ein Hauptrad von links, ein Bugrad von rechts
 * -- und niemand könnte hinterher sagen, welche Wägepunkte gemeint waren.
 * Gewogene Zahlen werden nie stillschweigend weggeworfen; was bleibt, wird
 * gemeldet, damit der Ausfüllende es selbst richten kann.
 *
 * Die Dichte zählt NICHT als Zahl: Sie steht schon auf dem Papier und wird von
 * der Vorlage mitgeliefert. Sonst wäre jeder frisch angelegte Abzugsabschnitt
 * „ausgefüllt", ohne dass ihn jemand angefasst hat.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SwitchSheetVariant
{
    /** Spalten, deren Inhalt jemand gemessen oder abgeschrieben hat. */
    private const FIGURES = [
        'mass_kg', 'non_lifting_kg', 'gross_kg', 'tare_kg', 'arm_mm',
        'volume_litres', 'max_mass_kg', 'useful_load_kg', 'cg_from_mm', 'cg_to_mm',
    ];

    /** Abschnitte, die zur Blattart gehören -- Sitzplätze nicht, die gelten für beide. */
    private const SECTIONS = [
        WeighingEntry::SECTION_SUPPORT,
        WeighingEntry::SECTION_COMPONENT,
        WeighingEntry::SECTION_DEDUCTION,
        WeighingEntry::SECTION_CONFIGURATION,
    ];

    /**
     * @return list<string> die Abschnitte, die wegen eingetragener Zahlen
     *                      stehen geblieben sind
     */
    public function handle(Weighing $weighing, SheetVariant $variant, Undercarriage $undercarriage): array
    {
        if ($weighing->isSignedOff()) {
            // Das Model wirft ohnehin; hier steht der Grund in der Sprache der
            // Fachlichkeit statt als Datenbankfehler.
            throw new RuntimeException('Ein abgezeichnetes Blatt wird nicht umgestellt.');
        }

        return DB::transaction(function () use ($weighing, $variant, $undercarriage): array {
            $weighing->load('entries');

            $geblieben = [];

            foreach (self::SECTIONS as $abschnitt) {
                $zeilen = $weighing->entriesOf($abschnitt);

                if ($zeilen->isEmpty()) {
                    continue;
                }

                if ($this->hasFigures($zeilen)) {
                    $geblieben[] = $abschnitt;

                    continue;
                }

                $zeilen->each(fn (WeighingEntry $zeile) => $zeile->delete());
            }

            $weighing->update([
                'sheet_variant' => $variant,
                'kind' => $variant->kind(),
                'undercarriage' => $undercarriage,
            ]);

            app(SeedWeighingSheet::class)->handle($weighing->fresh(['entries']) ?? $weighing);

            return $geblieben;
        });
    }

    /** @param  Collection<int, WeighingEntry>  $zeilen */
    private function hasFigures(Collection $zeilen): bool
    {
        foreach ($zeilen as $zeile) {
            foreach (self::FIGURES as $spalte) {
                if ($zeile->{$spalte} !== null) {
                    return true;
                }
            }
        }

        return false;
    }
}
