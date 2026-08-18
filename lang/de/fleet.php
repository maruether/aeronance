<?php

declare(strict_types=1);

return [

    'module' => [
        'title' => 'Flotte',
        'description' => 'Luftfahrzeuge, Betriebszeiten, eingebaute Komponenten und '
            .'Fristen. Führt die Laufzeiten, die im Lager bewusst nicht geführt werden.',
    ],

    'component_type' => [
        'singular' => 'Komponentenmuster',
        'plural' => 'Komponentenmuster',
        'action' => [
            'add_limit' => 'Muster-Laufzeit hinzufügen',
        ],
        'no_certificate' => 'kein Kennblatt',
        'field' => [
            'designation' => 'Bezeichnung',
            'kind' => 'Art',
            'manufacturer' => 'Hersteller',
            'part_number' => 'Teilenummer',
            'part_type' => 'Bauteiltyp im Lager',
            'limits' => 'Muster-Laufzeiten',
            'type_certificate' => 'Kennblatt-Nr.',
            'data_sheet' => 'Datenblatt',
            'overview_url' => 'Hersteller-Übersicht TM',
            'fitted' => 'verbaut',
        ],
        'kind' => [
            'engine' => 'Motor',
            'propeller' => 'Propeller',
            'tow_release' => 'Schleppkupplung',
            'other' => 'sonstiges',
        ],
        'help' => [
            'part_type' => 'Optional: der Lager-Bauteiltyp, der dieses Muster ist. '
                .'Beim Einbau aus dem Lager erbt der Einbau dann die '
                .'Muster-Laufzeiten unten — als Kopie, spätere Änderungen hier '
                .'fassen bestehende Einbauten nicht an.',
            'limits' => 'Was dieses Muster üblicherweise mitbringt, z. B. „24 Monate" '
                .'und „500 Starts" an einer Schleppkupplung. Leer ist normal: Ein '
                .'Ölfilter hat keine Laufzeit.',
            'designation' => 'So, wie Hersteller und Kennblatt es schreiben — z. B. '
                .'„Sicherheitskupplung Europa G 88".',
            'part_number' => 'Getrennt von der Bezeichnung: Eine TM nennt das eine oder '
                .'das andere, und eine Teileliste und ein Mensch sagen Verschiedenes.',
            'certificate' => 'Jede Geräteart hat eine eigene Schreibweise — 60.230/2 bei '
                .'Kupplungen, 4502/EN bei Motoren, 32.100/1/PR bei Propellern. Steht auf '
                .'dem Dokument, das vor dir liegt.',
            'overview' => 'Die TM-Übersicht des Herstellers für dieses Bauteil — Rotax und '
                .'Tost veröffentlichen je eine.',
            'lookup' => 'Sucht in den Komponentenbänden des LBA — Motoren, Propeller '
                .'und Schleppkupplungen. Übernommen werden Kennblattnummer und Behörde; '
                .'ein Datenblatt gibt es dort nicht, das Blaue Buch ist eine Liste.',
            'candidates' => 'Findet sich nichts, ist das Gerät vermutlich nie in '
                .'Deutschland zugelassen worden — dann trägst du die Nummer von Hand ein.',
        ],
    ],

    'type' => [
        'singular' => 'Luftfahrzeugmuster',
        'plural' => 'Muster',
        'no_certificate' => 'kein Kennblatt',
        'orphaned' => [
            'badge' => 'kein Musterbetreuer',
        ],
        'field' => [
            'designation' => 'Musterbezeichnung',
            'manufacturer' => 'Hersteller',
            'sheet_variant' => 'Wägeblatt',
            'undercarriage' => 'Fahrwerk',
            'type_support' => 'Musterbetreuer',
            'without_type_support' => 'Kein Musterbetreuer mehr vorhanden',
            'type_certificate' => 'Kennblatt-Nr.',
            'other_certificates' => 'Weitere Kennblattnummern',
            'authority' => 'Behörde',
            'data_sheet' => 'Datenblatt',
            'data_sheet_url' => 'Link zum Kennblatt',
            'overview_url' => 'Hersteller-Übersicht LTA/TM',
            'checked_at' => 'Geprüft am',
            'in_fleet' => 'im Bestand',
            'note' => 'Notiz',
            'search_term' => 'Suchbegriff',
            'candidate' => 'Treffer',
            'store_document' => 'Kennblatt herunterladen und ablegen',
        ],
        'authority' => [
            'easa' => 'EASA',
            'faa' => 'FAA',
            'lba' => 'LBA',
            'other' => 'andere',
        ],
        'source' => [
            'easa' => 'EASA-Dokumentenbibliothek',
            'lba' => 'LBA Blaues Buch',
        ],
        'blue_book' => [
            'segel' => 'Segelflugzeuge',
            'motorsegel' => 'Motorsegler',
            'lfz_2t' => 'Flugzeuge bis 2 t',
            'lfz_ue_2t' => 'Flugzeuge über 2 t',
            'motore' => 'Motoren',
            'propeller' => 'Propeller',
            'kupplung' => 'Schleppkupplungen',
        ],
        'action' => [
            'add_certificate' => 'Kennblattnummer hinzufügen',
            'lookup' => 'Kennblatt suchen',
            'create_from_lookup' => 'Kennblatt suchen & anlegen',
        ],
        'filter' => [
            'documented' => 'Kennblatt vorhanden',
            'orphaned' => 'ohne Musterbetreuer',
        ],
        'notification' => [
            'authority_failed' => ':authority konnte nicht abgefragt werden',
            'adopted' => 'Kennblatt :certificate übernommen.',
            'no_document' => 'Nummer und Link sind gespeichert, das Dokument nicht: :reason',
            'gone' => 'Der Treffer ist nicht mehr auffindbar — bitte erneut suchen.',
        ],
        'help' => [
            'other_certificates' => 'Dieselbe Musterzulassung bei einer anderen Behörde — '
                .'etwa das alte LBA-Kennblatt eines Musters, das später ein EASA-TCDS '
                .'bekommen hat. Oben steht die gültige Nummer; die hier sorgen dafür, '
                .'dass auch ältere Veröffentlichungen das Muster finden.',
            'free_text' => 'Freitext ist erlaubt. Wer etwas fliegt, das niemand '
                .'katalogisiert hat, tippt es hier ein — das Muster ist die bessere '
                .'Antwort, wo es sie gibt, nicht die einzige.',
            'sheet_variant' => 'Welches Wägeblatt jedes Luftfahrzeug dieses Musters '
                .'bekommt. Einmal hier eingetragen, steht es beim Anlegen jeder Wägung '
                .'schon richtig da. Leer lassen ist in Ordnung — dann fragt die '
                .'Wägemaske und schlägt vor, was sie erschließen kann.',
            'undercarriage' => 'Worauf das Luftfahrzeug beim Wiegen steht. Bestimmt die '
                .'Zahl der Wägepunkte und die Zeichnung auf dem Blatt. Weicht ein '
                .'einzelnes Exemplar ab, wird es in seiner Wägung geändert — das Muster '
                .'bleibt davon unberührt.',
            'type_support' => 'Wer das Muster heute betreut — z. B. „LTB Lindner" für die '
                .'Grob-Segelflugzeuge. Ist das Feld nur noch nicht ausgefüllt, heißt das '
                .'nicht, dass es niemanden gibt; dafür gibt es das Kästchen darunter.',
            'without_type_support' => 'Ankreuzen, wenn es für dieses Muster keinen '
                .'Musterbetreuer mehr gibt — Hersteller aufgelöst, niemand hat die '
                .'Musterbetreuung übernommen. Dann trägt die LTA/TM-Übersicht dieses '
                .'Musters die Warnung „Achtung! Kein Musterbetreuer!", am Bildschirm wie '
                .'auf dem Ausdruck. Das ist ausdrücklich etwas anderes als eine noch nicht '
                .'eingerichtete Quelle: Hier gibt es nichts zu konfigurieren.',
            'certificate_notation' => 'So, wie die Behörde es schreibt: „EASA.A.221", '
                .'„A21CE", „322". Vier Behörden, vier Schreibweisen — normiert wird nichts, '
                .'sonst geht die Form verloren, die auf dem Dokument steht.',
            'lookup_later' => 'Kann leer bleiben — in der Musterliste lässt sich das '
                .'Kennblatt bei LBA und EASA suchen und herunterladen.',
            'link_is_enough' => 'Ein Link genügt. Die Datei zusätzlich abzulegen lohnt, '
                .'wenn die Behörde ihre Website umbaut.',
            'overview' => 'Die Übersichtsliste des Herstellers für dieses Muster — z. B. '
                .'Schleichers „Übersicht (PDF)". Dort steht übrigens auch die '
                .'Kennblatt-Nummer.',
            'lookup' => 'Gesucht wird bei allen hinterlegten Behörden. Fällt eine aus, '
                .'antworten die anderen weiter.',
            'candidates' => 'Eine Suche nach „ASK 21" liefert mehrere Treffer — welcher '
                .'der richtige ist, entscheidet ein Mensch. Erst der gewählte kostet einen '
                .'zweiten Abruf für Details und Dokument.',
            'store_document' => 'Der Download läuft durch dieselbe Prüfung wie jedes '
                .'andere Dokument: Größe, echter Dateityp aus den Bytes, Virenscan. Es ist '
                .'die erste Stelle, die eine Datei von einer fremden URL schreibt.',
            'exact_matching' => 'Mit gepflegtem Muster trifft die LTA-Zuordnung exakt '
                .'statt über Namensvergleich.',
        ],
    ],

    'counter' => [
        'flight_hours' => 'Flugzeit',
        'landings' => 'Landungen',
        'engine_hours' => 'Motorlaufzeit',
        'starts' => 'Starts',
        'cycles' => 'Zyklen',
    ],

    'counter_unit' => [
        'flight_hours' => 'h',
        'landings' => '',
        'engine_hours' => 'h',
        'starts' => '',
        'cycles' => '',
    ],

    'limit' => [
        'calendar_months' => 'Monate',
        'calendar_date' => 'festes Datum',
        'flight_hours' => 'Flugstunden',
        'landings' => 'Landungen',
        'engine_hours' => 'Motorstunden',
        'starts' => 'Starts',
        'cycles' => 'Zyklen',
    ],

    'due' => [
        'title' => 'Fälligkeiten',
        'subheading' => 'Was abläuft, und was schon abgelaufen ist.',
        'no_review' => 'Keine Nachprüfung hinterlegt',
        'days' => 'Tage',
        'in' => 'fällig in',
        'at' => 'fällig bei',
        'window' => 'Vorschau',
        'nothing' => 'Nichts fällig im gewählten Zeitraum.',
        'overdue' => 'überfällig',
        'help' => [
            'counted' => 'Gezählte Grenzen haben kein Datum. Gemeldet wird das letzte '
                .'Zehntel der Laufzeit — Starts in Tage umzurechnen bräuchte eine '
                .'Flugrate, und die ist geraten.',
            'no_review' => 'Ein Luftfahrzeug ohne hinterlegte Nachprüfung erzeugt keine '
                .'ablaufende Frist und sähe sonst aus wie eines, bei dem alles in '
                .'Ordnung ist.',
        ],
    ],

    'limit_status' => [
        'ok' => 'in Ordnung',
        'due' => 'fällig',
        'in_tolerance' => 'überzogen (zulässig)',
        'overdue' => 'überfällig',
    ],

    'tolerance' => [
        'label' => 'Zulässige Überziehung',
        'percent' => 'Prozent',
        'absolute' => 'absolut',
        'help' => [
            'both' => 'Sind beide gesetzt, gilt die kleinere: 10 % von 100 Stunden sind '
                .'10 Stunden, 10 % von 12 Monaten sind mehr als ein Monat — dann gilt '
                .'der Monat.',
            'none' => 'LTA tragen in der Regel keine Toleranz, ein ARC nie. Leer lassen '
                .'heißt: keine.',
            'anchor' => 'Wird überzogen, rechnet das nächste Intervall ab der ALTEN '
                .'Fälligkeit — sonst wandert der Termin bei jeder Nutzung weiter nach '
                .'hinten. Wird zu früh gewartet, rechnet es ab dem tatsächlichen Stand.',
        ],
    ],

    'loading' => [
        'title' => 'Beladeplan',
        'seat' => 'Sitzplatz',
        'arm' => 'Hebelarm',
        'min' => 'Zuladung min',
        'max' => 'Zuladung max',
        'limited_by' => [
            'cg' => 'durch Schwerpunkt begrenzt',
            'mass' => 'durch Höchstmasse begrenzt',
        ],
        'flight_cg' => 'Zulässige Fluggewicht-Schwerpunktlagen',
        'rear' => 'hinten',
        'front' => 'vorn',
        'not_possible' => 'nicht zulässig',
        'missing_inputs' => 'Für den Beladeplan fehlen Angaben: Sitzplätze mit Hebelarm '
            .'und die zulässigen Fluggewicht-Schwerpunktlagen aus dem Flughandbuch.',
        'check_manual' => 'Berechnet aus Leermasse, Leermassen-Schwerpunkt und den '
            .'eingetragenen Grenzen. Das Flughandbuch bleibt maßgeblich — es kann '
            .'Größen enthalten, die hier nicht bekannt sind (Hecktank, Wasserballast, '
            .'feste Trimmgewichte).',
    ],

    'basis' => [
        'since_new' => 'seit Neu (TSN)',
        'since_overhaul' => 'seit Überholung (TSO)',
    ],

    'overhaul' => [
        'performed' => 'Grundüberholung durchgeführt — TSO auf null',
        'reference' => 'Nachweis der Überholung',
        'help' => [
            'explicit' => 'Wird nie aus einer Reparatur gefolgert. Zwei Motoren können '
                .'zum selben Betrieb fahren und verschieden zurückkommen — nur das Papier '
                .'sagt, ob überholt wurde.',
            'tsn_runs_on' => 'Die TSN läuft weiter. Zurückgesetzt wird nur, was seit der '
                .'letzten Überholung zählt.',
        ],
    ],

    'aircraft' => [
        'singular' => 'Luftfahrzeug',
        'plural' => 'Luftfahrzeuge',
        'no_type' => 'kein Muster zugeordnet',
        'field' => [
            'registration' => 'Kennzeichen',
            'model' => 'Muster',
            'manufacturer' => 'Hersteller',
            'serial_number' => 'Werknummer',
            'year_built' => 'Baujahr',
            'holder' => 'Halter',
            'optional_counters' => 'Weitere Zähler',
            'is_active' => 'Im Betrieb',
            'in_service_since' => 'Im Betrieb seit',
            'note' => 'Bemerkung',
        ],
        'help' => [
            'type' => 'Ordne ein Muster zu, dann greift die LTA/TM-Zuordnung exakt statt '
                .'über Namensvergleich — und das Kennblatt hängt daran. Fehlt das Muster '
                .'noch, lässt es sich hier direkt anlegen.',
            'model_free_text' => 'Bleibt Freitext. Wird beim Zuordnen eines Musters '
                .'vorbelegt, ist aber weiter änderbar — wer sein Muster anders schreibt, '
                .'darf das.',
            'registration' => 'Format ist Einstellungssache, nicht fest verdrahtet — '
                .'D-KABC, HB-, OE-, F- kommen alle vor.',
            'mandatory_counters' => 'Flugzeit und Landungen führt jedes Luftfahrzeug — '
                .'das ist gesetzlich zu erfassen und lässt sich nicht abwählen.',
            'optional_counters' => 'Motorlaufzeit nur, wenn tatsächlich ein Zähler '
                .'verbaut ist — nicht jedes Flugzeug mit Motor hat einen. Starts und '
                .'Zyklen, wenn Bauteile daran gemessen werden (z. B. Schleppkupplung).',
        ],
    ],

    'holder' => [
        'singular' => 'Halter',
        'plural' => 'Halter',
        'type' => [
            'club' => 'Verein',
            'private' => 'privat',
        ],
        'field' => [
            'type' => 'Art',
            'user' => 'Benutzerkonto',
            'contact' => 'Kontakt',
        ],
        'help' => [
            'user' => 'Verknüpft den Halter mit einem Mitgliedskonto. Nötig, wenn er '
                .'im AMP für Pilot-Owner-Arbeiten genannt werden soll.',
            'why' => 'Part-ML hängt die Verantwortung für die Aufrechterhaltung der '
                .'Lufttüchtigkeit am Halter. Ein privat gehaltenes Flugzeug in '
                .'Vereinsobhut antwortet seinem Eigentümer, nicht dem Vorstand.',
        ],
    ],

    'reading' => [
        'singular' => 'Zählerstand',
        'plural' => 'Zählerstände',
        'field' => [
            'kind' => 'Zähler',
            'value' => 'Stand',
            'read_at' => 'Abgelesen am',
            'note' => 'Bemerkung',
        ],
        'help' => [
            'absolute' => 'Der abgelesene Stand, nicht die Differenz — so wie er am '
                .'Instrument steht.',
            'append_only' => 'Zählerstände werden nicht überschrieben. Ein falscher '
                .'Eintrag wird durch einen weiteren korrigiert, beide bleiben sichtbar.',
        ],
    ],

    'installation' => [
        'singular' => 'Einbau',
        'plural' => 'Eingebaute Komponenten',
        'field' => [
            'part_name' => 'Bauteil',
            'part_number' => 'Teilenummer',
            'serial_number' => 'Seriennummer',
            'lot' => 'Los',
            'position' => 'Einbauort',
            'installed_at' => 'Eingebaut am',
            'quantity' => 'Menge',
            'removed_at' => 'Ausgebaut am',
            'removal_reason' => 'Grund des Ausbaus',
            'document' => 'Nachweis',
        ],
        'help' => [
            'scope' => 'Alles außer Standard Parts. Schrauben und Nieten interessieren '
                .'niemanden — was ein Form 1 oder CoC mitbringt, gehört in die '
                .'Lebenslaufakte.',
            'carried_usage' => 'Was das Teil schon hinter sich hat, bevor es hier '
                .'eingebaut wurde — z. B. eine überholte Schleppkupplung mit 300 Starts.',
            'no_limits' => 'Nicht jedes Bauteil hat eine Laufzeit. Ein Ölfilter geht mit '
                .'der Motorwartung und ein neuer kommt.',
            'serviceable' => 'Dieselbe Feststellung wie überall: verlangt eine gültige '
                .'Part-66-Lizenz und wird unveränderlich festgeschrieben. Ohne sie geht '
                .'das Teil in den Sperrbestand.',
            'lands_in_store' => 'Ist das Lagermodul aktiv, liegt das Teil jetzt dort — '
                .'mit allen Regeln des Lagers, inklusive der Bindung ans Luftfahrzeug '
                .'ohne Form 1.',
        ],
        'origin' => [
            'stock' => 'aus dem Lager',
            'onboarding' => 'bei Übernahme erfasst',
            'external' => 'von Fremdbetrieb verbaut',
        ],
        'transcribed' => 'abgeschrieben',
        'transcribed_from' => 'Übernommen aus',
        'remove' => 'Bauteil ausbauen',
        'field_removal_reason' => 'Grund des Ausbaus',
        'notification' => [
            'removed' => 'Ausbau gebucht.',
            'refused' => 'Das geht so nicht',
        ],
    ],

    'limits' => [
        'singular' => 'Laufzeitgrenze',
        'plural' => 'Laufzeitgrenzen',
        'add' => 'Laufzeitgrenze anlegen',
        'kind' => 'Art',
        'value' => 'Wert',
        'tolerance_percent' => 'Toleranz (%)',
        'tolerance_absolute' => 'Toleranz (absolut)',
        'added' => 'Laufzeitgrenze angelegt.',
        'source' => 'Quelle',
        'record_done' => 'Wartung abhaken',
        'recorded' => 'Als erledigt gebucht.',
        'done_at' => 'Ausgeführt am',
        'whichever_first' => 'was zuerst eintritt',
        'help' => [
            'multiple' => 'Mehrere Grenzen verschiedener Art sind der Normalfall: Eine '
                .'Tost-Schleppkupplung läuft „2 Jahre oder 500 Starts, was zuerst '
                .'eintritt". Fällig ist die früheste.',
        ],
    ],

    'actions' => 'Aktionen',

    'external' => [
        'approval_lapsed_short' => 'Zulassung abgelaufen',
        'refused' => [
            'approval_lapsed' => 'Die Zulassung von :shop ist am :date abgelaufen. Was von '
                .'dort zurückkommt, trägt eine Bescheinigung, die nichts wert ist — und bei '
                .'einem ganzen Luftfahrzeug wiegt das schwerer als bei einem Bauteil.',
        ],
        'singular' => 'Externer Auftrag',
        'plural' => 'Externe Aufträge',
        'commission' => 'Extern vergeben',
        'receive' => 'Rückkehr erfassen',
        'record_part' => 'Verbautes Teil erfassen',
        'release' => 'Freigabe erfassen',
        'awaiting_release' => 'Zurück von :shop, aber noch keine Freigabe erfasst',
        'report_of' => 'Arbeitsbericht :shop, :reference',
        'work_of' => 'Arbeiten bei :shop',
        'state' => [
            'commissioned' => 'vergeben',
            'returned' => 'zurück',
            'released' => 'freigegeben',
            'cancelled' => 'storniert',
        ],
        'released_by' => [
            'external' => 'durch den Fremdbetrieb',
            'internal' => 'durch uns',
        ],
        'field' => [
            'organisation' => 'Betrieb aus dem Verzeichnis',
            'shop_name' => 'Betrieb',
            'shop_approval' => 'Betriebsnummer',
            'order_reference' => 'Auftragsnummer',
            'scope' => 'Beauftragte Arbeiten',
            'sent_at' => 'Vergeben am',
            'expected_back_at' => 'Erwartet zurück',
            'returned_at' => 'Zurück am',
            'report_reference' => 'Arbeitsbericht',
            'release_reference' => 'Freigabe-Nummer',
            'signatory' => 'Unterzeichner',
        ],
        'help' => [
            'organisation' => 'Wird ein Betrieb gewählt, kommen Name und Zulassungsnummer '
                .'von dort — und eine abgelaufene Zulassung fällt sofort auf. Ohne Auswahl '
                .'bleibt der Freitext daneben.',
            'two_steps' => 'Zurück und freigegeben sind zwei Einträge. Das Flugzeug steht '
                .'in der Halle und sieht fertig aus — genau dann wird es geflogen, weil '
                .'es ja „wieder da" ist.',
            'who_releases' => 'Unterschreibt der Betrieb, schreiben wir seine Unterschrift '
                .'und Betriebsnummer auf — die Verantwortung liegt bei ihm. '
                .'Unterschreiben wir, nimmt jemand hier Arbeiten ab, bei denen er nicht '
                .'dabei war. Das ist eine Feststellung und verlangt eine Qualifikation.',
            'part_origin' => 'Solche Teile kamen aus fremdem Bestand und wurden von uns '
                .'nie gesehen. Sie bleiben dauerhaft so gekennzeichnet — der Nachweis '
                .'ist der Arbeitsbericht des Betriebs.',
        ],
        'notification' => [
            'commissioned' => 'Auftrag an :shop erfasst.',
            'returned' => 'Rückkehr erfasst. Die Freigabe fehlt noch.',
            'released_internal' => 'Freigabe erfasst — unter Ihrer Qualifikation.',
            'released_external' => 'Freigabe des Fremdbetriebs erfasst.',
            'refused' => 'Das geht so nicht',
        ],
    ],

    'onboarding' => [
        'title' => 'Luftfahrzeug übernehmen',
        'component' => 'Vorhandenes Bauteil erfassen',
        'arrival_reading' => 'Zählerstand bei Übernahme',
        'recorded' => 'Bauteil erfasst.',
        'field' => [
            'onboarded_at' => 'Übernommen am',
            'transcribed_from' => 'Übernommen aus welchem Dokument',
            'installed_at' => 'Eingebaut am (laut Unterlagen)',
            'since_new' => 'Betriebszeit seit Neu (TSN)',
            'since_overhaul' => 'Betriebszeit seit Überholung (TSO)',
        ],
        'help' => [
            'what' => 'Ein Luftfahrzeug kommt nie leer. Auch ein fabrikneues hat Bauteile '
                .'drin, und ein sechzig Jahre altes ist für den Betrieb trotzdem neu. '
                .'Das ist keine Migration, sondern ein normaler Vorgang.',
            'transcribed_from' => 'Pflichtangabe. Ein abgeschriebener Eintrag ist nur so '
                .'gut wie das Dokument dahinter — „Betriebszeitenübersicht des '
                .'Vorbetriebs vom 12.03.2019" beantwortet die Frage „woher wissen Sie '
                .'das", ein leeres Feld nicht.',
            'installed_at' => 'Das Datum aus den Unterlagen, nicht heute. Heute '
                .'einzutragen würde jede Kalendergrenze am Übernahmetag neu starten und '
                .'einer fünfzehn Jahre alten Kupplung zwei frische Jahre schenken.',
            'marked' => 'Solche Einträge bleiben dauerhaft als „bei Übernahme erfasst" '
                .'gekennzeichnet. Sie sind gültig, aber es ist die Aufzeichnung eines '
                .'anderen — nicht die eigene.',
        ],
    ],

    'airworthiness' => [
        'title' => 'Noch offen',
        'nothing_found' => 'Nichts gefunden.',
        'not_a_verdict' => 'Das ist keine Feststellung der Lufttüchtigkeit, sondern eine '
            .'Liste dessen, was offen ist. „Nichts gefunden" heißt nicht „lufttüchtig" — '
            .'das beurteilt eine qualifizierte Person am Flugzeug.',
        'expired_on' => 'abgelaufen am :date',
        'minimum_missing' => 'Mindestausrüstung ausgebaut und nicht ersetzt',
        'blocking' => 'blockierend',
        'warning' => 'Hinweis',
    ],

    /*
     * ─────────────────────────────────────────────────────────────────────────
     * DAS WÄGEBLATT, wie es gedruckt und ausgefüllt wird.
     *
     * Eigener Zweig neben „weighing", weil hier BLATTTEXTE stehen -- die
     * Beschriftungen des Formulars in genau der Reihenfolge und Schreibweise,
     * in der jemand sie abschreibt. Sie folgen der Gliederung klassischer
     * Wägeformulare: Wer vom Papier überträgt, soll Zeile für Zeile
     * wiederfinden, was er sucht, statt zu übersetzen.
     *
     * Was hier bewusst NICHT steht: der Name eines Verbandes, ein Briefkopf,
     * eine fremde Ausgabebezeichnung. Die Felder gehören keinem Verband -- sie
     * stehen im Kennblatt und folgen aus der Physik.
     * ─────────────────────────────────────────────────────────────────────────
     */
    'sheet' => [
        // Mit :variant, weil die Ueberschrift die Blattart traegt -- drei
        // Blaetter, wie auf dem Papier.
        'title' => 'Massenübersicht :variant',
        'registration' => 'Kennzeichen',
        'model' => 'Muster',
        'serial_number' => 'Werk-Nr.',
        'order_reference' => 'Auftr.-Nr.',
        'datum' => 'Bezugspunkt B.P.',
        'reference_line' => 'Bezugslinie horizontal B.L.',
        'reference_plane' => 'Bezugsebene B.E.',
        'fuselage_plane' => 'Rumpfbezugsebene RBE',
        'empty_cg_from_plane' => 'Leermassen-Schwerpunkt von B.E. [mm]',
        'airworthiness' => 'Lufttüchtigkeit',
        'max_flight_mass' => 'Höchstzul. Fluggewicht',
        'add_configuration' => 'Weitere Konfiguration hinzufügen',
        'support' => 'Auflage',

        'weighing' => 'WÄGUNG',
        'empty_masses' => 'Leermassen',
        'non_lifting' => 'M.N.T.',
        'result' => 'ERGEBNIS',
        'useful_load' => 'Zuladung',
        // Warum in der M.N.T.-Spalte zwei verschiedene Zahlen stehen: die
        // gewogene Summe unten, die abgeleitete Zuladung darueber.
        'non_lifting_note' => 'Die M.N.T. im Ergebnis ist die gewogene Summe der '
            .'Bauteile. Die Zuladung darüber ist abgeleitet: Im Flug gehört sie zu '
            .'den nichttragenden Teilen, deshalb ist sie das, was die Höchstmasse '
            .'der N.T. laut Kennblatt über den gewogenen Bauteilen noch frei lässt.',

        'limits' => 'MASSENGRENZEN',
        'empty_mass' => 'Leermasse',
        'max_mass' => 'Höchstmasse ohne Wasserballast',
        'max_mass_water' => 'Höchstmasse mit Wasserballast',
        'max_non_lifting' => 'Höchstmasse der N.T. einschließlich Zuladung laut Kennblatt',
        'load_distribution' => 'Aufteilung der Zuladung siehe Anweisung im Flughandbuch!',
        'cockpit_load' => 'Zuladung im Cockpit',
        'min' => 'min',
        'max' => 'max',
        'remarks' => 'Bemerkungen',

        'cg_determination' => 'SCHWERPUNKTERMITTLUNG',
        'gross' => 'Brutto',
        'tare' => 'Tara',
        'netto' => 'Netto',
        'arm' => 'Hebelarm',
        'moment' => 'Moment',
        'sum_one' => 'Summe I',
        'sum_two' => 'Summe II',
        'deductions' => 'ABZÜGE (ausfliegbar)',
        'volume' => 'Menge',
        'density' => 'Dichte',
        'mass' => 'Masse',
        'type_data' => 'KENNBLATTDATEN',
        'at_empty_mass' => 'bei Leermasse',

        'empty_cg_bar' => 'LEERGEWICHTS-SCHWERPUNKTLAGE',
        'empty_mass_and_cg' => 'Leermasse und Schwerpunktlage',
        'behind_datum' => 'mm hinter B.P.',
        'cg_not_computable' => 'Noch nicht berechenbar — es fehlen Auflagen, Massen oder Hebelarme.',
        'cg_range' => 'Schwerpunktbereich laut Flughandbuch',
        'cg_range_line' => 'Schwerpunktbereich laut Flughandbuch von :from mm bis :to mm '
            .'bei Leermasse :mass kg.',

        'confirm' => [
            'cg_in_range' => 'Die ermittelte Leermassen-Schwerpunktlage liegt im zulässigen Bereich.',
            'cg_out_of_range' => 'Die ermittelte Leermassen-Schwerpunktlage liegt NICHT im '
                .'zulässigen Bereich:',
            'equipment' => 'Die Ausrüstung bei der Wägung siehe Ausrüstungsliste vom :date.',
            'loading_plan' => 'Der Beladeplan im Luftfahrzeug und im Flughandbuch wurde '
                .'berichtigt bzw. stimmt mit diesem Ergebnis überein.',
        ],

        'equipment_list_dated' => 'Ausrüstungsliste vom',

        'sign' => [
            'place_date' => 'Ort und Datum',
            'printed_name' => 'Name in Druckbuchstaben',
            'stamp' => 'Stempel',
            'certifying_staff' => 'Freigabeberechtigter',
        ],

        /*
         * Eigene Blattbezeichnung und eigener Stand -- nicht die Ausgabe eines
         * fremden Formulars. Die Gliederung ist übernommen, das Blatt ist es
         * nicht.
         */
        'foot' => [
            'glider' => 'Massenübersicht Segelflugzeug',
            'powered' => 'Massenübersicht Motorflugzeug',
            'revision' => 'Aeronance — Ausgabe 1',
        ],
    ],

    'weighing' => [
        'add_support' => 'Weitere Auflage hinzufügen',
        'print' => 'Drucken',
        'locked' => 'Das Blatt ist abgezeichnet und damit eingefroren.',
        'save_failed' => 'Das Blatt konnte nicht gespeichert werden.',
        'signed_off_note' => 'Dieses Blatt ist abgezeichnet. Es lässt sich '
            .'ansehen und drucken, aber nicht mehr ändern — eine Korrektur ist '
            .'eine neue Wägung.',
        'add_component' => 'Weitere Zeile hinzufügen',
        'add_deduction' => 'Weiteren Behälter hinzufügen',
        'sketch_pending' => 'Die Skizze zeichnet sich, sobald die Auflagen mit '
            .'ihren Hebelarmen eingetragen sind.',
        'singular' => 'Wägung',
        'plural' => 'Wägungen',
        'kind' => [
            'glider' => 'Segelflugzeug',
            'powered' => 'Flugzeug / Motorsegler',
        ],
        // Die Überschrift des Blatts — drei, wie auf dem Papier.
        'variant' => [
            'glider' => 'Segelflugzeug',
            'motorglider' => 'Motorsegler',
            'aeroplane' => 'Flugzeug',
        ],
        'undercarriage' => [
            'tailwheel_one_main' => 'Spornrad mit einem Hauptrad',
            'tailwheel_two_mains' => 'Spornrad mit zwei Haupträdern',
            'tricycle' => 'Bugrad (Dreibein)',
        ],
        'section' => [
            'component' => 'Wägung (Bauteile)',
            'support' => 'Schwerpunktermittlung',
            'deduction' => 'Abzüge',
            'configuration' => 'Zugelassene Konfigurationen',
            'limits' => 'Massengrenzen laut Kennblatt',
            'result' => 'Ergebnis',
        ],
        'field' => [
            'weighed_at' => 'Wägedatum',
            'place' => 'Ort',
            'order_reference' => 'Auftr.-Nr.',
            'valid_until' => 'Gültig bis',
            'datum_reference' => 'Bezugspunkt B.P.',
            'reference_line' => 'Bezugslinie horizontal B.L.',
            'front_support_arm' => 'Hebelarm a (B.P. → vordere Auflage)',
            'support_distance' => 'Abstand b (vordere → hintere Auflage)',
            'empty_mass' => 'Leermasse',
            'empty_cg' => 'Leermassen-Schwerpunkt',
            'non_lifting' => 'Masse der nichttragenden Teile',
            'useful_load' => 'Zuladung',
            'cg_range' => 'Schwerpunktbereich laut Flughandbuch',
            'cg_range_at_mass' => 'gilt bei Leermasse',
            'sheet_variant' => 'Blattart',
            'undercarriage' => 'Fahrwerk',
            'component' => 'Bauteil',
            'support' => 'Auflage',
            'tank' => 'Behälter',
            'max_mass' => 'Höchstmasse ohne Wasserballast',
            'max_mass_water' => 'Höchstmasse mit Wasserballast',
            'max_non_lifting' => 'Höchstmasse der N.T.',
            'cockpit_load' => 'Zuladung im Cockpit',
            'equipment_list_dated' => 'Ausrüstungsliste vom',
            'gross' => 'Brutto',
            'tare' => 'Tara',
            'netto' => 'Netto',
            'arm' => 'Hebelarm',
            'moment' => 'Moment',
            'volume' => 'Menge [l]',
            'density' => 'Dichte [kg/l]',
        ],
        'help' => [
            // Verbandsneutral formuliert: Aeronance bildet die Gliederung
            // klassischer Wägeformulare ab und ist keines Verbandes Blatt.
            'arm_sign' => 'Mit Vorzeichen. Die beiden Formeln auf den üblichen '
                .'Wägeformularen („− a" und „+ a") sind dieselbe Gleichung — sie '
                .'unterscheiden sich nur darin, ob der Bezugspunkt vor oder hinter '
                .'der vorderen Auflage liegt. Mit vorzeichenbehaftetem a entscheidet '
                .'das Vorzeichen.',
            'non_lifting' => 'Zweite Spalte je Bauteil. Die Masse der nichttragenden '
                .'Teile hat eine eigene Grenze im Kennblatt — eine Fläche trägt, ein '
                .'Rumpf nicht, und das lässt sich aus keiner Summe herauslesen.',
            'deduction_arm' => 'Auch Abzüge brauchen ihren Hebelarm: Kraftstoff aus '
                .'einem Flügeltank zu nehmen verschiebt den Schwerpunkt und macht nicht '
                .'nur leichter.',
            'remember_on_type' => 'Dann bekommt jedes weitere Luftfahrzeug dieses Musters '
                .'die Angabe von selbst. Vorhandene Angaben am Muster werden nicht '
                .'überschrieben — die änderst du beim Muster.',
            'change_sheet' => 'Ändert die Überschrift des Blatts und damit den Rechenweg. '
                .'Abschnitte, in denen noch keine Zahl steht, werden durch die Vorlage der '
                .'neuen Blattart ersetzt; wo bereits Zahlen stehen, bleibt alles, wie es '
                .'ist.',
            'stored' => 'Ergebnis wird beim Speichern berechnet und festgeschrieben. Ein '
                .'unterschriebenes Dokument behält seine Zahlen — sonst würde eine '
                .'spätere Codeänderung eine fremde Unterschrift über ein anderes '
                .'Ergebnis setzen.',
        ],
        'finding' => [
            'cg_out_of_range' => 'Schwerpunkt :value mm liegt außerhalb des zulässigen '
                .'Bereichs (:from bis :to mm).',
            'no_useful_load' => 'Die Leermasse erreicht oder übersteigt die Höchstmasse — '
                .'es bleibt keine Zuladung.',
            'non_lifting_exceeded' => 'Masse der nichttragenden Teile :value kg über der '
                .'zulässigen Grenze von :max kg.',
        ],
        'in_range' => 'Die ermittelte Leermassen-Schwerpunktlage liegt im zulässigen Bereich.',
        'signed_off' => 'Abgezeichnet',
        'draft' => 'Entwurf',
        'sign_off' => 'Speichern und drucken',
        'signed_off_now' => 'Wägung abgezeichnet und festgeschrieben.',
        'sign_off_warning' => 'Damit werden die Werte unveränderlich festgesetzt. Eine '
            .'Korrektur ist danach eine neue Wägung — so wie auf Papier auch, denn das '
            .'alte Blatt trägt eine Unterschrift.',
        'action' => [
            'change_sheet' => 'Blattart ändern',
        ],
        'setup_origin' => [
            'type' => 'Aus dem Muster übernommen.',
            'previous' => 'Aus der letzten abgezeichneten Wägung dieses Luftfahrzeugs '
                .'übernommen.',
            'propulsion' => 'Vorschlag aus dem Antrieb — bitte prüfen. Ein Segelflugzeug '
                .'mit Hilfstriebwerk ist motorisiert und wird trotzdem nicht zwangsläufig '
                .'auf dem Flugzeugblatt gewogen.',
        ],
        'remember_on_type' => 'Beim Muster hinterlegen',
        'remembered' => 'Blattart und Fahrwerk sind jetzt beim Muster :type hinterlegt.',
        'sheet_changed' => 'Blattart auf „:sheet" umgestellt.',
        'rows_kept' => 'Stehen geblieben, weil dort schon Zahlen eingetragen sind: '
            .':sections. Diese Zeilen bitte selbst prüfen — die Vorlage der neuen '
            .'Blattart wurde dafür nicht eingesetzt.',
        'new_from_last' => 'Neue Wägung (Werte übernehmen)',
        'prepared' => 'Neue Wägung angelegt.',
        'carried_over' => 'Handbuchwerte, Bezugspunkt-Definition und Sitzplätze aus der '
            .'Wägung vom :date übernommen. Die Abstände der Waagen zum Bezugspunkt '
            .'bleiben leer — die werden jedes Mal neu gemessen.',
        'no_previous' => 'Keine abgezeichnete Vorgängerwägung vorhanden.',
        'figures_drifted' => 'Die festgeschriebenen Zahlen stimmen nicht mehr mit den '
            .'Zeilen überein. Entweder wurden Zeilen nach der Unterschrift geändert, '
            .'oder die Rechnung selbst hat sich geändert. Die alte Zahl ist die, die '
            .'jemand unterschrieben hat — nicht einfach neu berechnen.',
    ],

    'print' => [
        'label' => 'Drucken',
        'equipment_list' => 'Ausrüstungsverzeichnis',
        'operating_times' => 'Betriebszeitenübersicht',
    ],

    'equipment' => [
        'present' => 'Vorhanden',
        'minimum' => 'Mindestausrüstung',
        'type_designation' => 'Baumuster',
        'manufacturer' => 'Hersteller',
        'lever_arm' => 'Hebelarm (mm)',
        'help' => [
            'minimum' => 'Ohne dieses Teil ist das Luftfahrzeug nicht verwendbar. Das '
                .'zusätzliche Garmin darf raus, die Analoganzeige nicht.',
            'lever_arm' => 'Vom Bezugspunkt, mit Vorzeichen. Das Ausrüstungsverzeichnis '
                .'ist zugleich Teil des Wägungsnachweises.',
        ],
    ],

    'pilot_owner' => [
        'singular' => 'Pilot-Owner-Nennung',
        'plural' => 'Pilot-Owner-Nennungen',
        'field' => [
            'person' => 'Person',
            'valid_until' => 'Gültig bis',
        ],
        'remove' => 'Aus dem AMP austragen',
        'removed' => ':name ist ausgetragen. Die Berechtigung endet, der Datensatz bleibt.',
        'notification' => [
            'listed' => ':name ist im AMP genannt.',
        ],
        'help' => [
            'ends_not_deletes' => 'Austragen beendet die Berechtigung, löscht sie aber '
                .'nicht: Sie war bis heute wahr, und ein verschwundener Datensatz kann '
                .'nicht beantworten, ob eine Arbeit im Frühjahr gedeckt war.',
            'open_ended' => 'Leer lassen für unbefristet. Die Nennung endet durch '
                .'Austragen, nicht durch Löschen — sie war bis dahin wahr.',
            'source' => 'Die Berechtigung folgt der Nennung im Instandhaltungsprogramm, '
                .'nicht dem Eigentum. Wer im AMP steht, darf — auch an einem fremden '
                .'Flugzeug.',
        ],
    ],

    'review' => [
        'singular' => 'Nachprüfung (ARC)',
        'plural' => 'Nachprüfungen',
        'help' => [
            'carries' => 'innerhalb 90 Tage vor Ablauf ausgestellt, altes Datum trägt',
            'full_term' => 'volle Laufzeit ab Ausstellung (364 Tage)',
        ],
        'notification' => [
            'issued' => 'Nachprüfung erfasst, gültig bis :date.',
        ],
        'field' => [
            'certificate_reference' => 'Bescheinigungsnummer',
            'issued_at' => 'Ausgestellt am',
            'valid_until' => 'Gültig bis',
            'issued_by_name' => 'Ausgestellt von',
            'issued_by_approval' => 'Betriebsnummer',
        ],
    ],

    'document' => [
        'singular' => 'Dokument',
        'plural' => 'Dokumente',
        'type' => [
            'amp' => 'Instandhaltungsprogramm (AMP)',
            'weighing_report' => 'Wägebericht',
            'noise' => 'Lärmzeugnis',
            'radio' => 'Funkgenehmigung',
            'insurance' => 'Versicherungsnachweis',
            'registration' => 'Eintragungsschein',
            'flight_manual' => 'Flughandbuch',
            'crs' => 'Freigabebescheinigung (CRS)',
            'other' => 'Sonstiges',
        ],
        'crs_title' => 'Freigabebescheinigung :number',
        'field' => [
            'title' => 'Bezeichnung',
            'reference' => 'Nummer',
            'issued_at' => 'Ausgestellt am',
            'valid_until' => 'Gültig bis',
            'issued_by' => 'Ausgestellt von',
            'file' => 'Datei',
        ],
        'no_expiry' => 'ohne Ablauf',
        'help' => [
            'file' => 'PDF, JPG oder PNG. Ohne Datei wird nur die Frist geführt — '
                .'für Papier, das im Ordner bleibt. Mit Datei wird die Bezeichnung '
                .'in der Übersicht zum Link.',
            'valid_until' => 'Leer lassen, wenn das Dokument nicht abläuft. Manche '
                .'Luftfahrzeuge brauchen z. B. alle vier Jahre eine Wägung, andere nur '
                .'bei Bedarf — leer heißt „läuft nicht ab", nicht „vergessen".',
            'amp' => 'Das AMP wird angehängt, nicht ausgefüllt. Was sich daraus an '
                .'Intervallen ergibt, wird an den Laufzeitgrenzen der Bauteile '
                .'eingetragen — dort, wo man danach handeln kann.',
        ],
    ],

    'manual' => [
        'singular' => 'Wartungsunterlage',
        'plural' => 'Wartungsunterlagen',
        'revision_short' => 'Rev. :revision',
        'current' => 'Gültig',
        'superseded' => 'Abgelöst',
        'withdrawn' => 'Zurückgezogen',
        'not_yet_effective' => 'Gilt ab :date',
        'kind' => [
            'maintenance' => 'Wartungshandbuch',
            'parts' => 'Ersatzteilkatalog',
            'repair' => 'Reparaturhandbuch',
            'flight_manual' => 'Flughandbuch',
            'programme' => 'Instandhaltungsprogramm',
            'other' => 'Sonstige',
        ],
        'field' => [
            'kind' => 'Art',
            'title' => 'Bezeichnung',
            'reference' => 'Dokumentnummer',
            'revision' => 'Revisionsstand',
            'revision_date' => 'Ausgegeben am',
            'effective_from' => 'Anzuwenden ab',
            'superseded_at' => 'Abgelöst am',
            'withdrawn_at' => 'Zurückgezogen am',
            'withdrawn_reason' => 'Grund',
            'scope' => 'Gilt für',
            'note' => 'Bemerkung',
            'file' => 'Dokument',
        ],
        'help' => [
            'revision' => 'Wie der Hersteller ihn schreibt — „Rev. 12", „Ausgabe 3", '
                .'„Issue B". Freitext, weil es kein gemeinsames Schema gibt.',
            'supersede' => 'Eine neue Revision überschreibt nichts: Sie entsteht als neuer '
                .'Eintrag und löst den alten ab. Nur so bleibt beantwortbar, nach welchem '
                .'Stand im Mai gearbeitet wurde.',
            'scope' => 'Das Wartungshandbuch gilt meist fürs Muster, das '
                .'Instandhaltungsprogramm oft für das einzelne Luftfahrzeug.',
            'file' => 'Das Dokument dieses Revisionsstands (PDF; Scans auch als JPG/PNG). '
                .'Es liegt geschützt ab und ist nur für angemeldete Mitglieder abrufbar. '
                .'Ohne Datei bleibt der Eintrag ein Verweis auf den Papierordner.',
        ],
        'action' => [
            'supersede' => 'Neue Revision',
            'superseded' => 'Neue Revision eingetragen, die alte ist abgelöst.',
            'withdraw' => 'Zurückziehen',
            'withdrawn' => 'Zurückgezogen.',
            'failed' => 'Nicht möglich',
            'open' => 'Öffnen',
        ],
        'filter' => [
            'current' => 'Nur geltende',
            'kind' => 'Art',
        ],
        'empty' => [
            'heading' => 'Keine Wartungsunterlagen',
            'description' => 'Aufgenommen wird, wonach gearbeitet wird — mit dem Stand, den '
                .'der Hersteller gerade gültig hält.',
        ],
        'refused' => [
            'title_missing' => 'Die Unterlage braucht eine Bezeichnung.',
            'revision_missing' => 'Ohne Revisionsstand ist der Eintrag wertlos — genau die '
                .'Angabe, wegen der es diese Liste gibt, wäre dann leer.',
            'not_current' => 'Diese Unterlage gilt nicht mehr. Abgelöst oder zurückgezogen '
                .'wird nur, was gerade gültig ist.',
            'same_revision' => 'Der Stand „:revision" steht schon da. Derselbe Stand zweimal '
                .'ist keine Revision, sondern ein Tippfehler.',
            'withdraw_without_reason' => 'Zurückziehen braucht einen Grund — sonst steht in '
                .'einem Jahr die Frage im Raum, warum die Unterlage verschwunden ist.',
        ],
    ],

];
