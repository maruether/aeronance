<?php

declare(strict_types=1);

return [

    'development_build' => 'Entwicklungsstand',
    'unknown' => 'unbekannt',

    'current' => 'Installierte Fassung',
    'latest' => 'Neueste Fassung',

    'available' => 'Version :version ist verfügbar.',
    'up_to_date' => 'Die Installation ist aktuell.',
    'no_answer' => 'Keine Auskunft — :repository war nicht erreichbar oder hat noch '
        .'keine Veröffentlichung.',
    'disabled' => 'Die Aktualisierungsprüfung ist abgeschaltet.',
    'no_version' => 'Diese Installation kennt ihre eigene Fassung nicht (keine '
        .'VERSION-Datei). Das ist der Entwicklungsstand oder ein von Hand '
        .'ausgechecktes Repository — verglichen wird dann nichts.',

    'widget' => [
        'title' => 'Version :version ist verfügbar.',
        'installed' => 'Installiert ist :version.',
        'notes' => 'Was ist neu?',
    ],

    'how_to' => 'Eingespielt wird je nach Installationsart: eigener Server mit '
        .'deploy/update.sh, Docker mit einem neuen Image, LXC mit dem '
        .'Update-Skript.',

];
