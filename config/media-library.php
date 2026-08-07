<?php

declare(strict_types=1);

/*
 * Nur die zwei Werte, die uns wichtig sind.
 *
 * Das Paket registriert seine Konfiguration ueber mergeConfigFrom, also gewinnen
 * die hier gesetzten Schluessel und alles Uebrige kommt weiter aus dem Paket.
 * Eine vollstaendig veroeffentlichte Konfiguration muesste bei jedem Update
 * abgeglichen werden; diese hier nicht.
 */
return [

    /*
     * Der Paket-Standard ist 'public' -- und die public-Disk liegt hinter dem
     * Symlink public/storage, ist also per URL abrufbar. Fuer die Nachweise am
     * Los faellt das nicht auf, weil die Collection ihre Disk selbst setzt; die
     * naechste Collection, die das vergisst, laege im Web. Deshalb steht der
     * Standard hier auf der privaten Disk: Wer oeffentlich ablegen will, muss
     * das ausdruecklich sagen.
     */
    'disk_name' => env('MEDIA_DISK', 'documents'),

    /*
     * Muss zum Limit des Upload-Feldes passen.
     *
     * Vorher standen hier 10 MB (Paket-Standard) gegen 20 MB im Formular: Eine
     * Datei dazwischen kam durch die Validierung und flog danach mitten im
     * Buchungsvorgang mit FileIsTooBig auf die Nase. Beide lesen jetzt dieselbe
     * Env-Variable -- absichtlich ueber env() und nicht ueber config(), weil
     * Konfigurationsdateien einander waehrend des Ladens nicht zuverlaessig
     * lesen koennen.
     */
    'max_file_size' => ((int) env('AERONANCE_DOCUMENT_MAX_MB', 20)) * 1024 * 1024,

];
