<?php

declare(strict_types=1);

return [

    'module' => [
        'title' => 'LTA / TM',
        'description' => 'Lufttüchtigkeitsanweisungen und Technische Mitteilungen — die '
            .'Übersicht, die zeilenweise bestätigt wird. Die Liste wird länger, nie kürzer.',
    ],

    'singular' => 'LTA / TM',
    'plural' => 'LTA / TM',

    'kind' => [
        'lta' => 'LTA',
        'ad' => 'AD',
        'tm' => 'TM',
        'sb' => 'SB',
    ],

    /*
     * Die PAARE fuer Auswahl und Filter: LTA/AD und TM/SB sind jeweils
     * dasselbe auf Deutsch und Englisch -- gewaehlt wird die Familie, die
     * Liste zeigt weiter das eigene Wort des Dokuments.
     */
    'kind_family' => [
        'lta' => 'LTA / AD (verbindlich)',
        'tm' => 'TM / SB (Herstellermitteilung)',
    ],

    'subject' => [
        'aircraft_model' => 'Luftfahrzeugmuster',
        'component' => 'Bauteil',
        'engine' => 'Motor',
        'propeller' => 'Propeller',
    ],

    'bindingness' => [
        'mandatory' => 'verbindlich',
        'recommended' => 'empfohlen',
        'optional' => 'optional',
    ],

    'state' => [
        'open' => 'nicht beurteilt',
        'complied' => 'durchgeführt',
        'not_applicable' => 'nicht zutreffend',
        'not_carried_out' => 'nicht durchgeführt',
    ],

    'source' => [
        'manual' => 'Manuell erfasst',
        'csv' => 'CSV / Liste einfügen',
        'schleicher' => 'Alexander Schleicher (Download)',
    ],

    'field' => [
        'number' => 'Nummer',
        'title' => 'Titel',
        'summary' => 'Inhalt',
        'kind' => 'Art',
        'bindingness' => 'Verbindlichkeit',
        'issuer' => 'Herausgeber',
        'issued_at' => 'Ausgabedatum',
        'comply_before' => 'Frist',
        'subject_kind' => 'Betrifft',
        'subject_model' => 'Muster',
        'subject_designation' => 'Bauteil',
        'subject_part_number' => 'Teilenummer',
        'serial_from' => 'S/N von',
        'serial_to' => 'S/N bis',
        'is_recurring' => 'Wiederkehrend',
        'interval_months' => 'Intervall (Monate)',
        'interval_counter' => 'Intervall-Zähler',
        'interval_value' => 'Intervall-Wert',
        'superseded_by' => 'Ersetzt durch',
        'reference_url' => 'Fundstelle',
        'source' => 'Quelle',
        'list' => 'Liste',
        'state' => 'Beurteilung',
        'assessed_at' => 'Beurteilt am',
        'assessed_by' => 'Beurteilt von',
        'reason' => 'Begründung',
        'method' => 'Wie durchgeführt',
        'task_card' => 'Arbeitskarte',
        'next_due' => 'Wieder fällig',
        'aircraft' => 'Luftfahrzeug',
    ],

    'card' => [
        'open_new_order' => 'Neuen Vorgang dafür eröffnen (Titel = Anweisung)',
        'action' => 'Arbeitskarte anlegen',
        'created' => 'Arbeitskarte :number angelegt.',
        'help' => 'Die Karte organisiert die Arbeit. Erledigt ist die Anweisung erst, wenn '
            .'jemand Qualifiziertes sie ausdrücklich als durchgeführt vermerkt — mit der '
            .'Kartennummer als Nachweis.',
    ],

    'action' => [
        'comply' => 'Durchgeführt',
        'not_applicable' => 'Nicht zutreffend',
        'not_carried_out' => 'Nicht durchgeführt',
        'reopen' => 'Beurteilung zurücknehmen',
        'import' => 'Liste importieren',
        'supersede' => 'Als ersetzt markieren',
        'assess' => 'Beurteilen',
        'prune' => 'Liste aufräumen',
    ],

    'notification' => [
        'assessed' => 'Beurteilung gespeichert.',
        'imported' => ':created neu, :updated aktualisiert, :unchanged unverändert.',
        'pruned' => ':count Zeile(n) entfernt.',
        'refused' => 'Nicht möglich',
        'collisions' => 'Mehrfach vergebene Nummer',
        'collisions_body' => 'Der Hersteller führt :numbers mehrfach in derselben Liste. '
            .'Übernommen wurde jeweils nur der erste Eintrag — die weiteren bitte von Hand '
            .'anlegen und dabei eine unterscheidbare Nummer vergeben. Automatisch eine zu '
            .'erfinden wäre geraten, und geraten wird hier nicht.',
    ],

    'open' => [
        'unassessed' => 'noch nicht beurteilt',
        'never_assessed' => 'betrifft dieses Luftfahrzeug, noch keine Beurteilung',
        'no_candidates' => 'Kein Luftfahrzeug im Bestand betroffen.',
        'not_carried_out' => 'als nicht durchgeführt vermerkt: :reason',
        'recurrence_due' => 'wieder fällig seit :due',
    ],

    'auto_fetch' => [
        'needs_credentials' => ':source führt Listen für dieses Muster — es fehlen '
            .'aber die Zugangsdaten.',
        'needs_credentials_hint' => 'Unter „Hersteller-Zugänge" hinterlegen; danach '
            .'holt der nächtliche Lauf die Liste, oder sofort über „Importieren".',
        'deferred' => ':source passt zu diesem Muster — der Import läuft beim '
            .'nächsten Sonntagslauf (zum sofortigen Import fehlt das Recht '
            .'„Quellen und Listen verwalten").',
    ],

    'credentials' => [
        'title' => 'Hersteller-Zugänge',
        'subheading' => 'Manche Hersteller geben ihre LTA/TM-Liste nur an Kunden heraus, '
            .'andere zeigen Abonnenten mehr als der Allgemeinheit. Hier stehen alle '
            .'Quellen, die einen Zugang kennen — verlangt oder freiwillig; alle übrigen '
            .'liest Aeronance ohne Anmeldung. Die Zugangsdaten liegen verschlüsselt '
            .'in der Datenbank und werden nie wieder angezeigt.',
        'optional_hint' => 'Freiwillig: Ohne Zugang wird die Liste anonym gelesen — '
            .'ein eingetragenes Abo nutzt der Abruf automatisch.',
        'used_by' => 'Verwendet von: :sources',
        'username' => 'Benutzername',
        'password' => 'Passwort',
        'keep_hint' => 'leer lassen = unverändert',
        'stored_hint' => 'Ein Passwort ist gespeichert. Zum Ändern ein neues eintragen; '
            .'leer lassen behält das bisherige.',
        'not_stored_hint' => 'Noch kein Passwort gespeichert.',
        'save' => 'Speichern',
        'test' => 'Anmeldung testen',
        'forget' => 'Zugang entfernen',
        'saved' => 'Zugangsdaten gespeichert.',
        'removed' => 'Zugang entfernt.',
        'needs_user' => 'Ohne Benutzername lässt sich nichts speichern.',
        'needs_password' => 'Beim ersten Speichern braucht es auch das Passwort — '
            .'es ist noch keins hinterlegt, das behalten werden könnte.',
        'from_env_title' => 'Durch die Umgebung vorgegeben',
        'from_env_body' => 'Für dieses Profil stehen Zugangsdaten in der Serverkonfiguration '
            .'(.env oder Docker-Secret). Die haben Vorrang — ein hier eingetragener Wert '
            .'würde nie gelesen. Änderungen macht, wer den Server verwaltet.',
        'test_ok' => 'Anmeldung erfolgreich — :count Zeilen gelesen.',
        'test_failed' => 'Anmeldung fehlgeschlagen',
        'none' => 'Zurzeit verlangt keine eingerichtete Quelle einen Login.',
    ],

    'source_problems' => [
        'action' => ':count Herstellerdatei(en) fehlerhaft',
        'no_credentials' => 'Zugangsdaten fehlen',
        'help' => 'Diese Dateien konnten nicht gelesen werden und stehen deshalb nicht '
            .'als Quelle zur Verfügung. Eine fehlerhafte Datei nimmt die anderen nicht '
            .'mit — sie wird übersprungen. Ohne diese Anzeige wäre das einzige Symptom '
            .'eine fehlende Quelle, und das sieht genauso aus wie eine, die nie '
            .'eingerichtet wurde.',
    ],

    'overview' => [
        'title' => 'LTA/TM-Übersicht',
        'subheading' => 'Die Liste aus Sicht eines Luftfahrzeugs — Zeile für Zeile.',
        'only_outstanding' => 'nur offene',
        'print' => 'Übersicht drucken',
        'total' => 'Zeilen',
        'unassessed' => 'nicht beurteilt',
        'outstanding' => 'offen',
        'blocking' => 'blockierend',
        'assess_hint' => 'Beurteilt wird auf der Seite der jeweiligen Anweisung — dort '
            .'stehen die drei Antworten samt Begründungsfeld.',
    ],

    /*
     * The warning for a type nobody looks after any more. Shared word for word
     * between the screen and the printed sheet -- two copies of one warning drift
     * apart exactly once, and then only one of them is still right.
     */
    'orphaned' => [
        'headline' => 'Achtung! Kein Musterbetreuer!',
        'body' => 'Für das Muster :type gibt es keinen Musterbetreuer mehr. Es '
            .'veröffentlicht niemand mehr LTA/TM — diese Liste kann deshalb vollständig '
            .'aussehen, ohne es zu sein. Die Recherche liegt beim Halter bzw. beim Betrieb; '
            .'eine leere oder kurze Liste ist hier kein Nachweis.',
    ],

    'empty' => [
        'orphaned' => 'Keine Zeile erfasst — und für dieses Muster gibt es niemanden mehr, '
            .'der etwas veröffentlichen würde. Siehe Warnung oben.',
        'ambiguous' => 'Keine Zeile betrifft dieses Luftfahrzeug. Das heißt entweder, dass '
            .'nichts veröffentlicht wurde, oder dass für dieses Muster noch keine Quelle '
            .'eingerichtet ist — beides sieht hier gleich aus. Im Zweifel beim '
            .'Musterbetreuer nachsehen.',
    ],

    'general_list' => [
        'title' => 'General-TM',
        'subheading' => 'Was der Musterbetreuer für dieses Muster erlaubt — kein offener '
            .'Punkt, sondern ein Angebot.',
        'search' => 'Suche',
        'search_placeholder' => 'Nummer, Titel oder Inhalt, z. B. „Transponder"',
        'urgency' => 'Dringlichkeit',
        'done_here' => 'An diesem Luftfahrzeug',
        'not_adopted' => 'nicht durchgeführt',
        'not_applicable_here' => 'gilt für dieses Luftfahrzeug nicht',
        'adopt' => 'Durchführen',
        'confirm' => 'Damit wird ein Vorgang geöffnet — oder der bereits offene genutzt — '
            .'und eine Arbeitskarte angelegt. Fortfahren?',
        'none' => 'Für dieses Muster ist keine General-TM erfasst.',
        'no_match' => 'Keine Zeile passt zu dieser Suche.',
        'no_aircraft' => 'Kein aktives Luftfahrzeug im Bestand.',
        'needs_cards' => 'Ohne das Arbeitskarten-Modul gibt es keinen Vorgang, in dem Arbeit '
            .'und Material landen könnten. Die Durchführung lässt sich dann nur direkt '
            .'vermerken.',
        'notification' => [
            'order_opened' => 'Vorgang :order neu geöffnet.',
            'order_reused' => 'Zum offenen Vorgang :order hinzugefügt.',
            'card' => 'Arbeitskarte :card angelegt.',
            'unknown_line' => 'Diese Zeile steht auf dieser Seite nicht zur Auswahl.',
        ],
        'help' => [
            'not_outstanding' => 'Eine General-TM ist genehmigte Unterlage des '
                .'Musterbetreuers — ein Weg, eine Änderung legal durchzuführen. Solange sie '
                .'niemand durchführen will, ist sie kein offener Punkt: Sie steht hier beim '
                .'Muster, taucht in der Übersicht des Flugzeugs nicht auf und blockiert '
                .'keine Freigabe.',
            'general_is_not_all' => '„General" heißt nicht „gilt für alles". Angezeigt wird, '
                .'was der Hersteller in seinen Feldern für dieses Muster nennt — mehrere '
                .'General-TM sind enger gefasst als die Musterliste.',
            'compliance_later' => 'Durchführen öffnet den Vorgang und legt die Karte an. '
                .'Durchgeführt ist die Zeile damit noch nicht: Das vermerkt jemand '
                .'Qualifiziertes ausdrücklich, mit der Karte als Nachweis — bis dahin steht '
                .'hier weiter „nicht durchgeführt".',
        ],
    ],

    'filter' => [
        'current_only' => 'nur geltende',
        'superseded_only' => 'nur ersetzte',
    ],

    'summary' => [
        'all_clear' => ':count beurteilt, nichts offen',
        'outstanding' => ':count offen',
    ],

    'help' => [
        'prune' => 'Entfernt alle Zeilen, auf die kein Luftfahrzeug im Bestand passt '
            .'— betroffen wären derzeit :count. Beurteilte Zeilen bleiben immer '
            .'stehen: Eine Beurteilung ist ein Nachweis. Bei leerer Flotte '
            .'passiert nichts, sonst träfe „kein Flugzeug passt" auf alles zu.',
        'prune_restores' => 'Kommt später ein passendes Luftfahrzeug in die Flotte, '
            .'holt der nächste Import die Zeilen von selbst zurück — entfernt heißt '
            .'hier weggeräumt, nicht ausradiert.',
        'method' => 'Der Nachweis der Durchführung: nach welcher Vorgabe und was '
            .'getan wurde — z. B. „TM 34-5 Abschnitt 3, Bolzen getauscht, '
            .'Sichtprüfung o. B.". So, dass es ein Prüfer in drei Jahren versteht.',
        'list_grows' => 'Diese Liste wird länger, nie kürzer. Verschwindet eine Zeile beim '
            .'Hersteller, wird sie hier nicht gelöscht — eine gekürzte Exportdatei oder ein '
            .'kaputter Parser sieht genauso aus, und die Beurteilungen gingen mit.',
        'four_states' => 'Vier Antworten, nicht zwei: durchgeführt, nicht zutreffend '
            .'(mit Begründung), nicht durchgeführt (mit Begründung) — und „nicht beurteilt", '
            .'was etwas völlig anderes heißt als die dritte: Da hat noch niemand hingesehen.',
        'reason_required' => 'Ohne Begründung ist der Eintrag nicht von jemandem zu '
            .'unterscheiden, der seine Liste leerklickt.',
        'not_applicable_stays' => 'Bleibt in der Liste. Ein Prüfer will sehen, dass '
            .'hingeschaut wurde — eine fehlende Zeile beweist nichts.',
        'recurrence' => 'Abgehakt bleibt abgehakt, bis die Laufzeit greift. Gerechnet wird '
            .'vom Tag der Durchführung, nicht von der Fälligkeit.',
        'qualification' => 'Alle drei Beurteilungen sind Aussagen über die Lufttüchtigkeit '
            .'und brauchen eine Qualifikation. „Nicht zutreffend" ist nicht die vorsichtige '
            .'Variante: falsch gesetzt verschwindet eine verbindliche Anweisung still aus '
            .'der Liste.',
        'mandatory' => 'Eine nicht durchgeführte LTA/AD blockiert die Lufttüchtigkeit. Eine '
            .'TM/SB nicht — das ist eine Entscheidung, für die der Betrieb einsteht.',
        'model_match' => 'Teilstring-Vergleich in beide Richtungen: „ASK 21" trifft auch '
            .'„ASK 21 B" und umgekehrt. Leer heißt: alle Muster.',
        'candidates' => 'Angeboten werden die Luftfahrzeuge, die diese Zeile betreffen '
            .'könnte. Bei Bauteil-Anweisungen zählt ein Flugzeug ohne erfasste Komponenten '
            .'mit — nicht erfasst heißt nicht „nicht verbaut".',
        'task_card' => 'Optional. Wenn das Arbeitskarten-Modul läuft, gehört hier die '
            .'Kartennummer hin, mit der es gemacht wurde.',
        'supersede' => 'Die alte Zeile bleibt mit ihren Beurteilungen lesbar und verweist '
            .'auf die neue. „Durchgeführt nach LTA 2019-05, ersetzt durch LTA 2024-11" ist '
            .'die Geschichte, nach der ein Prüfer fragt.',
        'fetch_all' => 'Alle Muster holen',
        'schleicher_model' => 'Muster wie beim Hersteller, z. B. „ASK 21". Leer und '
            .'„alles" angehakt holt alle 42 Typen — 43 Abrufe bei fremden Servern, deshalb '
            .'nicht der Standard.',
        'schleicher_scope' => 'Nur Flugzeugtypen. Triebwerke und Propeller veröffentlicht '
            .'Schleicher anders aufgebaut (ohne Tabelle) — das wäre ein zweiter Parser.',
        'csv_columns' => 'Spalten (Semikolon oder Komma): Nummer; Titel; Datum; Frist; '
            .'Muster; Bauteil; Teilenummer; SN_von; SN_bis; Link. Ohne Kopfzeile wird '
            .'positionsweise gelesen: Nummer; Titel; Datum; Frist.',
        'kind_fallback' => 'Nur Vorgabe: Nennt die Nummer die Art selbst („TM 300/12", '
            .'„SB 090702", „LTA 03-1", „AD 2020-15"), gewinnt die Nummer.',
        'bindingness_from_kind' => 'aus der Art ableiten',
        'bindingness' => 'Verbindlich, empfohlen oder optional — unabhängig von der Art '
            .'des Dokuments. Eine TM wird verbindlich, sobald eine Behörde sie übernimmt, '
            .'ohne ihre Nummer zu ändern. „Empfohlen" darf wie „optional" abgelehnt werden, '
            .'der Hersteller rät aber ausdrücklich dazu.',
        'refusal_optional_only' => 'Eine verbindliche Zeile kann nicht „nicht durchgeführt" '
            .'werden. Für sie gibt es diese Erklärung nicht: Sie ist gemacht, sie trifft '
            .'nicht zu, oder das Flugzeug fliegt nicht. Optionale und empfohlene Zeilen '
            .'dürfen abgelehnt werden — das ist eine Entscheidung, die jemand verantwortet.',
        'refusal_standing' => 'Dafür braucht es eine Part-66-Lizenz oder den Halter dieses '
            .'Luftfahrzeugs. Eine Pilot-Owner-Berechtigung genügt nicht — sie zeichnet '
            .'Instandhaltung ab, sie hebt keine Herstellerempfehlung auf.',
        'unassessed_blocks' => 'Eine nicht beurteilte Zeile verhindert die Freigabe. Wer '
            .'unterschreibt, während niemand die Zeile gelesen hat, unterschreibt über eine '
            .'Unbekannte.',
        'source_seam' => 'Manuell und CSV gibt es immer. Hersteller-Downloads kommen als '
            .'eigene Quelle dazu, ohne dass sich hier etwas ändert.',
    ],

];
