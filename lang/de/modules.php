<?php

declare(strict_types=1);

return [

    'page' => [
        'title' => 'Module',
        'subheading' => 'Was in dieser Installation läuft. Deaktivieren blendet ein Modul aus '
            .'und stoppt seine Hintergrundarbeit — die Daten bleiben erhalten.',
        'none' => 'Diese Ausgabe enthält keine Module.',
    ],

    'state' => [
        'enabled' => 'aktiv',
        'disabled' => 'inaktiv',
    ],

    'action' => [
        'enable' => 'Aktivieren',
        'disable' => 'Deaktivieren',
    ],

    'confirm' => [
        'disable' => 'Modul deaktivieren? Die Funktionen verschwinden aus der Oberfläche, '
            .'die Daten bleiben unverändert erhalten.',
    ],

    'needs' => 'Benötigt: :modules',
    'will_also_enable' => 'Wird mit aktiviert: :modules — ohne diese Module ist das '
        .'gewählte Modul nicht lauffähig.',

    'notification' => [
        'enabled' => 'Modul aktiviert.',
        'disabled' => 'Modul deaktiviert.',
        'also_enabled' => 'Mit aktiviert: :modules',
        'data_kept' => 'Die Daten des Moduls bleiben erhalten und stehen nach einer '
            .'erneuten Aktivierung wieder zur Verfügung.',
        'refused' => 'Das geht so nicht',
        'warning_title' => 'Bitte beachten',
    ],

    'warning' => [
        'duplicate_capability' => 'Mehrere Module bieten :capability an (:modules). '
            .'Das ist zulässig, führt aber dazu, dass Mitgliederdaten aus mehreren '
            .'Quellen stammen — dieselbe Person kann dabei doppelt angelegt werden.',
    ],

    'capability' => [
        'identity-provider' => 'die Anmeldung und den Mitgliederabgleich',
        'document-storage' => 'die Dokumentenablage',
    ],

];
