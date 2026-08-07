<?php

declare(strict_types=1);

return [

    'module' => [
        'title' => 'Erfahrungslogbuch',
        'description' => 'Erfahrungsnachweis nach Part-66, abgeleitet aus den '
            .'Arbeitskarten. Führt nichts eigenes — es liest.',
    ],

    'log' => [
        'title' => 'Erfahrungslogbuch',
        'subheading' => 'Abgeleitet aus den Arbeitskarten. Keine zweite Pflege.',
        'mine' => 'Mein Logbuch',
        'person' => 'Person',
        'from' => 'von',
        'until' => 'bis',
        'nothing' => 'Keine Einträge im gewählten Zeitraum.',
        'print' => 'Logbuch drucken',
        'field' => [
            'date' => 'Datum',
            'registration' => 'Kennzeichen',
            'model' => 'Muster',
            'ata' => 'ATA',
            'activity' => 'Tätigkeit',
            'duration' => 'Dauer',
            'participation' => 'Mitwirkung',
            'card' => 'Karte',
            'work' => 'Ausgeführte Arbeit',
            'certified_by' => 'Abgezeichnet von',
            'release' => 'Freigabe',
        ],
        'provisional' => 'vorläufig',
        'help' => [
            'derived' => 'Diese Liste wird aus den Arbeitskarten berechnet und nirgends '
                .'zusätzlich gespeichert. Eine zweite Fassung wäre eine zweite Wahrheit — '
                .'und beim ersten Auseinanderlaufen wüsste niemand, welche gilt.',
            'provisional' => 'Solange ein Vorgang nicht freigegeben ist, können sich '
                .'seine Karten noch ändern. Solche Zeilen sind als vorläufig '
                .'gekennzeichnet. Nach der Freigabe ist alles eingefroren — deshalb ist '
                .'ein berechnetes Logbuch überhaupt verlässlich.',
            'own' => 'Das eigene Logbuch darf jeder sehen. Für fremde braucht es eine '
                .'Berechtigung — es ist eine persönliche Aufzeichnung darüber, wie jemand '
                .'seine Samstage verbringt.',
        ],
    ],

    'summary' => [
        'title' => 'Zusammenfassung',
        'total_hours' => 'Gesamtstunden',
        'entries' => 'Einträge',
        'span' => 'Zeitraum',
        'by_activity' => 'Nach Tätigkeitsart',
        'by_model' => 'Nach Muster',
        'by_participation' => 'Nach Mitwirkung',
        'certifications' => 'Abgezeichnete Karten',
        'releases' => 'Erteilte Freigaben',
        'reviews' => 'Lufttüchtigkeitsprüfungen',
    ],

    'recency' => [
        'title' => 'Aktualität (66.A.20(b))',
        'window' => 'Letzte :months Monate',
        'days' => 'Tage mit Arbeit',
        'months' => 'Monate mit Arbeit',
        'hours' => 'Stunden',
        'last_worked' => 'Letzte Arbeit',
        'gap' => 'Seit :days Tagen keine Arbeit',
        'nothing_in_window' => 'In den letzten :months Monaten ist keine Arbeit erfasst.',
        'few_months' => 'Arbeit in :months Monaten erfasst. 66.A.20(b) spricht von sechs '
            .'Monaten Erfahrung in zwei Jahren — was das für ehrenamtliche Arbeit heißt, '
            .'ist damit nicht entschieden.',
        'long_gap' => 'Seit :days Tagen ist keine Arbeit erfasst.',
        'provisional' => ':count Einträge stammen aus noch nicht freigegebenen Vorgängen '
            .'und können sich noch ändern.',
        'arcs' => 'Anzahl der Lufttüchtigkeitsprüfungen und die Stunden dahinter — '
            .'die Übersicht, die für Part-66-Inhaber von Interesse ist. Welche Zahl eine '
            .'ARS-Berechtigung erhält, entscheidet auch hier nicht das Werkzeug.',
        'help' => [
            'no_verdict' => 'Hier stehen Zahlen, keine Beurteilung. 66.A.20(b) verlangt '
                .'sechs Monate Erfahrung in zwei Jahren — bei Anstellung ist das klar, '
                .'bei drei Samstagen im Monat nicht: sechs Monate wovon? Kalendermonate? '
                .'Anwesenheitstage? Stunden umgerechnet? Das entscheidet die Vorschrift '
                .'nicht, und eine hier erfundene Zahl wäre schlimmer als keine — jemand '
                .'würde sich darauf verlassen.',
            'counts_days' => 'Gezählt werden Tage, nicht Einträge: Drei Karten an einem '
                .'Samstag sind ein Tag Erfahrung.',
        ],
    ],

];
