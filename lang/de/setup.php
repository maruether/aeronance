<?php

declare(strict_types=1);

return [
    'organisation' => [
        'title' => 'Organisation',
        'intro' => 'Name und Zeitzone. Beides erscheint auf jedem Ausdruck — '
            .'die Zeitzone entscheidet ausserdem, welches Datum ein Sperrzettel trägt.',
        'name' => 'Name der Organisation',
        'timezone' => 'Zeitzone',
        'save' => 'Übernehmen',
        'saved' => 'Angaben zur Organisation gespeichert.',
    ],

    'title' => 'Aeronance einrichten',
    'intro' => 'Dieser Assistent führt einmalig durch die Installation. '
        .'Danach verriegelt er sich dauerhaft und ist nicht mehr erreichbar.',

    'step' => [
        'database' => 'Datenbank',
        'demo' => 'Oder: Demo einrichten',
        'migrate' => 'Tabellen anlegen',
        'administrator' => 'Administratorkonto',
        'modules' => 'Module',
        'finish' => 'Abschließen',
    ],

    'demo' => [
        'preselected' => 'in der Umgebung vorgewählt',
        'what' => 'Eine Spielwiese zum Ausprobieren statt einer Vereinsinstallation. '
            .'Alles ist da und alles ist erfunden — und die Wahl lässt sich nicht '
            .'zurücknehmen: Aus einer Demo wird später keine Live-Installation und '
            .'umgekehrt.',
        'point' => [
            'reset' => 'Der gesamte Datenbestand wird jede Nacht gelöscht und neu '
                .'aufgesetzt. Was du einträgst, ist morgen weg.',
            'accounts' => 'Feste Konten mit bekanntem Passwort, die niemand ändern kann — '
                .'sonst wäre die Demo nach dem ersten Besucher zu.',
            'uploads' => 'Keine Dateiuploads. Beispieldokumente liegen bereit.',
            'mail' => 'Kein Mailversand und keine Mailkonfiguration.',
            'fetch' => 'Handabrufe von Herstellermitteilungen sind stark begrenzt.',
        ],
        'account' => 'Anmeldung',
        'password' => 'Passwort',
        'can' => 'kann',
        'confirm' => 'Ich richte eine Demo ein. Mir ist klar, dass der Datenbestand täglich '
            .'gelöscht wird und dass sich das nicht mehr umstellen lässt.',
        'confirm_required' => 'Ohne Bestätigung wird keine Demo eingerichtet.',
        'action' => 'Demo einrichten',
    ],

    'db' => [
        'ok' => 'Verbindung steht (:version).',
        'failed' => 'Keine Verbindung zur Datenbank: :error',
        'not_mariadb' => 'Die Verbindung steht, aber es ist keine MariaDB (:version). '
            .'Aeronance unterstützt ausschließlich MariaDB — auch MySQL nicht.',
        'hint' => 'Die Zugangsdaten der MariaDB hier eintragen. Gespeichert wird erst '
            .'nach erfolgreichem Verbindungstest — ein Tippfehler ist eine '
            .'Fehlermeldung, keine kaputte Konfiguration. Geschrieben wird in die '
            .'Datei .env; in Docker-Umgebungen haben dort gesetzte Variablen Vorrang.',
        'stored_in_env' => 'Die Zugangsdaten stehen in der Datei .env und lassen sich dort ändern.',
        'preconfigured' => 'Die Zugangsdaten kommen aus der Umgebung — dieser Schritt entfällt.',
        'field' => [
            'host' => 'Server',
            'port' => 'Port',
            'database' => 'Datenbank',
            'username' => 'Benutzer',
            'password' => 'Passwort',
        ],
        'action' => 'Verbindung testen und speichern',
        'saved' => 'Zugangsdaten gespeichert — die Verbindung steht (:version).',
        'env_missing' => 'Die Datei .env fehlt. Sie entsteht bei der Installation aus '
            .'.env.example — ohne sie kann hier nichts gespeichert werden.',
    ],

    'migrate' => [
        'action' => 'Tabellen anlegen',
        'done' => 'Die Tabellen wurden angelegt.',
        'already' => 'Die Tabellen sind bereits vorhanden.',
        'hint' => 'Es werden die Tabellen aller mitgelieferten Module angelegt, auch der '
            .'nicht ausgewählten. Ein Modul später zu aktivieren ist dann ein Schalter '
            .'und kein Wartungsfenster.',
    ],

    'admin' => [
        'action' => 'Konto anlegen',
        'created' => 'Das Administratorkonto wurde angelegt und Sie sind angemeldet.',
        'exists' => 'Es gibt bereits ein Administratorkonto.',
        'name' => 'Name',
        'email' => 'E-Mail-Adresse',
        'password' => 'Passwort',
        'password_confirmation' => 'Passwort wiederholen',
        'hint' => 'Mindestens zwölf Zeichen mit Buchstaben und Ziffern.',
    ],

    'modules' => [
        'action' => 'Auswahl übernehmen',
        'saved' => 'Die Modulauswahl wurde übernommen.',
        'none' => 'Diese Ausgabe enthält keine Module. Der Kern läuft auch ohne.',
        'hint' => 'Module lassen sich jederzeit nachträglich aktivieren. Abhängigkeiten '
            .'werden automatisch mit aktiviert.',
    ],

    'finish' => [
        'action' => 'Installation abschließen',
        'hint' => 'Danach ist dieser Assistent dauerhaft nicht mehr erreichbar.',
        'blocked' => 'Erst muss ein Administratorkonto angelegt werden — sonst käme '
            .'niemand mehr hinein.',
    ],

];
