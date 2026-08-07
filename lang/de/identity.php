<?php

declare(strict_types=1);

return [
    'mapping' => [
        'singular' => 'Rollenzuordnung',
        'plural' => 'Rollenzuordnungen',

        'field' => [
            'provider' => 'Quelle',
            'kind' => 'Art',
            'value' => 'Externer Wert',
            'group' => 'Funktion beim Anbieter',
            'group_manual' => 'Funktion von Hand eintragen',
            'subject' => 'Kennung der Person',
            'role' => 'Rolle in Aeronance',
            'created' => 'Angelegt',
        ],

        'kind' => [
            'group' => 'Funktion',
            'user' => 'Einzelne Person',
        ],

        'help' => [
            'kind' => 'Eine Funktion wirkt auf alle, die sie tragen -- auch auf die, '
                .'die sie erst morgen bekommen. Eine einzelne Person ist die Ausnahme '
                .'und sollte eine bleiben.',
            'subject' => 'Die Kennung, unter der die Quelle diese Person führt -- bei '
                .'Vereinsflieger die UID, nicht der Anmeldename.',
            'group' => 'Angeboten wird, was beim letzten Abruf gefunden wurde.',
            'group_empty' => 'Noch nichts abgerufen. „Funktionen abrufen" holt die Liste '
                .'aus dem Verein.',
            'group_free' => 'Diese Quelle kann ihre Gruppen nicht aufzählen -- der Wert '
                .'muss von Hand eingetragen werden.',
            'group_manual' => 'Der Wert muss genau so lauten wie beim Anbieter. Eine neu '
                .'angelegte Funktion, die noch niemand trägt, taucht im Abruf nicht auf -- '
                .'dafür ist dieser Weg da.',
            'role' => 'Die Freigabeberechtigung steht bewusst nicht zur Wahl. Sie ist eine '
                .'Qualifikationsaussage mit Lizenznachweis und wird nur hier vergeben, '
                .'gegen Nachweis.',
        ],

        'no_provider' => 'Es ist keine externe Quelle eingerichtet.',
        'empty' => 'Keine Zuordnung eingerichtet',
        'empty_help' => 'Ohne Zuordnung bekommt niemand eine Rolle von außen. Anmelden '
            .'kann man sich trotzdem -- nur eben ohne Rechte.',
    ],

    'group' => [
        'status' => [
            'aktuell' => 'beim letzten Abruf vorhanden',
            'fehlte' => 'beim letzten Abruf NICHT mehr vorhanden -- diese Zuordnung wirkt vermutlich nicht mehr',
            'unbestaetigt' => 'von Hand eingetragen, beim Anbieter noch nicht gesehen',
            'unknown' => 'nicht in der abgerufenen Liste -- Schreibweise prüfen',
        ],
    ],

    'discover' => [
        'action' => 'Funktionen abrufen',
        'confirm' => 'Ruft die Liste der Funktionen beim Anbieter ab. Das ist ein Zugriff '
            .'über das Netz -- bei Vereinsflieger ist die Zahl der Anfragen begrenzt, '
            .'also bitte nicht in Folge drücken.',
        'done' => ':seen Funktionen gefunden, davon :new neu.',
        'failed' => 'Der Abruf ist fehlgeschlagen',
    ],
];
