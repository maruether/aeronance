<?php

declare(strict_types=1);

return [
    'title' => 'Einstellungen',
    'subheading' => 'Alles, was eine Organisation selbst festlegt — ohne eine einzige Datei anzufassen.',
    'save' => 'Speichern',
    /*
     * Der Testversand. Er prueft, was im Formular STEHT, nicht was gespeichert
     * ist -- sonst muesste man erst speichern, um zu erfahren, ob der Zugang
     * stimmt, und haette im Fehlerfall einen kaputten Zugang in der Datenbank.
     */
    'mail_test' => [
        'action' => 'Testmail senden',
        'heading' => 'Testmail senden',
        'description' => 'Verschickt eine Nachricht mit den Angaben, die gerade in '
            .'diesem Abschnitt stehen — auch wenn sie noch nicht gespeichert sind. '
            .'Bleibt das Passwortfeld leer, gilt das hinterlegte.',
        'recipient' => 'An welche Adresse?',
        'not_configured' => 'Kein SMTP-Server eingetragen — ohne ihn verschickt die '
            .'Anwendung nichts.',
        'sent' => 'Abgeschickt an :empfaenger.',
        'sent_hint' => 'Kommt nichts an, liegt es nicht mehr an dieser Anwendung — '
            .'dann beim Empfänger im Spam nachsehen oder beim Anbieter. Fehlende '
            .'SPF-, DKIM- und DMARC-Einträge sind der häufigste Grund.',
        'failed' => 'Der Versand ist fehlgeschlagen. Der Mailserver sagt:',
    ],

    'saved' => 'Einstellungen gespeichert.',
    'reset' => 'zurücksetzen',
    'reset_confirm' => 'Der gespeicherte Wert wird entfernt. Danach gilt wieder die '
        .'Umgebungsvariable, falls eine gesetzt ist — sonst die Vorgabe.',
    'reset_done' => 'Auf Umgebung bzw. Vorgabe zurückgesetzt.',

    'secret_set' => 'Ein Wert ist hinterlegt. Feld leer lassen heisst: unverändert.',
    'from_environment' => 'Kommt derzeit aus der Umgebung (z. B. docker-compose). '
        .'Sobald hier gespeichert wird, gilt der gespeicherte Wert dauerhaft.',

    'group' => [
        'organisation' => 'Organisation',
        'backup' => 'Sicherung',
        'offsite' => 'Auslagerung',
        'retention' => 'Aufbewahrung',
        'operation' => 'Betrieb',
        'mail' => 'E-Mail',
        'vereinsflieger' => 'Vereinsflieger',
    ],

    'group_help' => [
        'organisation' => 'Name, Logo und Zeitzone. Alles drei steht auf jedem Ausdruck.',
        'backup' => 'Ohne Verschlüsselung verlässt keine Sicherung dieses System.',
        'offsite' => 'Der zweite Ort. Eine Sicherung neben der Datenbank auf '
            .'derselben Platte überlebt genau den Fall nicht, für den sie gemacht ist.',
        'retention' => 'Alle Regeln sind ab Werk AUS. Was sie löschen, ist danach fort — '
            .'Freigabeinhalte bleiben davon unberührt.',
        'operation' => 'Grenzwerte und Prüfungen des laufenden Betriebs.',
        'vereinsflieger' => 'Zugang und Rückwege. Welche Rolle jemand bekommt, entscheidet '
            .'die Zuordnung im Kern — die Freigabeberechtigung wird grundsätzlich nicht '
            .'übernommen.',
    ],
];
