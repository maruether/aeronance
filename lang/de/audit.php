<?php

declare(strict_types=1);

return [

    'title' => 'Protokoll',
    'subheading' => 'Das Protokoll ist ein Diagnosewerkzeug: gedacht zum gezielten '
        .'Nachschlagen bei Auffälligkeiten, nicht zum ständigen Mitlesen. '
        .'Einträge lassen sich nicht ändern und nicht löschen.',

    'system' => 'System',
    'yes' => 'ja',
    'no' => 'nein',
    'subject_gone' => ':type (Datensatz entfernt)',

    'field' => [
        'when' => 'Wann',
        'who' => 'Wer',
        'area' => 'Bereich',
        'what' => 'Was',
        'object' => 'Betroffen',
        'changes' => 'Änderung',
    ],

    /*
     * Die Bereiche entsprechen den log_name-Werten im Code. Sie werden ueber
     * einen ZUSAMMENGESETZTEN Schluessel uebersetzt (audit.area. . $state) --
     * kein Scanner sieht das, und ein fehlender Eintrag zeigt hier woertlich
     * "audit.area.fleet" an. AuditVocabularyTest zaehlt sie deshalb ab.
     */
    'area' => [
        'core' => 'Kern',
        'auth' => 'Anmeldung',
        'warehouse' => 'Lager',
        'inspection' => 'Eingangsprüfung',
        'tooling' => 'Werkzeuge',
        'fleet' => 'Flotte',
        'workorders' => 'Arbeitskarten',
        'directives' => 'LTA/TM',
        'directive_credentials' => 'LTA-Zugänge',
        'vereinsflieger' => 'Vereinsflieger',
        'default' => 'Allgemein',
    ],

    'event' => [
        'created' => 'angelegt',
        'updated' => 'geändert',
        'deleted' => 'entfernt',
        'restored' => 'wiederhergestellt',

        // Der Not-Aus.
        'access_locked' => 'Zugang gesperrt',
        'access_unlocked' => 'Sperre aufgehoben',

        // Anmeldeversuche.
        'login_failed' => 'Anmeldung fehlgeschlagen',
        'login_succeeded' => 'angemeldet',
    ],

    /*
     * Was in den Eigenschaften eines Anmelde-Eintrags steht. Ohne diese Zeilen
     * saehe ein Protokolleintrag zur fehlgeschlagenen Anmeldung leer aus -- der
     * Grund steht naemlich nicht im Ereignis, sondern in den Eigenschaften.
     */
    'auth' => [
        'identifier' => 'Kennung',
        'ip' => 'IP-Adresse',
        'account_exists' => 'Konto vorhanden',
        'unknown_account' => 'kein Konto zu dieser Kennung',
        'known_account' => 'Konto vorhanden — falsches Passwort oder kein Zugang',
    ],

    'subject' => [
        'PartType' => 'Bauteiltyp',
        'StorageLocation' => 'Lagerort',
        'StorageCompartment' => 'Lagerfach',
        'Supplier' => 'Lieferant',
        'Qualification' => 'Qualifikation',
        'User' => 'Benutzer',
        'Role' => 'Rolle',
    ],

    'filter' => [
        'from' => 'Von',
        'until' => 'Bis',
    ],

];
