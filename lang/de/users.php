<?php

declare(strict_types=1);

return [

    'singular' => 'Benutzer',
    'plural' => 'Benutzer',

    'section' => [
        'account' => 'Konto',
        'roles' => 'Rollen',
    ],

    'field' => [
        'name' => 'Name',
        'avatar' => 'Profilbild',
        'email' => 'E-Mail-Adresse',
        'password' => 'Passwort',
        'is_active' => 'Aktiv',
        'roles' => 'Rollen',
        'qualifications' => 'Gültige Qualifikationen',
        'origin' => 'Herkunft',
        'locked' => 'Gesperrt',
        'lock_reason' => 'Grund der Sperre',
    ],

    'lock' => [
        'since' => 'seit :date',
        'by' => 'Gesperrt am :date durch :name',
        'by_unknown' => 'Gesperrt am :date',
        'reason' => 'Grund: :reason',
    ],

    'origin' => [
        'local' => 'Hier angelegt',
    ],

    'filter' => [
        'all' => 'Alle',
        'never_activated' => 'Nie aktiviert',
        'never_activated_true' => 'Ohne Passwort',
        'never_activated_false' => 'Mit Passwort',
        'no_address' => 'E-Mail-Adresse',
        'no_address_true' => 'Ohne Adresse',
        'no_address_false' => 'Mit Adresse',
        'locked' => 'Sperre',
        'locked_true' => 'Gesperrt',
        'locked_false' => 'Nicht gesperrt',
    ],

    'action' => [
        'invite' => 'Einladen',
        'invite_confirm' => 'Verschickt eine E-Mail mit einem Link, über den sich '
            .'diese Person ein Passwort setzen kann. Der Link ist begrenzt gültig.',
        'lock' => 'Zugang sperren',
        'lock_heading' => 'Zugang von :name sperren',
        'lock_description' => 'Der Zugang ist sofort weg — auch eine laufende Sitzung '
            .'wird beendet. Die Sperre bleibt bestehen, unabhängig davon, was der '
            .'nächtliche Mitgliederabgleich meldet. Aufheben kann sie jederzeit, wer '
            .'Benutzer verwalten darf.',
        'unlock' => 'Sperre aufheben',
        'unlock_heading' => 'Sperre von :name aufheben',
        'unlock_description' => 'Danach kann sich diese Person wieder anmelden, '
            .'sofern sie als Mitglied geführt wird. Bisheriger Grund der Sperre: :reason',
    ],

    'notification' => [
        'locked' => 'Zugang von :name gesperrt.',
        'locked_body' => 'Die laufende Sitzung wurde beendet. Kein Abgleich hebt '
            .'diese Sperre auf.',
        'unlocked' => 'Sperre von :name aufgehoben.',
    ],

    'help' => [
        'avatar' => 'JPG, PNG oder WebP, höchstens 2 MB. Ohne Bild stehen die '
            .'Initialen da. Sichtbar für alle angemeldeten Mitglieder.',
        'password' => 'Mindestens zwölf Zeichen mit Buchstaben und Ziffern. '
            .'Beim Bearbeiten leer lassen, um das bisherige Passwort zu behalten.',
        'is_active' => 'Ein deaktiviertes Konto kann sich weder anmelden noch sonst '
            .'irgendetwas tun — die Rechte bleiben erhalten, wirken aber nicht.',
        'roles' => 'Rollen sagen, was jemand im System bedienen darf. Wofür jemand '
            .'einstehen kann, steht unter Qualifikationen.',
        'is_active_from_provider' => 'Wird aus :provider übernommen. Wer dort ausscheidet, '
            .'verliert beim nächsten Abgleich auch hier den Zugang.',
        'from_provider' => 'Kommt aus :provider und lässt sich nur dort ändern — '
            .'der nächtliche Abgleich setzt den Wert ohnehin neu.',
        'lock_reason' => 'Wird im Audit-Log festgehalten. In drei Monaten weiß sonst '
            .'niemand mehr, warum diese Sperre da ist — und keiner traut sich, sie '
            .'aufzuheben.',
        'locked' => 'Solange die Sperre besteht, kommt diese Person nicht herein — '
            .'auch dann nicht, wenn sie im Mitgliederverwaltungssystem aktiv ist.',
    ],

];
