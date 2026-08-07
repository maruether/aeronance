<?php

declare(strict_types=1);

return [

    'module' => [
        'title' => 'Werkzeuge',
        'description' => 'Werkzeugbestand mit Kalibrierfristen. Hält fest, wann etwas '
            .'wieder zur Kalibrierung muss — und wenn es zu spät kam, wie lange die '
            .'Genauigkeit nicht belegt war.',
    ],

    'singular' => 'Werkzeug',
    'plural' => 'Werkzeuge',

    'state' => [
        'in_service' => 'In Benutzung',
        'out_of_service' => 'Gesperrt',
        'lost' => 'Verschollen',
        'retired' => 'Ausgesondert',
    ],

    'result' => [
        'in_tolerance' => 'In Toleranz',
        'out_of_tolerance' => 'Außer Toleranz',
        'unknown' => 'Kein Befund',
    ],

    'gap_reason' => [
        'overdue' => 'Zu spät kalibriert',
        'out_of_tolerance' => 'Werkzeug war außer Toleranz',
    ],

    'field' => [
        'inventory_number' => 'Inventarnummer',
        'name' => 'Bezeichnung',
        'manufacturer' => 'Hersteller',
        'model' => 'Typ',
        'serial_number' => 'Seriennummer',
        'location' => 'Aufbewahrungsort',
        'state' => 'Zustand',
        'calibration_required' => 'Kalibrierpflichtig',
        'calibration_interval_months' => 'Intervall (Monate)',
        'calibration_basis' => 'Grundlage des Intervalls',
        'result' => 'Befund',
        'calibration_due_at' => 'Kalibrierung fällig',
        'note' => 'Bemerkung',
        'performed_at' => 'Gemessen am',
        'valid_until' => 'Gültig bis',
        'provider' => 'Kalibrierstelle',
        'certificate_reference' => 'Scheinnummer',
        'certificate' => 'Kalibrierschein',
        'gap' => 'Lücke',
        'gap_review_note' => 'Bewertung',
        'reviewed_by' => 'Bewertet von',
        'recorded_by' => 'Eingetragen von',
    ],

    'help' => [
        'calibration_required' => 'Nur für Werkzeuge, deren Genauigkeit zählt — '
            .'Drehmomentschlüssel, Messuhren, Prüfgeräte. Ein Schraubendreher '
            .'gehört nicht dazu; stünde er hier, wäre die Warnliste nach einer '
            .'Woche Hintergrundrauschen.',
        'interval' => 'Wird beim Eintragen eines Kalibrierscheins verwendet, wenn der '
            .'Schein selbst kein Gültigkeitsdatum nennt.',
        'basis' => 'Woher das Intervall stammt — Herstellervorgabe oder Norm, etwa '
            .'„EN ISO 6789: 12 Monate oder 5.000 Betätigungen". Betätigungen zählt '
            .'Aeronance bewusst nicht mit; wo diese Grenze zuerst greifen könnte, '
            .'setzt man das Zeitintervall entsprechend kürzer.',
        'result' => 'Wie das Werkzeug beim Labor ANKAM, vor einer etwaigen Justage. '
            .'Davon hängt ab, ob zurückliegende Arbeit nachzuprüfen ist.',
        'valid_until' => 'Leer lassen, dann rechnet das Intervall des Werkzeugs.',
        'gap' => 'Zeitraum, dessen Arbeit in Frage steht. Bei „außer Toleranz" reicht er '
            .'zurück bis zur letzten Messung mit gutem Befund — ab wann das Werkzeug '
            .'abgewichen ist, weiß niemand. Bei „zu spät kalibriert" nur ab dem '
            .'Fälligkeitsdatum. Was in dieser Zeit damit gearbeitet wurde, gehört '
            .'angesehen (145.A.40, EASA-FAQ 116318).',
    ],

    'due' => [
        'overdue' => 'Überfällig',
        'soon' => 'Läuft ab',
        'never' => 'Noch nie kalibriert',
        'ok' => 'Gültig',
        'days' => 'seit :days Tagen',
        'in_days' => 'in :days Tagen',
    ],

    'gap' => [
        'open' => 'Bewertung offen',
        'reviewed' => 'Bewertet',
        'length' => ':days Tage ohne belegte Genauigkeit',
        'none' => 'Lückenlos',
    ],

    'issue' => [
        'heading' => 'Ausgabe',
        'out' => 'Draußen bei :name',
        'since' => 'seit :days Tagen',
        'available' => 'Im Regal',
        'refused' => [
            'already_out' => 'Dieses Werkzeug ist bereits ausgegeben (:name). Erst '
                .'zurücknehmen, dann neu ausgeben.',
            'not_usable' => 'Das Werkzeug ist als „:state" geführt und wird nicht '
                .'ausgegeben.',
            'calibration_overdue' => 'Die Kalibrierung ist überfällig (:date). Damit wird '
                .'nicht gearbeitet — das ist der einzige Zeitpunkt, an dem die Sperre noch '
                .'etwas nützt.',
            'due_in_the_past' => 'Das Rückgabedatum liegt in der Vergangenheit.',
            'already_back' => 'Diese Ausgabe ist bereits zurückgenommen.',
        ],
        'help' => [
            'work_order' => 'Woran gearbeitet wird. Nicht Pflicht — aber genau diese Angabe '
                .'beantwortet später die Frage, welche Arbeit nachzuprüfen ist, falls das '
                .'Werkzeug bei der nächsten Kalibrierung durchfällt.',
            'due_back' => 'Optional. Die meisten Ausgaben dauern einen Nachmittag.',
        ],
    ],

    'field_issue' => [
        'issued_to' => 'Ausgegeben an',
        'issued_at' => 'Ausgegeben am',
        'due_back_at' => 'Zurück bis',
        'work_order_reference' => 'Vorgang',
        'returned_at' => 'Zurück am',
    ],

    'action' => [
        'issue' => 'Ausgeben',
        'return' => 'Zurücknehmen',
        'issued' => 'Ausgegeben.',
        'returned' => 'Zurückgenommen.',
        'calibrate' => 'Kalibrierung eintragen',
        'calibrated' => 'Kalibrierung eingetragen.',
        'review_gap' => 'Lücke bewerten',
        'reviewed' => 'Bewertung festgehalten.',
        'failed' => 'Nicht möglich',
    ],

    'filter' => [
        'issued' => 'Nur ausgegebene',
        'overdue' => 'Nur überfällige',
        'open_gaps' => 'Nur offene Bewertungen',
        'state' => 'Zustand',
    ],

    'refused' => [
        'future_calibration' => 'Eine Messung aus der Zukunft ist ein Tippfehler — und einer, '
            .'der die Fälligkeit um Jahre verschiebt.',
        'validity_backwards' => 'Das Gültigkeitsdatum liegt vor der Messung.',
        'no_gap' => 'Zu dieser Kalibrierung gibt es keine Lücke.',
        'review_without_note' => 'Eine Bewertung ohne Text ist keine. Was wurde angesehen, '
            .'und mit welchem Ergebnis?',
    ],

    'empty' => [
        'heading' => 'Keine Werkzeuge',
        'description' => 'Angelegt wird, was kalibriert werden muss oder was man wiederfinden '
            .'können will.',
    ],

];
