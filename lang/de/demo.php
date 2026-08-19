<?php

declare(strict_types=1);

return [
    'uploads_disabled' => 'In der Demo sind Dateiuploads abgeschaltet. Beispieldokumente '
        .'sind hinterlegt — im Betrieb hängst du hier deine eigenen Dateien an.',

    'banner' => [
        'title' => 'Demo.',
        'body' => 'Alles hier ist erfunden, alles darf ausprobiert werden.',
        'next_reset' => '{0}Reset läuft in Kürze|{1}Reset in einer Stunde|[2,*]Reset in :hours Stunden',
    ],

    'accounts' => [
        'lead' => 'Zum Ausprobieren — jede Anmeldung zeigt eine andere Sicht:',
        'password' => 'Passwort für alle: :password',
    ],

    'mail' => [
        'disabled' => 'In der Demo geht keine Mail hinaus, und es lässt sich auch kein '
            .'Mailserver einrichten. Alles Übrige funktioniert; nur der Versand ist aus.',
    ],

    'credentials_disabled' => 'In der Demo werden keine Zugangsdaten gespeichert. Die '
        .'Anbindungen sind als Beispiel angelegt und fragen nie einen fremden Server.',

    'account_locked' => 'Die Demokonten lassen sich nicht ändern — sonst wäre die Demo nach '
        .'dem ersten Besucher für alle anderen zu.',

    'upload_refused' => 'Uploads sind in dieser Demo abgeschaltet.',

    'fetch_limited' => 'In der Demo sind Handabrufe begrenzt (:limit pro Stunde für die '
        .'ganze Instanz). Der Schutz gilt dem Hersteller, dessen Server sonst für jeden '
        .'Besucher einzeln befragt würde. Nächster Versuch in :minutes Minuten.',

    'reset' => [
        'action' => 'Demo jetzt zurücksetzen',
        'confirm' => 'Alle eingetragenen Daten werden gelöscht und der Beispielbestand neu '
            .'aufgesetzt. Das dauert einen Moment.',
        'done' => 'Die Demo steht wieder auf Anfang.',
        'failed' => 'Der Reset ist gescheitert.',
    ],
];
