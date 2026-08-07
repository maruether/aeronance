<?php

declare(strict_types=1);

return [

    'module' => [
        'title' => 'Eingangsprüfung',
        'description' => 'Angelieferte Ware wird geprüft, bevor sie verfügbar ist: '
            .'Papiere, Kennzeichnung, Zustand. Bis die Prüfung unterschrieben ist, '
            .'bleibt das Los gesperrt.',
    ],

    'singular' => 'Eingangsprüfung',
    'plural' => 'Eingangsprüfungen',

    'unknown_part' => 'Unbekanntes Teil',

    'state' => [
        'open' => 'Offen',
        'accepted' => 'Angenommen',
        'rejected' => 'Zurückgewiesen',
    ],

    'result' => [
        'pass' => 'In Ordnung',
        'fail' => 'Beanstandet',
        'not_applicable' => 'Entfällt',
    ],

    'check' => [
        'part_number' => [
            'label' => 'Teilenummer',
            'hint' => 'Stimmt die Teilenummer auf Teil, Bescheinigung und Lieferschein überein? '
                .'Ein einziges abweichendes Zeichen ist der klassische Weg, auf dem ein '
                .'falsches Bauteil ins Regal kommt.',
        ],
        'quantity' => [
            'label' => 'Menge',
            'hint' => 'Stimmt die gelieferte Menge mit Lieferschein und — falls vorhanden — '
                .'Bestellung überein?',
        ],
        'certificate' => [
            'label' => 'Bescheinigung',
            'hint' => 'Liegt die richtige Bescheinigung vor, vollständig und unterschrieben? '
                .'EASA Form 1, FAA 8130-3 oder bei Normteilen die Konformitätserklärung — '
                .'welche die richtige ist, hängt davon ab, was angekommen ist.',
        ],
        'issuer' => [
            'label' => 'Ausstellende Stelle',
            'hint' => 'Ist der Aussteller zur Ausstellung berechtigt, Zulassungsnummer vorhanden '
                .'und plausibel? Eine Bescheinigung ist genau so viel wert wie die Zulassung '
                .'dessen, der sie ausgestellt hat.',
        ],
        'identification' => [
            'label' => 'Kennzeichnung am Teil',
            'hint' => 'Stimmt die Serien- oder Chargennummer am Teil mit der Bescheinigung '
                .'überein? Das ist die Verbindung zwischen Papier und Metall — fehlt sie, '
                .'hat die Nachweiskette gleich am Anfang eine Lücke.',
        ],
        'condition' => [
            'label' => 'Zustand und Verpackung',
            'hint' => 'Transportschaden, Verpackung, Konservierung, ESD-Schutz.',
        ],
        'shelf_life' => [
            'label' => 'Restlaufzeit',
            'hint' => 'Bleibt genug Lagerzeit übrig, um das Teil sinnvoll zu verwenden?',
        ],
    ],

    'hold_reason' => 'Eingangsprüfung ausstehend',
    'release_reason' => 'Eingangsprüfung bestanden',

    'refused' => [
        'already_decided' => 'Diese Eingangsprüfung ist bereits abgeschlossen (:state). '
            .'Eine Korrektur wird als neuer Eintrag festgehalten, nicht durch Ändern des alten.',
        'unanswered' => 'Es sind noch Punkte offen. Eine Eingangsprüfung mit Lücken '
            .'unterschreibt man nicht — auch nicht bei einer Zurückweisung.',
        'note_missing' => 'Zu „:item" fehlt die Bemerkung. Beanstandet oder entfällt ohne '
            .'Begründung ist von „nicht hingeschaut" nicht zu unterscheiden.',
        'accept_despite_failure' => 'Es gibt Beanstandungen. Annehmen ist möglich — eine '
            .'gedellte Verpackung um ein einwandfreies Teil kommt vor —, aber bitte mit '
            .'Begründung.',
        'reject_without_reason' => 'Zurückweisen braucht einen Grund. Ohne ihn ist die '
            .'Lieferung in einem halben Jahr eine offene Frage.',
    ],

    'field' => [
        'part' => 'Teil',
        'lot' => 'Los',
        'quantity' => 'Menge',
        'arrived_at' => 'Angekommen',
        'state' => 'Zustand',
        'decided_by' => 'Geprüft von',
        'decided_at' => 'Geprüft am',
        'decision_note' => 'Bemerkung',
        'result' => 'Ergebnis',
        'note' => 'Bemerkung',
        'item' => 'Prüfpunkt',
        'origin' => 'Herkunft',
    ],

    'decision' => [
        'heading' => 'Entscheidung',
        'hint' => 'Erst die Liste, dann die Entscheidung — in dieser Reihenfolge, '
            .'sonst füllt man die Liste passend zur schon getroffenen Entscheidung aus.',
        'accept_releases' => 'Die Sperre fällt, das Los ist ab sofort verwendbar. '
            .'Setzt die Freigabeberechtigung voraus.',
        'accept_records' => 'Wird festgehalten. Sammelbestand ohne Los ist bereits verfügbar.',
        'reject_holds' => 'Die Ware bleibt gesperrt. Was mit ihr geschieht, ist ein '
            .'eigener Vorgang im Lager.',
        'note_hint' => 'Pflicht beim Zurückweisen und beim Annehmen trotz Beanstandung.',
    ],

    'action' => [
        'inspect' => 'Prüfen',
        'sign' => 'Unterschreiben',
        'accept' => 'Annehmen',
        'reject' => 'Zurückweisen',
        'accepted' => 'Angenommen — das Los ist freigegeben.',
        'accepted_bulk' => 'Angenommen und festgehalten.',
        'rejected' => 'Zurückgewiesen. Die Ware bleibt gesperrt.',
        'failed' => 'Nicht möglich',
    ],

    'filter' => [
        'open' => 'Nur offene',
        'state' => 'Zustand',
    ],

    'since' => 'seit :days Tagen',

    'empty' => [
        'heading' => 'Keine Eingangsprüfungen',
        'description' => 'Sobald etwas ins Lager gebucht wird, entsteht hier ein Eintrag.',
    ],

];
