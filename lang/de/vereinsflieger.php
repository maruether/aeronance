<?php

declare(strict_types=1);

return [
    'module' => [
        'title' => 'Vereinsflieger',
        'description' => 'Anmeldung und Mitgliederabgleich über Vereinsflieger. '
            .'Liefert Personen und ihre Vereinsfunktionen; welche Rolle daraus wird, '
            .'entscheiden die Rollenzuordnungen. Die Freigabeberechtigung wird '
            .'ausdrücklich nicht übernommen.',
    ],

    /*
     * Die drei Ebenen, in der Zuordnungsliste lesbar gemacht.
     *
     * Beim Status steht die Nummer mit dabei: Der Name kann sich aendern, die
     * Nummer nicht -- und wer die Zuordnung spaeter liest, soll den Status auch
     * dann wiederfinden, wenn er inzwischen anders heisst.
     */
    'group' => [
        'function' => 'Funktion: :name',
        'role' => 'VF-Rolle: :name',
        'status' => 'Status: :name (Nr. :id)',
        'membership' => 'Mitglied: :name',
    ],

    'status' => [
        'plural' => 'VF-Mitgliedsstatus',
        'subheading' => 'Entscheidet, wer aus Vereinsflieger ein Konto bekommt — und '
            .'als was er geführt wird. Was jemand DARF, entscheiden danach die '
            .'Rollenzuordnungen.',
        'id' => 'Nr. :id',
        'unnamed' => 'Status :id',
        'undecided' => 'noch nicht entschieden',
        'people' => '{0}niemand|{1}1 Person|[2,*]:count Personen',
        'empty' => 'Noch nichts abgerufen. „Status abrufen" holt die Liste aus '
            .'Vereinsflieger.',
        'open_heading' => ':count Status ohne Entscheidung',
        'open_help' => 'Solange nicht entschieden ist, bekommen diese Menschen '
            .'KEIN Konto — zusammen :people. Das ist die sichere Richtung, aber '
            .'keine Dauerlösung: Wer gebraucht wird, fehlt sonst kommentarlos.',
        'mapping_hint' => 'Ein Konto allein kann nichts. Rechte entstehen erst über '
            .'die Rollenzuordnungen — nach Mitgliedschaft, Funktion oder VF-Rolle. '
            .'Kommt später eine Funktion dazu, hat sie zunächst keine Rechte und '
            .'kann jederzeit nachgetragen werden.',
        'discover' => 'Jetzt abrufen',
        'discover_confirm' => 'IM NORMALFALL NICHT NÖTIG: Der Abruf läuft automatisch '
            .'jede Nacht um 02:00. Dieser Knopf ist für die Ersteinrichtung — er '
            .'löst einen zusätzlichen Zugriff auf Vereinsflieger aus. '
            .'Bereits getroffene Entscheidungen bleiben unangetastet; es kommen nur '
            .'neue Status hinzu, Namen und Anzahlen werden aktualisiert.',
        'scheduled' => 'Der Abruf läuft täglich um 02:00. Zuletzt gesehen: :when',
        'never_run' => 'Noch kein Abruf gelaufen. Der erste läuft heute Nacht um 02:00 '
            .'— oder sofort über „Jetzt abrufen".',
        'discover_done' => ':seen Status gefunden, davon :new neu. :undecided ohne Entscheidung.',
        'discover_failed' => 'Der Abruf ist fehlgeschlagen',
        'decided' => ':status wird künftig behandelt als: :handling',
    ],

    'status_handling' => [
        'active' => 'aktiv',
        'passive' => 'passiv',
        'ignore' => 'ignorieren',

        'help' => [
            'active' => 'Konto und Anmeldung. Rechte kommen aus der Zuordnung auf '
                .'„Mitglied: aktiv" oder auf die genaue Statusnummer.',
            'passive' => 'Konto und Anmeldung wie bei aktiv — nur greift die '
                .'Zuordnung auf „Mitglied: passiv". Wer nichts zuordnet, hat ein '
                .'Konto ohne Rechte.',
            'ignore' => 'Kein Konto. Diese Menschen kommen im Abgleich nicht vor.',
        ],
    ],

    'counter' => [
        'note' => 'Aus Vereinsflieger (:connection, :callsign)',
    ],

    'connection' => [
        'singular' => 'VF-Anbindung',
        'plural' => 'VF-Anbindungen',
        'field' => [
            'name' => 'Bezeichnung',
            'username' => 'Benutzername',
            'password' => 'Passwort',
            'password_is_hash' => 'Passwort ist bereits ein MD5-Hash',
            'app_key' => 'App-Key',
            'auth_secret' => 'Auth-Secret',
            'cid' => 'Vereins-ID (cid)',
            'provides_identities' => 'Mitglieder als Benutzer importieren',
            'is_active' => 'Aktiv',
            'aircraft' => 'Luftfahrzeuge',
            'last_run' => 'Letzter Lauf',
        ],
        'help' => [
            'name' => 'Wie dieser Verein hier heißen soll — z. B. „Akaflieg Freiburg".',
            'username' => 'Der Zugang der Instanz für den Abgleich, nicht der eines Mitglieds.',
            'password_is_hash' => 'Vereinsflieger hasht den Klartext selbst. Steht oben '
                .'schon der Hash, muss dieser Schalter an sein — sonst wird doppelt '
                .'gehasht, und Vereinsflieger meldet beides als „Wrong User or wrong '
                .'Password".',
            'auth_secret' => 'Nur nötig, wenn Vereinsflieger eines zum App-Key ausgegeben hat.',
            'cid' => '„0" ist die Vorgabe des offiziellen Clients. Leer lassen geht nicht '
                .'— damit weist Vereinsflieger die Anmeldung ab.',
            'provides_identities' => 'ACHTUNG: Damit bekommen die Mitglieder dieses '
                .'Vereins Konten in DIESER Installation. Bei einer CAO, die Flugzeuge '
                .'mehrerer Vereine betreut, wäre das Zugriff auf ein fremdes System — '
                .'für reine Zeitenabfrage bleibt der Haken aus. '
                .'GENAU EINE Anbindung darf ihn haben: Ein Mensch hat ein Konto, und '
                .'zwei Vereinsflieger vergeben dieselben Kennungen doppelt. Wird er '
                .'hier gesetzt, verlieren ihn die anderen.',
            'is_active' => 'Inaktive Anbindungen werden nachts übersprungen.',
        ],
        'never_run' => 'noch nie',
        'empty' => 'Keine Anbindung eingerichtet',
        'empty_help' => 'Ohne Anbindung passiert nichts — weder Anmeldung noch '
            .'Betriebszeiten noch Arbeitsstunden.',
        'identity_warning' => 'Ohne diesen Haken werden von diesem Verein NUR '
            .'Betriebszeiten geholt. Niemand bekommt ein Konto.',
    ],

    'link' => [
        'singular' => 'Luftfahrzeug-Kopplung',
        'plural' => 'Luftfahrzeug-Kopplungen',
        'field' => [
            'connection' => 'Anbindung',
            'aircraft' => 'Luftfahrzeug',
            'callsign' => 'Kennzeichen in Vereinsflieger',
            'is_active' => 'Zeiten holen',
            'last_read' => 'Zuletzt gelesen',
        ],
        'help' => [
            'callsign' => 'So, wie es in Vereinsflieger steht. In der Regel identisch '
                .'mit dem Kennzeichen am Luftfahrzeug — getrennt geführt, weil eine '
                .'abweichende Schreibweise sonst zu einem Abgleich führt, der stumm '
                .'nichts findet.',
            'is_active' => 'Aus heißt: Die Kopplung bleibt stehen, nachts wird nichts '
                .'geholt.',
        ],
        'no_fleet' => 'Das Flottenmodul ist nicht aktiv — ohne Luftfahrzeuge gibt es '
            .'nichts zu koppeln.',
        'empty' => 'Kein Luftfahrzeug gekoppelt',
    ],

    'workhours' => [
        'categories' => 'Arbeitsstunden-Kategorien',
        'categories_help' => 'Zuletzt aus Vereinsflieger gelesen. Die Nummer gehört in '
            .'die Einstellung „Kategorie" — auch eine dort abgeschaltete Kategorie ist '
            .'über die Schnittstelle beschreibbar.',
    ],
];
