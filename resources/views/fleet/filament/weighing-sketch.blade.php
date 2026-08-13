{{--
    Die Hebelskizze IN DER MASKE -- Feldtest: "bei den wägungen will ich die
    grafik nicht nur beim drucken, sondern auch in der maske haben."

    Dieselbe Zeichnung wie auf dem Papier (fleet.print._weighing_sketch),
    aus denselben gespeicherten Werten abgeleitet. Auf weißem Grund auch im
    Dunkelmodus: Die Skizze IST das Formularblatt, schwarz auf weiß -- sie
    umzufärben hieße, etwas anderes zu zeigen als das, was gedruckt wird.

    Erscheint erst, wenn die Zwei-Punkt-Lage gespeichert ist: zwei Auflagen
    im Datensatz. Vorher gibt es ehrlich nichts zu zeichnen -- ein leeres
    Achsenkreuz erklärte niemandem etwas.
--}}
@php($weighing = $getRecord())

@if ($weighing !== null)
    @php($stuetzen = $weighing->entriesOf('support')->values())

    @if ($stuetzen->count() === 2)
        @php($result = $weighing->result())
        @php($fmt = fn (?float $v, int $d = 2) => $v === null ? '' : number_format($v, $d, ',', '.'))

        <div class="rounded-xl bg-white p-4 ring-1 ring-gray-200 dark:ring-white/10">
            @include('fleet.print._weighing_sketch', [
                'a' => $weighing->front_support_arm_mm !== null ? (float) $weighing->front_support_arm_mm : 0.0,
                'b' => $weighing->support_distance_mm !== null ? (float) $weighing->support_distance_mm : null,
                'g1' => (float) $stuetzen[0]->netto(),
                'g2' => (float) $stuetzen[1]->netto(),
                'g' => (float) $stuetzen[0]->netto() + (float) $stuetzen[1]->netto(),
                'x' => $result->emptyCgMm !== null ? (float) $result->emptyCgMm : null,
                'fmt' => $fmt,
            ])
        </div>
    @endif
@endif
