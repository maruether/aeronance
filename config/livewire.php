<?php

declare(strict_types=1);

return [
    /*
     * ─────────────────────────────────────────────────────────────────────────
     * DAS HOCHLADELIMIT, und warum es diese Datei überhaupt gibt.
     *
     * Livewires eingebaute Regel ist `max:12288` -- zwölf Megabyte. Die
     * Formulare dieser Anwendung versprechen aber 20 (aeronance.documents
     * .max_size_mb), Nginx erlaubt 32 und PHP ebenso. Ein Wartungshandbuch
     * liegt genau in dem Bereich dazwischen: Der Upload lief los, brach ohne
     * verständliche Meldung ab, und die Anwendung sah aus, als könne sie
     * keine Dateien.
     *
     * Feldtest, zweimal gemeldet: "ich kann immer noch keine
     * Wartungsunterlagen hochladen."
     *
     * Deshalb HIER dieselbe Zahl wie in den Formularen -- eine Grenze, die an
     * zwei Stellen verschieden ist, ist keine Grenze, sondern eine Falle.
     * ─────────────────────────────────────────────────────────────────────────
     */
    'temporary_file_upload' => [
        'rules' => ['required', 'file', 'max:'.((int) env('AERONANCE_DOCUMENT_MAX_MB', 20) * 1024)],
    ],
];
