<?php

declare(strict_types=1);

return [
    'test' => [
        'subject' => 'Testmail von :organisation',
        'heading' => 'Der Mailversand funktioniert',
        'intro' => 'Diese Nachricht kommt von Aeronance (:organisation).',
        'explains' => 'Wenn Sie sie lesen, erreichen auch Einladungen und '
            .'Passwort-Zurücksetzungen ihre Empfänger.',
        'sent_at' => 'Gesendet am :zeit.',
    ],

    'invitation' => [
        'subject' => 'Ihr Zugang zu :organisation',
        'heading' => 'Willkommen bei :organisation',
        'greeting' => 'Hallo :name,',
        'intro' => 'für Sie wurde ein Konto in der Werkstattverwaltung von '
            .':organisation angelegt. Vergeben Sie hier Ihr Passwort — danach '
            .'können Sie sich anmelden.',
        'button' => 'Passwort vergeben',
        'expires' => '{1}Der Link gilt eine Stunde.|[2,*]Der Link gilt :stunden Stunden.',
        'ignore' => 'Wenn Sie nichts damit anfangen können, ignorieren Sie diese '
            .'Nachricht — ohne den Link passiert nichts.',
        'sent' => 'Einladung an :name verschickt.',
        'no_mailer' => 'Es ist kein Mailversand eingerichtet. Unter Einstellungen → '
            .'E-Mail eintragen.',
        'no_address' => ':name hat keine brauchbare E-Mail-Adresse.',
        'failed' => 'Der Versand ist fehlgeschlagen. Mit „aeronance:mail-test" '
            .'prüfen, was der Mailserver sagt.',
    ],
];
