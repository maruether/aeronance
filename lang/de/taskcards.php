<?php

declare(strict_types=1);

return [

    'module' => [
        'title' => 'Arbeitskarten',
        'description' => 'Vorgänge, Arbeitskarten, Befunde und Arbeitszeiten. Liefert '
            .'die Datenbasis für das Erfahrungslogbuch nach Part-66.',
    ],

    'work_order' => [
        'singular' => 'Vorgang',
        'plural' => 'Vorgänge',
        'field' => [
            'number' => 'Nummer',
            'title' => 'Bezeichnung',
            'description' => 'Beschreibung',
            'opened_at' => 'Eröffnet am',
            'closed_at' => 'Abgeschlossen am',
            'state' => 'Stand',
        ],
        'state' => [
            'open' => 'offen',
            'closed' => 'abgeschlossen',
            'cancelled' => 'storniert',
        ],
        'action' => [
            'open' => 'Vorgang eröffnen',
            'close' => 'Vorgang abschließen',
            'add_card' => 'Arbeitskarte anlegen',
            'quick_repair' => 'Schnellreparatur',
        ],
        'quick_repair_placeholder' => 'Reifen Hauptfahrwerk getauscht',
        'help' => [
            'close' => 'Für Vorgänge ohne freizugebende Arbeit — irrtümlich eröffnet oder '
                .'nur stornierte Karten. Abgezeichnete Arbeit endet mit ihrer Freigabe, '
                .'und die schließt den Vorgang von selbst.',

        ],
    ],

    'inspection' => [
        'heading' => 'Unabhängige Kontrolle',
        'awaiting' => 'Kontrolle ausstehend',
        'done' => 'Kontrolliert',
        'help' => [
            'critical' => 'Nur für Arbeiten, bei denen ein Fehler unmittelbar gefährlich '
                .'wird — Steuerungsanschlüsse an erster Stelle. Sparsam setzen: Wäre jede '
                .'Karte kritisch, wäre die Kontrolle nach zwei Wochen ein Haken, den man '
                .'setzt, ohne hinzusehen.',
            'reason' => 'Worauf der Kontrolleur sehen soll — „Querruderanschluss", '
                .'„Höhenruder-Anlenkung getrennt". Eine Karte, die nur „kritisch" sagt, '
                .'schickt ihn suchen.',
            'note' => 'Was Sie tatsächlich geprüft haben, nicht was zu prüfen war. '
                .'„Anlenkung beidseitig gezogen, Sicherung sichtbar" ist ein Nachweis; '
                .'„kontrolliert" ist eine Behauptung.',
            'four_eyes' => 'Wer an der Karte gearbeitet hat, kann sie nicht kontrollieren '
                .'— auch nicht mit Lizenz. Darum geht es.',
        ],
        'refused' => [
            'not_critical' => 'Diese Karte ist nicht als kritische Arbeit markiert. Eine '
                .'Kontrolle ohne Anlass wäre ein Nachweis über nichts.',
            'not_completed' => 'Die Arbeit ist noch nicht fertig gemeldet. Vorher gibt es '
                .'nichts zu kontrollieren.',
            'card_closed' => 'Diese Karte ist bereits :state.',
            'already_inspected' => 'Diese Karte wurde bereits kontrolliert (:name). Eine '
                .'zweite Kontrolle wäre von der ersten nicht zu unterscheiden.',
            'no_permission' => 'Für die unabhängige Kontrolle fehlt die Berechtigung '
                .'„:permission".',
            'own_work' => 'Wer an dieser Karte gearbeitet hat, kann sie nicht kontrollieren. '
                .'Genau darum geht es: Wer eine Steuerung angeschlossen hat, sieht seinen '
                .'eigenen Fehler nicht — er bringt beim Nachsehen dieselbe Erwartung mit, '
                .'die ihn beim Anschließen geleitet hat.',
            'note_missing' => 'Bitte eintragen, was kontrolliert wurde. Ein leeres Feld '
                .'hätte man auch ohne Hinsehen abschicken können.',
            'certify_without_inspection' => 'Diese Karte ist als kritische Arbeit markiert '
                .'und noch nicht unabhängig kontrolliert. Ohne die Kontrolle keine Freigabe '
                .'— sonst entstünde der Nachweis genau dann nicht, wenn es eilig ist.',
        ],
    ],

    'card' => [
        'singular' => 'Arbeitskarte',
        'plural' => 'Arbeitskarten',
        'field' => [
            'title' => 'Arbeit',
            'instruction' => 'Arbeitsanweisung',
            'manual_reference' => 'Gearbeitet nach',
            'ata_chapter' => 'ATA-Kapitel',
            'activity_kind' => 'Tätigkeitsart',
            'work_performed' => 'Ausgeführte Arbeit',
            'cancellation_reason' => 'Grund der Stornierung',
            'completed_by' => 'Fertig gemeldet von',
            'certified_by' => 'Abgezeichnet von',
            'critical' => 'Kritische Arbeit',
            'critical_reason' => 'Woran genau',
            'inspected_by' => 'Unabhängig kontrolliert von',
            'inspection_note' => 'Was kontrolliert wurde',
            'for_limit' => 'Erledigt Laufzeitgrenze',
        ],
        'action' => [
            'complete' => 'Fertig melden',
            'inspect' => 'Unabhängig kontrollieren',
            'certify' => 'Abzeichnen',
            'cancel' => 'Stornieren',
            'record_time' => 'Arbeitszeit erfassen',
        ],
        'help' => [
            'time_here' => 'Optional: Ihre Arbeitszeit gleich mit erfassen (z. B. „1:30"). Für weitere Personen weiter über „Zeit erfassen".',
            'two_signatures' => 'Zwei Unterschriften: Wer die Arbeit gemacht hat, meldet '
                .'sie fertig. Wer qualifiziert ist, zeichnet sie danach ab. Das ist '
                .'nicht dasselbe und soll es auch nicht sein.',
            'work_performed' => 'Was tatsächlich gemacht wurde — die Anweisung sagt, was '
                .'gefordert war, nicht was passiert ist.',
            'ata' => 'Frei eintragbar. Im Segelflug wird ATA oft nicht oder nur grob '
                .'geführt; eine feste Liste würde erzwingen, irgendetwas Passendes zu '
                .'suchen, wo nichts passt.',
            'times_first' => 'Ohne erfasste Arbeitszeit lässt sich die Karte nicht fertig '
                .'melden. Das Erfahrungslogbuch wird aus diesen Einträgen abgeleitet — '
                .'eine Karte ohne Zeiten hat es für keine Lizenz je gegeben.',
            'cancel' => 'Eine abgezeichnete Karte wird nie storniert — das löschte eine '
                .'Unterschrift. Dafür gibt es eine neue Karte.',
            'certify_discharges' => 'Wurde die Karte gegen eine Laufzeitgrenze angelegt, '
                .'ist diese mit dem Abzeichnen erledigt.',
            'manual_reference' => 'Nach welcher Unterlage in welcher Revision gearbeitet '
                .'wird. Wird als Abschrift auf der Karte festgehalten — eine spätere '
                .'Revision ändert nichts daran, was hier stand.',
        ],
    ],

    'state' => [
        'open' => 'offen',
        'completed' => 'fertig gemeldet',
        'certified' => 'abgezeichnet',
        'cancelled' => 'storniert',
    ],

    'activity' => [
        'inspection' => 'Prüfung',
        'maintenance' => 'Wartung',
        'repair' => 'Reparatur',
        'modification' => 'Änderung',
        'ad_compliance' => 'LTA-Durchführung',
        'other' => 'Sonstiges',
    ],

    'participation' => [
        'executed' => 'ausgeführt',
        'assisted' => 'unterstützt',
        'supervised' => 'beaufsichtigt',
    ],

    'time' => [
        'singular' => 'Arbeitszeit',
        'plural' => 'Arbeitszeiten',
        'field' => [
            'person' => 'Person',
            'minutes' => 'Dauer',
            'participation' => 'Art der Mitwirkung',
            'worked_on' => 'Am',
        ],
        'none' => 'Keine Arbeitszeit erfasst.',
        'invalid' => 'Als Dauer verstehe ich „90" (Minuten) oder „1:30" (Stunden:Minuten).',
        'help' => [
            'minutes' => 'Wie auf dem Zettel: „1:45" — oder in Minuten, „90" wird '
                .'beim Verlassen des Feldes zu „1:30".',
            'per_person' => 'Je Person und Karte. Das Erfahrungslogbuch zählt, wer was '
                .'wie lange gemacht hat — und ausgeführt ist nicht dasselbe wie '
                .'unterstützt.',
        ],
    ],

    'finding' => [
        'singular' => 'Befund',
        'plural' => 'Befunde',
        'field' => [
            'title' => 'Befund',
            'description' => 'Beschreibung',
            'is_blocking' => 'Verhindert den Betrieb',
            'found_on' => 'Gefunden am',
            'deferred_until' => 'Zurückgestellt bis',
            'deferral_reason' => 'Begründung der Zurückstellung',
            'resolution' => 'Behebung',
            'open_new_order' => 'Neuen Vorgang dafür eröffnen',
        ],
        'empty' => [
            'heading' => 'Keine offenen Befunde',
            'description' => 'Befunde entstehen über den Knopf „Befund melden" oben '
                .'rechts (für jeden mit Melderecht) oder aus einem Vorgang heraus — '
                .'Aktion „Befund erfassen" auf der Vorgangsseite. Diese Liste ist die '
                .'flottenweite Übersicht: Hier wird eingeplant, zurückgestellt, '
                .'behoben oder verworfen. Erledigte Befunde blendet der Filter oben aus.',
        ],
        'action' => [
            'record' => 'Befund erfassen',
            'schedule' => 'Arbeitskarte anlegen',
            'raise_card' => 'Arbeitskarte anlegen',
            'defer' => 'Zurückstellen',
            'resolve' => 'Als behoben erfassen',
            'dismiss' => 'Als kein Befund verwerfen',
        ],
        'card_title' => 'Befunde :numbers',
        'card_title_many' => 'Befunde: :count Punkte',
        'scheduled' => 'Arbeitskarte :card angelegt.',
        'deferral_lapsed' => 'Zurückstellung am :date abgelaufen',
        'help' => [
            'schedule' => 'Angeboten werden alle offenen Befunde dieses Luftfahrzeugs, '
                .'nicht nur die aus diesem Vorgang — ein im März gefundener Riss wird '
                .'bei nächster Gelegenheit erledigt, und genau das ist ein Vorgang.',
            'stays_open' => 'Der Befund bleibt offen, bis die Karte abgezeichnet ist. '
                .'Erst dann kann jemand ehrlich sagen, dass die Sache erledigt ist.',
            'why_own' => 'Ein Befund ist etwas anderes als die Karte, bei der er '
                .'auffiel. Man dreht eine Schraube raus und sieht einen Riss — der '
                .'verschwindet nicht, weil die Karte fertig ist.',
            'defer' => 'Zurückstellen ist eine Feststellung: Es verlangt eine '
                .'Qualifikation und wird mit ihr festgeschrieben. „Hält bis zur nächsten '
                .'Nachprüfung" ist eine Aussage, für die jemand einsteht.',
            'blocking' => 'Ob ein Riss kosmetisch ist, kann nur ein Mensch sagen. Ein '
                .'System, das das rät, rät in eine Richtung — und beide sind falsch.',
            'dismiss' => 'Verworfen ist nicht behoben. Es wurde nichts gemacht, und ein '
                .'Datensatz, der etwas anderes sagt, wäre auf eine Art falsch, auf die '
                .'sich jemand verlassen könnte.',
            'raise_card' => 'Alle angehakten Befunde kommen auf EINE Karte — so, wie '
                .'gearbeitet wird: einmal aufmachen, Liste abarbeiten. Mit der '
                .'Abzeichnung der Karte gelten alle als behoben. Die Auswahl muss zu '
                .'einem Luftfahrzeug gehören und darf nur Offenes enthalten.',
        ],
    ],

    'finding_state' => [
        'open' => 'offen',
        'scheduled' => 'eingeplant',
        'deferred' => 'zurückgestellt',
        'resolved' => 'behoben',
        'dismissed' => 'verworfen',
    ],

    /*
     * Der Befundbericht des VORGANGS -- das Blatt. Nicht zu verwechseln mit
     * „report" darunter: Das ist die Meldung „Befund melden", mit der jemand
     * von aussen etwas ins Buch bringt. Im Alltag heissen beide fast gleich
     * und sind zwei verschiedene Dinge.
     */
    'finding_report' => [
        'title' => 'Befundbericht',
        'description' => 'Jede Zeile ist ein Befund mit der Arbeitskarte, die ihn behebt. '
            .'Die drei Unterschriftsspalten kommen aus dieser Karte — fertig gemeldet, '
            .'unabhängig kontrolliert, abgezeichnet — und werden hier nicht ein zweites '
            .'Mal geführt: Ein Blatt, das etwas anderes sagt als die Akte darunter, wäre '
            .'die schlimmste Sorte Dokument.',
        'sheet_no' => 'Blatt Nr.',
        'empty' => 'Noch keine Befunde in diesem Vorgang.',
        'column' => [
            'position' => 'Lfd. Nr.',
            'finding' => 'Art der Beanstandung, Bericht oder Befund',
            'fix' => 'Art der Behebung, Bemerkungen',
            'done_by' => 'Erledigung',
            'checked_by' => 'Kontrolle',
            'certified_by' => 'Prüfung',
        ],
        'foreign_object_check' => 'Fremdkörperkontrolle und Werkzeugkontrolle nach '
            .'Beendigung der Arbeiten',
        'carried_out' => 'durchgeführt',
        'foreign_object_check_done' => 'Fremdkörper- und Werkzeugkontrolle bestätigt.',
        'made_by' => 'Bericht erstellt:',
        'checked_finally' => 'Abschließend geprüft:',
        'date' => 'Datum',
        'signature' => 'Unterschrift',
        'name' => 'Name',
        'licence' => 'Lizenznummer',
        'stamp' => 'Stempel',
        'certifying_staff' => 'Freigabeberechtigter',
        'deferred' => 'Zurückgestellt bis :date.',
        'recorded' => ':count Punkt(e) aufgenommen — je eine Arbeitskarte',
        'action' => [
            'record' => 'Befundbericht erfassen',
            'print' => 'Befundbericht drucken',
            'foreign_object_check' => 'Fremdkörper-/Werkzeugkontrolle bestätigen',
        ],
        'help' => [
            'record' => 'Jeder Punkt wird ein Befund mit eigener Nummer UND eine eigene '
                .'Arbeitskarte. Das ist der Unterschied zur Sammelaktion an der '
                .'Befundliste: Auf dem Blatt trägt jede Zeile ihre eigenen drei '
                .'Unterschriften, und eine gemeinsame Karte hätte für zehn Zeilen nur '
                .'eine.',
            'foreign_object_check' => 'Die vorgedruckte letzte Zeile des Blatts, und sie '
                .'meint den ganzen Vorgang: Nach Beendigung der Arbeiten wird auf '
                .'Fremdkörper und vergessenes Werkzeug kontrolliert. Bestätigt wird sie '
                .'mit Namen und Zeitpunkt.',
        ],
    ],

    'report' => [
        'title' => 'Befund melden',
        'subheading' => 'Aufgefallen ist etwas — hier kommt es ins Buch. Jeder Punkt '
            .'wird ein eigener Befund mit Nummer; die Werkstatt macht daraus '
            .'Arbeitskarten. Abgezeichnet wird der Bericht mit der Nummer, die zu '
            .'Freigaben berechtigt — der Part-66-Lizenz oder der '
            .'Pilot-Owner-Berechtigung für dieses Luftfahrzeug. Ob etwas harmlos '
            .'ist, entscheidet nicht die Meldung: Bis jemand mit Qualifikation '
            .'darüber befindet, gilt jeder Punkt als blockierend.',
        'field' => [
            'points' => 'Punkte',
        ],
        'add_point' => 'Weiteren Punkt hinzufügen',
        'help' => [
            'description' => 'Wo genau, wie groß, seit wann bemerkt — „Riss" allein '
                .'sagt der nächsten Person nichts.',
        ],
        'done' => ':count Befund(e) gemeldet',
        'refused' => 'Meldung nicht angenommen',
    ],

    'external' => [
        'singular' => 'Externer Auftrag',
        'link' => 'Externen Auftrag verknüpfen',
        'linked' => 'Auftrag verknüpft.',
        'released' => 'freigegeben',
        'help' => [
            'why' => 'Damit die Jahresnachprüfung, deren Motor bei der Fremdwerft war, '
                .'auf derselben Seite zeigt, welcher Auftrag das war und wer freigegeben '
                .'hat — statt dass zwei Aufzeichnungen dasselbe Ereignis beschreiben, '
                .'ohne voneinander zu wissen.',
        ],
    ],

    'release' => [
        'external' => [
            'action' => 'Freigabe eines externen Prüfers eintragen',
            // 'intro' statt 'help': Ein Schlüssel kann nicht Text UND Unterbaum
            // sein, und die Feldhilfen darunter brauchen 'help' als Array.
            'intro' => 'Für den Fall, dass der Prüfer nicht im Verein ist — ein '
                .'freiberuflicher Part-66-Prüfer oder ein Betrieb. Die Bescheinigung '
                .'trägt SEINEN Namen und SEINE Nummer; daneben steht, wer sie '
                .'eingetragen hat. Geprüft wird die Nummer hier nicht, sondern '
                .'abgeschrieben — dafür stehen Sie mit Ihrem Namen im Protokoll.',
            'field' => [
                'name' => 'Freigegeben durch (Name)',
                'licence' => 'Lizenz- oder Betriebsnummer',
                'organisation' => 'Betrieb (optional)',
            ],
            'help' => [
                'co_certifies' => 'ACHTUNG: Diese Freigabe zeichnet folgende Karten mit ab, '
                    .'die bisher nur fertiggemeldet sind: :cards. Mit der Unterschrift '
                    .'unter die Freigabe stehen Sie auch für deren Prüfung ein.',
                'licence' => 'So, wie sie auf seiner Unterschrift steht.',
                'organisation' => 'Wenn ein Betrieb zeichnet und nicht eine Person.',
            ],
            'recorded' => 'Externe Freigabe :number eingetragen.',
            'print_note' => 'Die Freigabe erfolgte durch eine vereinsfremde Person; '
                .'Name und Nummer wurden nach deren Unterschrift eingetragen.',
        ],
        'singular' => 'Freigabe (CRS)',
        'plural' => 'Freigaben',
        'action' => 'Freigabe erteilen',
        'correct' => 'Freigabe korrigieren',
        'print' => 'Bescheinigung drucken',
        'issued' => 'Freigabe :number erteilt.',
        'already' => 'Für diesen Vorgang ist bereits eine Freigabe erteilt.',
        'blocked_by_findings' => 'Offene, nicht zurückgestellte Befunde: :list',
        'blocked_by_airworthiness' => 'Vor der Freigabe zu klären: :list',
        'awaiting' => 'alle Karten abgezeichnet, aber keine Freigabe erteilt',
        'not_yet' => 'noch keine Freigabe — es sind Karten offen',
        'field' => [
            'number' => 'Freigabe-Nr.',
            'statement' => 'Freigabevermerk',
            'maintenance_data' => 'Instandhaltungsunterlagen',
            'released_at' => 'Freigegeben am',
            'released_by' => 'Freigegeben von',
            'correction_reason' => 'Grund der Korrektur',
        ],
        'statement' => 'Die im Vorgang :number (:title) an :registration ausgeführten '
            .'Arbeiten (:cards Arbeitskarten) wurden nach den geltenden '
            .'Instandhaltungsunterlagen durchgeführt. Das Luftfahrzeug gilt insoweit als '
            .'zum Betrieb freigegeben.',
        'help' => [
            'co_certifies' => 'ACHTUNG: Diese Freigabe zeichnet folgende Karten mit ab, '
                .'die bisher nur fertiggemeldet sind: :cards. Mit der Unterschrift '
                .'unter die Freigabe stehen Sie auch für deren Prüfung ein.',
            'third_signature' => 'Die dritte und letzte Unterschrift. „Fertig gemeldet" '
                .'heißt, die Arbeit ist getan; „abgezeichnet" heißt, sie war in Ordnung; '
                .'das hier heißt, das Luftfahrzeug darf fliegen.',
            'freezes' => 'Damit ist der Vorgang eingefroren: Karten, Zeiten und der '
                .'Vorgang selbst lassen sich danach nicht mehr ändern. Eine Korrektur '
                .'ist eine neue Freigabe, die auf diese verweist.',
            'findings' => 'Offene blockierende Befunde verhindern die Freigabe. Genau '
                .'dafür gibt es das Zurückstellen — das ist eine Entscheidung, für die '
                .'jemand einsteht.',
            'pilot_owner' => 'Eine Pilot-Owner-Berechtigung deckt nur selbst ausgeführte '
                .'Arbeit — und eine Freigabe deckt den ganzen Vorgang. Eine einzige Karte '
                .'von jemand anderem genügt, dass ein Part-66-Inhaber unterschreiben muss.',
            'correction' => 'Die alte Freigabe bleibt mit ihrem Text und ihrer '
                .'Unterschrift erhalten. Die neue verweist auf sie und sagt, was falsch '
                .'war — geändert wird nichts.',
            'statement' => 'Der Text über der Unterschrift. Wird beim Erteilen '
                .'festgeschrieben und nicht bei jeder Anzeige neu gebaut — eine '
                .'Unterschrift gehört zu den Worten, die über ihr standen.',
        ],
    ],

    'parts' => [
        'singular' => 'Teileentnahme',
        'plural' => 'Entnommene Teile',
        'action' => 'Teil entnehmen',
        'issued' => 'Entnahme gebucht.',
        'none' => 'Keine Teile entnommen.',
        'unavailable' => 'Kein Lagermodul aktiv.',
        'help' => [
            'through_warehouse' => 'Gebucht wird über das Lager selbst — mit allen '
                .'Regeln, die dort gelten: FEFO, Verfall, Sperrlager und die Bindung '
                .'eines Ausbau-Loses an sein Luftfahrzeug.',
            'aircraft' => 'Das Kennzeichen geht mit. Ohne es könnte ein Ausbau-Los aus '
                .'einem anderen Flugzeug hier unbemerkt eingebaut werden.',
        ],
    ],

    'pilot_owner' => [
        'no_own_work' => 'Auf dieser Karte ist keine eigene Arbeitszeit erfasst.',
        'others_worked' => 'An dieser Karte haben auch andere gearbeitet: :names.',
        'help' => 'Eine Pilot-Owner-Berechtigung deckt nur selbst ausgeführte '
            .'Instandhaltung. Fremde Arbeit freizugeben ist der Part-66-Lizenz '
            .'vorbehalten — das steht so in der VO (EU) 1321/2014.',
        'beyond_scope' => 'Diese Arbeit liegt ausserhalb dessen, was ein Halter selbst '
            .'instand halten darf.',
    ],

    /*
     * Die zweite Frage bei einer Freigabe: nicht WESSEN Arbeit, sondern WELCHE.
     * Siehe CertifyingScope.
     */
    'ma803b' => [
        'not_assessed' => 'Diese Lizenz trägt den Eintrag „no maintenance exceeding '
            .'MA.803(b)". Für Karte :card ist nicht vermerkt, ob die Arbeit im Umfang '
            .'der Pilot-Owner-Instandhaltung liegt — ohne diese Angabe lässt sie sich '
            .'nicht abzeichnen.',
        'beyond_scope' => 'Karte :card liegt ausserhalb der Pilot-Owner-Instandhaltung. '
            .'Diese Lizenz ist auf „no maintenance exceeding MA.803(b)" eingeschränkt und '
            .'deckt sie deshalb nicht — auch wenn sie fremde Arbeit freigeben darf.',
    ],

    'limitation' => [
        'blocks' => 'Die Lizenz trägt die Einschränkung „:limitation" und deckt :registration '
            .'damit nicht. Einschränkungen gelten für die gesamte Lizenz, unabhängig von '
            .'der Kategorie.',
    ],

    'awaiting_certification' => 'fertig gemeldet, aber nicht abgezeichnet',

];
