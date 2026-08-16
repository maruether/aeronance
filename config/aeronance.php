<?php

declare(strict_types=1);
use App\Modules\Directives\DirectivesModule;
use App\Modules\Fleet\FleetModule;
use App\Modules\Inspection\InspectionModule;
use App\Modules\Part66\Part66Module;
use App\Modules\TaskCards\TaskCardsModule;
use App\Modules\Tooling\ToolingModule;
use App\Modules\Vereinsflieger\VereinsfliegerModule;
use App\Modules\Warehouse\WarehouseModule;

return [

    /*
    |---------------------------------------------------------------------------
    | Modules shipped with this release
    |---------------------------------------------------------------------------
    |
    | Explicit list, no directory scan. What ships is a fixed, reviewable set,
    | and an explicit list is the most direct expression of the guardrail
    | "no loading of code at runtime" -- it also shows up in the diff when it
    | changes, which a scan never would.
    |
    | Being listed here means the module is PRESENT, not that it is ACTIVE.
    | Activation lives in the database and is managed through ModuleManager.
    |
    */

    'modules' => [
        WarehouseModule::class,
        FleetModule::class,
        TaskCardsModule::class,
        Part66Module::class,
        DirectivesModule::class,
        InspectionModule::class,
        ToolingModule::class,
        VereinsfliegerModule::class,
        // App\Modules\LdapIdentity\LdapIdentityModule::class,
    ],

    /*
    |---------------------------------------------------------------------------
    | Club identity
    |---------------------------------------------------------------------------
    |
    | Nothing club-specific belongs in the code. One instance serves one club;
    | these values come from the environment or the setup wizard.
    |
    */

    /*
    |---------------------------------------------------------------------------
    | Manufacturer logins from the environment
    |---------------------------------------------------------------------------
    |
    | Captured HERE and nowhere else, because env() outside a config file returns
    | null the moment `php artisan config:cache` has run -- which deploy/update.sh
    | does on every update. Read directly, a gated source would simply lose its
    | credentials after an update and report "Zugangsdaten fehlen" for a login
    | that is sitting right there in the .env.
    |
    | Everything DIRECTIVES_*_USER / _PASSWORD is picked up, so a new manufacturer
    | needs no change here. What a user types into the panel does not come through
    | this path at all -- it lives encrypted in the database; see SourceCredentials
    | for which of the two wins.
    |
    */

    'directive_credentials' => collect(array_merge($_ENV, $_SERVER))
        ->filter(fn ($value, string $key): bool => is_string($value)
            && str_starts_with($key, 'DIRECTIVES_')
            && (str_ends_with($key, '_USER') || str_ends_with($key, '_PASSWORD')))
        ->all(),

    'organisation' => [
        // Pfad auf der local-Disk; ausgeliefert ueber LogoController.
        'logo' => env('ORGANISATION_LOGO', ''),
        'name' => env('ORGANISATION_NAME', 'Luftsportverein'),

        // Timestamps are stored in UTC -- that survives daylight saving
        // transitions and keeps audit entries unambiguous. This is the zone
        // they are shown in.
        'timezone' => env('ORGANISATION_TIMEZONE', 'Europe/Berlin'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Sperrzettel (quarantine tags)
    |---------------------------------------------------------------------------
    |
    | Geometrie des Etikettenbogens, in Millimetern. Konfigurierbar und nicht
    | fest verdrahtet, weil sich Stanzmasse zwischen Herstellern und sogar
    | zwischen Chargen unterscheiden -- und weil ein um zwei Millimeter
    | verschobener Druck einen ganzen Bogen unbrauchbar macht.
    |
    | Voreingestellt ist Avery Zweckform T2002-10: 90 x 50 mm Anhaenger aus
    | 220-g-Karton mit Faden, 10 Stueck je A4-Bogen. Die Randmasse sind aus dem
    | Bogenformat errechnet und vor dem ersten Serieneinsatz einmal gegen ein
    | echtes Blatt zu pruefen -- dafuer gibt es den Kalibrierbogen.
    |
    */

    'quarantine_tag' => [
        'template' => 'T2002-10',

        /*
         * Farben der Zustaende.
         *
         * Voreingestellt ist die international verbreitete Konvention, wie sie
         * aus den US-Militaervordrucken (DD 1574 ff.) stammt und in der Branche
         * weithin gelesen wird:
         *
         *   gelb  brauchbar
         *   blau  wartet auf Entscheidung / Pruefung
         *   gruen unbrauchbar, aber instandsetzbar
         *   rot   ausgemustert, nicht wiederverwendbar
         *
         * Achtung beim Lesen: GRUEN heisst hier "reparabel", nicht "gut".
         * Vorgeschrieben ist nichts davon -- keine EASA-Vorschrift regelt
         * Anhaengerfarben. Wer im Verein eine andere Zuordnung gewohnt ist,
         * aendert sie hier, und der Zettel traegt den Zustand ohnehin in Worten.
         */
        'colours' => [
            'serviceable' => '#e8b400',   // gelb
            'quarantined' => '#1f5fa8',   // blau
            'unserviceable' => '#157a3a', // gruen
            'unsalvageable' => '#c0201c', // rot
            'disposed' => '#5a5a5a',      // grau
        ],

        'sheet' => [
            'width' => 210.0,
            'height' => 297.0,
            'columns' => 2,
            'rows' => 5,
            'tag_width' => 90.0,
            'tag_height' => 50.0,
            'margin_left' => 15.0,
            'margin_top' => 23.5,
            'gap_x' => 0.0,
            'gap_y' => 0.0,

            // Breite des eingefaerbten Kopfes -- die Seite mit dem Loch, an der
            // der Anhaenger haengt. Bunt gehaltene Koepfe sind erkennbar, auch
            // wenn mehrere Anhaenger im Regal uebereinander liegen.
            'head_width' => 17.0,
        ],

        /*
         * Alternative: Etiketten zum Aufkleben auf fertige Anhaenger.
         *
         * Fuer Vereine, die vorgefertigte Warenanhaenger aus farbigem Karton mit
         * Metalloese und Draht verwenden -- deutlich robuster als Faden, und die
         * Farbe steckt im Karton statt in der Tonerdecke. Aufgeklebt wird ein
         * gewoehnliches Laseretikett.
         *
         * Voreingestellt ist ein verbreitetes Format (70 x 37 mm, 24 je A4).
         * Vor dem ersten Einsatz an die tatsaechlich beschafften Etiketten
         * anpassen -- auch hier hilft der Kalibrierbogen.
         */
        'label' => [
            'width' => 210.0,
            'height' => 297.0,
            'columns' => 3,
            'rows' => 8,
            'tag_width' => 70.0,
            'tag_height' => 37.0,
            'margin_left' => 0.0,
            'margin_top' => 0.0,
            'gap_x' => 0.0,
            'gap_y' => 0.0,
            'head_width' => 0.0,
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Aktualisierungen
    |---------------------------------------------------------------------------
    |
    | Vorgabe: "ich haette gerne ein auto update das auf GitHub zugreift."
    |
    | Die Anwendung SCHAUT NACH und installiert nichts. Das ist keine Vorsicht,
    | sondern Bauart: Im Docker-Kanal kann sich eine Installation gar nicht
    | selbst aktualisieren (das Image ist unveraenderlich), im Tarball-Kanal
    | gibt es kein Git, mit dem sich ein Tag auschecken liesse. Ein
    | Update-Knopf liefe in zwei von drei Kanaelen ins Leere -- und
    | kanalspezifische Codepfade verbietet CLAUDE.md.
    |
    | Was ueberall funktioniert, ist die FRAGE. Also beantwortet die Anwendung
    | die Frage, und der Kanal erledigt den Rest: deploy/update.sh, ein neues
    | Image, das LXC-Skript.
    |
    | Abgeschaltet wird das mit AERONANCE_UPDATE_CHECK=false. Eine Instanz ohne
    | Internetzugang braucht das nicht -- und ein Verein, der nicht moechte,
    | dass seine Installation nach draussen telefoniert, hat das Recht dazu.
    |
    */

    'updates' => [
        /*
         * Das oeffentliche Repository, aus dem die Veroeffentlichungen kommen.
         *
         * Der oeffentliche Spiegel auf GitHub. Dorthin schiebt GitLab die Tags,
         * und von dort liest diese Pruefung -- ueber die GitHub-API, weil
         * Push-Mirroring Refs uebertraegt und keine GitHub-Releases.
         *
         * Vorgabe vom 2026-08-07: "den github pfad darfst du als oeffentlich
         * betrachten." Die Pruefung braucht deshalb keine Anmeldung; die API
         * beantwortet oeffentliche Repositories ohne Token.
         *
         * WAR DER SPIEGEL PRIVAT, BLIEBE DIE PRUEFUNG STUMM -- die GitHub-API
         * unterscheidet ohne Anmeldung nicht zwischen "gibt es nicht" und
         * "darfst du nicht sehen", beides ist 404. Das ist kein Fehler und
         * sieht auch nicht wie einer aus; die Pruefung meldet dann schlicht
         * "keine Auskunft". Der Hinweis bleibt hier stehen, weil eine spaetere
         * Instanz mit eigenem, privatem Spiegel genau darueber stolpern wird.
         */
        'repository' => env('AERONANCE_UPDATE_REPOSITORY', 'maruether/aeronance'),

        'check' => (bool) env('AERONANCE_UPDATE_CHECK', true),

        // Ein Verein veroeffentlicht keine zwei Fassungen am Tag.
        'cache_hours' => (int) env('AERONANCE_UPDATE_CACHE_HOURS', 12),

        'timeout' => (int) env('AERONANCE_UPDATE_TIMEOUT', 8),
    ],

    /*
    |---------------------------------------------------------------------------
    | Bestellungen
    |---------------------------------------------------------------------------
    |
    | Vorgabe: "Es geht bei den bestellungen nicht darum über aeronance
    | bestellungen auszuführen oder die Kosten zu führen sondern nur darum
    | einen reminder zu bekommen."
    |
    | Wie viele Tage zwischen zwei Erinnerungen zu derselben Bestellung liegen.
    | Ohne Abstand schriebe der taegliche Lauf jeden Morgen dieselbe Mail, bis
    | die Lieferung kommt -- und die vierte identische Nachricht wischt jeder
    | weg, ohne sie zu lesen.
    |
    */

    'orders' => [
        'reminder_interval_days' => (int) env('AERONANCE_ORDER_REMINDER_DAYS', 3),

        /*
         * Voreingestelltes Lieferdatum: Bestelldatum plus diese Tage.
         *
         * Vorgabe: "da einige lieferanten kein lieferdatum angeben, würde ich
         * gerne bestelldatum + 1 Woche als default einsetzen."
         *
         * Der Erinnerer haengt am zugesagten Datum. Wo nichts zugesagt wurde,
         * gaebe es ohne Vorbelegung auch keine Erinnerung -- und das ist genau
         * der Lieferant, bei dem man sie braucht: der, der sich nicht meldet.
         *
         * Eine Woche ist eine ANNAHME und keine Zusage. Sie darf ueberschrieben
         * und geleert werden; geleert heisst weiterhin "nicht erinnern".
         */
        'default_lead_days' => (int) env('AERONANCE_ORDER_LEAD_DAYS', 7),
    ],

    /*
    |---------------------------------------------------------------------------
    | Losaufkleber
    |---------------------------------------------------------------------------
    |
    | Das Etikett am Teil. Vorgabe: "wir brauchen losaufkleber fuer die Teile.
    | kommen aus dem thermodrucker" -- gedacht an Brother DK-Folie.
    |
    | ZWEI BETRIEBSARTEN, weil zwei Vereine zwei Drucker haben:
    |
    |   roll   Etikettendrucker mit Rolle. Die SEITE IST DAS ETIKETT, eines je
    |          Seite, kein Raster. So arbeitet ein Brother QL, ein Zebra oder
    |          ein Dymo. Voreingestellt 62 x 29 mm -- die Breite der
    |          DK-22606-Rolle (gelbe Folie), auf eine bequeme Laenge geschnitten.
    |
    |   sheet  A4-Bogen mit Raster, fuer Vereine ohne Etikettendrucker.
    |          Voreingestellt 70 x 37 mm, 24 je Bogen -- ein verbreitetes
    |          Laseretikett.
    |
    | Vor dem ersten Einsatz an die tatsaechlich beschafften Etiketten anpassen;
    | dafuer gibt es den Kalibrierbogen.
    |
    | ─────────────────────────────────────────────────────────────────────────
    | ZUR HALTBARKEIT, weil sie bei der Geraetewahl gern uebersehen wird:
    |
    | "DK-Folie" bezeichnet den TRAEGER, nicht das Druckverfahren. Jeder Brother
    | QL druckt THERMODIREKT -- das Bild entsteht aus hitzeempfindlicher Chemie.
    | Folie haelt Wasser und Reissen aus, der Druck verblasst trotzdem unter UV,
    | Waerme und Loesemitteln.
    |
    | Deshalb ist dieses Etikett als VERWEIS entworfen und nicht als Nachweis:
    | Die Losnummer fuehrt zum Datensatz und zum Form 1, dort steht alles.
    | Verblasst es, heisst das "nachschlagen" und nicht "Herkunft verloren".
    |
    | Wer ein Etikett braucht, das in fuenf Jahren OHNE das System lesbar ist,
    | nimmt Thermotransfer mit Harzband auf Polyester (Brother TD-4420TN,
    | Zebra ZD421t) -- anderer Druckertyp, hier aendert sich nur die Groesse.
    | ─────────────────────────────────────────────────────────────────────────
    |
    */

    'lot_label' => [
        /*
         * Rolle: eine Seite je Etikett. `margin_*` sind der bedruckbare Rand
         * des Geraets -- ein QL kann die aeussersten Millimeter nicht treffen.
         */
        /*
         * Der QR-Code auf dem Etikett.
         *
         * Er traegt KEINE Adresse, sondern nur einen Verweis (siehe ScanCode) --
         * gescannt wird mit dem Scanner der Anwendung selbst. Ein Regalschild
         * haengt sichtbar in der Halle; eine URL darauf verriete jedem, der es
         * fotografiert, die Adresse dieser Instanz.
         *
         * 16 mm sind die untere brauchbare Grenze fuer eine Telefonkamera aus
         * Armlaenge. Wer kleinere Etiketten fahrt, schaltet ihn eher ab, als ihn
         * zu verkleinern: ein Code, der nicht liest, ist schlechter als keiner,
         * weil man es erst am Regal merkt.
         */
        'qr' => true,
        'qr_size' => 16.0,

        'roll' => [
            'width' => 62.0,
            'height' => 29.0,
            'columns' => 1,
            'rows' => 1,
            'label_width' => 62.0,
            'label_height' => 29.0,
            'margin_left' => 0.0,
            'margin_top' => 0.0,
            'gap_x' => 0.0,
            'gap_y' => 0.0,
        ],

        'sheet' => [
            'width' => 210.0,
            'height' => 297.0,
            'columns' => 3,
            'rows' => 8,
            'label_width' => 70.0,
            'label_height' => 37.0,
            'margin_left' => 0.0,
            'margin_top' => 0.0,
            'gap_x' => 0.0,
            'gap_y' => 0.0,
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Retention
    |---------------------------------------------------------------------------
    |
    | Only these two logs are ever cleaned up automatically. Everything else is
    | either stock or evidence -- see decision E3 in docs/ANALYSE.md. Each data
    | class is switched on individually and deliberately, so a misconfiguration
    | cannot reach the stock movements.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Nachweisdokumente
    |--------------------------------------------------------------------------
    |
    | Form 1, Konformitaetsbescheinigungen, spaeter CRS. Sie liegen auf einer
    | privaten Disk ausserhalb des Webroots und werden nur ueber eine
    | auth-gepruefte Route ausgeliefert.
    |
    */

    'documents' => [

        /*
         * Auch in config/media-library.php gelesen -- beide ueber dieselbe
         * Env-Variable, damit Formular und Ablage nicht auseinanderlaufen.
         */
        'max_size_mb' => (int) env('AERONANCE_DOCUMENT_MAX_MB', 20),

        /*
         * Virenpruefung: 'none' oder 'clamav'.
         *
         * Aus, solange nichts eingerichtet ist -- ein LXC beim Verein hat in der
         * Regel keinen clamd. Wer sie einschaltet, will sie auch durchgesetzt
         * sehen: siehe fail_closed.
         */
        'scanner' => env('AERONANCE_VIRUS_SCANNER', 'none'),

        'clamav' => [
            /*
             * WIE geredet wird: 'socket' oder 'tcp'.
             *
             * Stand frueher nirgends und ergab sich daraus, OB ein Host gesetzt
             * war -- mit dem Ergebnis, dass ein alter Hosteintrag den Socket
             * still ueberstimmte. Jetzt ist es eine Entscheidung, und der
             * Provider gibt bei 'socket' keinen Host mehr weiter.
             */
            'transport' => env('AERONANCE_CLAMAV_TRANSPORT', 'socket'),

            // Unix-Socket (gleicher Rechner) oder Host/Port (eigener Dienst).
            'socket' => env('AERONANCE_CLAMAV_SOCKET', '/var/run/clamav/clamd.ctl'),
            'host' => env('AERONANCE_CLAMAV_HOST'),
            'port' => (int) env('AERONANCE_CLAMAV_PORT', 3310),
            'timeout' => (int) env('AERONANCE_CLAMAV_TIMEOUT', 30),

            /*
             * Was passiert, wenn der Scanner nicht erreichbar ist.
             *
             * Standard: ablehnen. Wer die Pruefung einschaltet, will nicht, dass
             * sie sich bei einem abgestuerzten Dienst still selbst abschaltet --
             * das waere der Zustand, den man am wenigsten bemerkt.
             */
            'fail_closed' => (bool) env('AERONANCE_CLAMAV_FAIL_CLOSED', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Flotte
    |--------------------------------------------------------------------------
    */

    'fleet' => [

        /*
         * Voreinstellung fuer die zulaessige Ueberziehung einer Laufzeitgrenze.
         *
         * Nur ein Vorschlag beim Anlegen -- eingetragen wird sie je Grenze, denn
         * sie steht im Instandhaltungsprogramm und nicht in einer Konfiguration.
         * LTA tragen in der Regel gar keine, und ein ARC nie.
         *
         * Sind beide gesetzt, gewinnt die kleinere: Zehn Prozent von hundert
         * Stunden sind zehn Stunden, zehn Prozent von zwoelf Monaten sind mehr
         * als ein Monat -- und dann ist der Monat die Antwort.
         */
        'default_tolerance_percent' => (float) env('AERONANCE_TOLERANCE_PERCENT', 10),
        'default_tolerance_months' => (float) env('AERONANCE_TOLERANCE_MONTHS', 1),

        /* Wie weit die Faelligkeitsliste nach vorne schaut. */
        'due_window_days' => (int) env('AERONANCE_DUE_WINDOW_DAYS', 60),
    ],

    /*
     * ─────────────────────────────────────────────────────────────────────────
     * VERSCHLUESSELTE SICHERUNGEN.
     *
     * Aus bleibt die Vorgabe: Eine frische Installation soll sichern koennen,
     * bevor jemand Schluessel verwaltet, und eine Sicherung, die wegen eines
     * fehlenden Passworts NICHT laeuft, ist schlimmer als eine unverschluesselte.
     *
     * ZWEI WEGE, und sie schuetzen gegen Verschiedenes:
     *
     *   recipient  Der Server traegt nur den OEFFENTLICHEN Schluessel und kann
     *              seine eigenen Sicherungen nicht lesen. Der private liegt beim
     *              Verein, offline. Das ist die Empfehlung -- und der Preis ist,
     *              dass ein verlorener privater Schluessel die Sicherungen
     *              wertlos macht.
     *
     *   passphrase Ein Passwort, das der geplante Lauf kennen muss und deshalb
     *              hier steht. Wer den Server hat, hat auch das Passwort -- es
     *              schuetzt die Sicherung DORT, wo sie hingeht: beim
     *              Backup-Anbieter, auf einer verlorenen Platte, in einem falsch
     *              gesetzten Bucket. Genau dafuer ist es gedacht.
     *
     * Vorgabe: "das bekommen viele nicht hin. wir sollten das einbauen und
     * empfehlen, aber auch ein passwort anbieten."
     * ─────────────────────────────────────────────────────────────────────────
     */
    'backup' => [
        'encryption' => [
            'mode' => env('BACKUP_ENCRYPTION', 'none'),

            // Pfad zur Datei mit dem oeffentlichen Schluessel (PEM), oder der
            // PEM-Block selbst.
            'public_key' => env('BACKUP_PUBLIC_KEY'),

            'passphrase' => env('BACKUP_PASSPHRASE'),
        ],

        /*
         * ─────────────────────────────────────────────────────────────────────
         * DER ZWEITE ORT. Vorgabe: "ein backup ohne offsite storage ist nur halb
         * soviel wert." Eine Sicherung neben der Datenbank auf derselben Platte
         * ueberlebt genau den Fall nicht, fuer den sie gemacht ist.
         *
         * Angegeben wird der NAME EINER DISK aus config/filesystems.php --
         * damit ist das Ziel reine Konfiguration und im Code steht kein
         * anbieterspezifischer Pfad. Moeglich sind damit ein gemountetes
         * Verzeichnis (NFS, CIFS, eine per SSHFS eingehaengte Storage Box), ein
         * S3-kompatibler Speicher oder SFTP.
         *
         * Leer heisst aus. Eine frische Installation sichert erst einmal
         * lokal -- und der Lauf sagt in seinem Bericht, dass sie das tut.
         * ─────────────────────────────────────────────────────────────────────
         */
        'offsite' => [
            'disk' => env('BACKUP_OFFSITE_DISK', ''),

            // Unterverzeichnis am Ziel, damit mehrere Instanzen sich einen
            // Speicher teilen koennen, ohne sich die Sicherungen zu ueberschreiben.
            'prefix' => env('BACKUP_OFFSITE_PREFIX', ''),

            /*
             * Wie viele Sicherungen am Ziel bleiben. Ohne Aufraeumen laeuft ein
             * Backup-Space voll -- und der Lauf, der ihn fuellt, ist der, der
             * ihn danach nicht mehr beschreiben kann.
             */
            'keep' => (int) env('BACKUP_OFFSITE_KEEP', 30),
        ],
    ],

    /*
     * ─────────────────────────────────────────────────────────────────────────
     * VEREINSFLIEGER. Alles hier ist ueber die Einstellungsseite pflegbar --
     * die env-Namen stehen nur, damit eine Erstinstallation sie vorgeben kann.
     *
     * DIE RUECKWEGE SIND EINZELN ABSCHALTBAR. Vorgabe: "beide punkte sollten
     * getrennt abschaltbar sein." Beide sind AUS ab Werk: Ein Rueckweg
     * schreibt in ein fremdes, produktives System, und das darf nie ein
     * Nebeneffekt einer Installation sein.
     * ─────────────────────────────────────────────────────────────────────────
     */
    /*
    |---------------------------------------------------------------------------
    | E-Mail
    |---------------------------------------------------------------------------
    |
    | Der SMTP-Zugang selbst steht in config/mail.php -- dort liest Laravels
    | Mailer, und ein zweiter Ort waere eine zweite Wahrheit. Hier steht nur,
    | was Aeronance daraus macht.
    |
    */

    'mail' => [
        /*
         * Ab Werk AUS. Beim ersten Mitgliederabgleich koennen auf einen Schlag
         * hunderte Konten entstehen -- ob die alle sofort eine Einladung
         * bekommen, ist eine Entscheidung des Vereins und keine Voreinstellung.
         * Aus heisst nicht "nie": In der Benutzerliste steht eine Schaltflaeche.
         */
        'invite_automatically' => (bool) env('MAIL_INVITE_AUTOMATICALLY', false),
    ],

    'vereinsflieger' => [
        /*
         * ─────────────────────────────────────────────────────────────────────
         * DIE ZUGANGSDATEN STEHEN HIER NICHT MEHR.
         *
         * Sie sind Datensaetze geworden (vereinsflieger_connections), weil eine
         * CAO Luftfahrzeuge MEHRERER Vereine betreut und jeder Verein seinen
         * eigenen Vereinsflieger hat. Eine Konfigurationszeile kann genau einen
         * Zugang halten -- das reichte nicht mehr.
         *
         * Was hier bleibt, gilt fuer die INSTALLATION und nicht je Verein.
         * ─────────────────────────────────────────────────────────────────────
         */
        'workhours' => [
            /*
             * Ab Werk AUS. Es schreibt in ein fremdes, produktives System, und
             * zwar endgueltig: Vereinsflieger kann eine gebuchte Stunde weder
             * aendern noch loeschen (gemessen -- es gibt weder edit noch
             * delete). Das darf nie ein Nebeneffekt einer Installation sein.
             */
            'enabled' => (bool) env('VF_WORKHOURS_ENABLED', false),

            /*
             * Die Kategorienummer aus Vereinsflieger, z. B. 7265 fuer
             * "Wartung/Werkstatt". Eine dort ABGESCHALTETE Kategorie ist ueber
             * die Schnittstelle trotzdem beschreibbar (gemessen an 7813) -- so
             * laesst sich sauber trennen, was aus Aeronance kommt und was
             * jemand von Hand eingetragen hat.
             */
            'category' => env('VF_WORKHOURS_CATEGORY', ''),

            /*
             * "1" nicht bewertet, "2" akzeptiert.
             *
             * GEMESSEN: Vereinsflieger uebernimmt den Status beim Anlegen.
             * Vorgabe: "wenn es sauber über aeronance dokumentiert ist dann bin
             * ich happy wenn es akzeptiert heisst" -- und der wichtigere Grund:
             * "akzeptiert" sperrt den Eintrag drueben fuer das MITGLIED --
             * solange er "nicht bewertet" ist, kann es ihn noch aendern. Die
             * Abzeichner des Vereins kommen weiterhin dran; unveraenderlich ist
             * er nur aus Sicht dessen, fuer den er gebucht wurde. (Korrektur
             * aus dem Betrieb -- hier stand erst "macht ihn dicht", und das
             * war zu viel behauptet.)
             */
            'status' => env('VF_WORKHOURS_STATUS', '1'),
        ],

        /*
         * Hier stand ein 'writeback'-Block: "Instandhaltungspunkte
         * zurueckschreiben", wirkungslos, weil Vereinsfliegers einziger
         * Wartungs-Endpunkt LESEND ist. Der Schalter blieb "damit er nicht
         * nachtraeglich erfunden werden muss" -- und genau das war der Fehler:
         * Ein Schalter ohne Wirkung ist ein Versprechen, das die Anwendung
         * nicht haelt. Vorgabe: "funktionslose schalter taugen nix." Sollte
         * der Endpunkt je schreibbar werden, kommt der Schalter MIT der
         * Funktion wieder, nicht vor ihr.
         */
    ],

    'retention' => [
        'activity_log' => [
            'enabled' => false,
            'days' => 365 * 3,
        ],
        'break_glass_log' => [
            'enabled' => false,
            'days' => 365 * 5,
        ],
        'pseudonymise_former_members' => [
            'enabled' => false,
            'days' => 28,
        ],
    ],

];
