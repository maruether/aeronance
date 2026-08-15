<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\Weighings\Pages;

use App\Modules\Fleet\Filament\Resources\Weighings\WeighingResource;
use App\Modules\Fleet\Models\WeighingEntry;
use Filament\Resources\Pages\CreateRecord;

final class CreateWeighing extends CreateRecord
{
    protected static string $resource = WeighingResource::class;

    /**
     * Works the sheet out and writes the answer down.
     *
     * Stored rather than left to be recomputed: a signed document keeps its
     * numbers, and recalculating a 2019 report with 2027 code would republish
     * somebody's signature over a different answer.
     */
    protected function afterCreate(): void
    {
        /*
         * Die Auflagen gehören zum Blatt, nicht zur Fleißarbeit.
         *
         * Der zweite Anlegeweg neben „Aus der letzten Wägung" -- und er lief
         * an PrepareWeighing vorbei, also entstand hier ein Blatt ganz ohne
         * Auflagen. Ohne die zeichnet keine Skizze, und genau das war zu
         * sehen (Feldtest, dreimal: "grafiken ... fehlen immer noch").
         *
         * Nur wenn noch keine da sind: Wer sie im Anlegeformular schon
         * eingetragen hat, bekommt sie nicht doppelt.
         */
        $this->record->load('entries');

        if ($this->record->entriesOf(WeighingEntry::SECTION_SUPPORT)->isEmpty()) {
            foreach ($this->record->kind->defaultSupports() as $position => $label) {
                WeighingEntry::create([
                    'weighing_id' => $this->record->id,
                    'section' => WeighingEntry::SECTION_SUPPORT,
                    'label' => $label,
                    'position' => $position,
                ]);
            }

            $this->record->load('entries');
        }

        $this->record->recalculate();
    }
}
