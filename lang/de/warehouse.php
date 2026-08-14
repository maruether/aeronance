<?php

declare(strict_types=1);

return [

    'module' => [
        'title' => 'Lager',
        'description' => 'Ersatzteile, Lagerorte, Lose und Bestandsbewegungen — '
            .'mit Rückverfolgbarkeit vom Teil bis zum Nachweis.',
    ],

    'classification' => [
        'component' => 'Bauteil',
        'standard_part' => 'Standard Part',
        'consumable_material' => 'Verbrauchsmaterial',
    ],

    'lot_state' => [
        'serviceable' => 'brauchbar',
        'quarantined' => 'gesperrt',
        'unserviceable' => 'unbrauchbar',
        'unsalvageable' => 'ausgemustert',
        'disposed' => 'entsorgt',
    ],

    'movement_type' => [
        'receipt' => 'Zugang',
        'issue' => 'Entnahme',
        'correction' => 'Korrektur',
        'repair_dispatch' => 'Abgang zur Reparatur',
        'repair_return' => 'Rückkehr aus Reparatur',
        'scrap' => 'Ausmusterung',
        'disposal' => 'Entsorgung',
    ],

    'document_type' => [
        'form_one' => 'Form 1',
        'certificate_of_conformity' => 'Konformitätsbescheinigung',
        'none' => 'kein Nachweis',
    ],

    'unit' => [
        'days' => 'Tage',
    ],

    'unit_group' => [
        'count' => 'Stück',
        'length' => 'Länge',
        'area' => 'Fläche',
        'volume' => 'Volumen',
        'mass' => 'Masse',
        'own' => 'Eigene Einheit',
    ],

    'supplier' => [
        'approval' => [
            'heading' => 'Zulassung als Betrieb',
            'hint' => 'Nur für Betriebe, die Bescheinigungen ausstellen oder Instandsetzungen '
                .'ausführen dürfen. Die Schraubenhandlung braucht das nicht.',
            'unlimited' => 'unbefristet',
            'until' => 'gültig bis :date',
            'lapsed' => 'abgelaufen am :date',
        ],
        'filter' => [
            'lapsed' => 'Zulassung abgelaufen',
        ],
        'help' => [
            'approval_number' => 'Wie sie auf der Bescheinigung steht — etwa „EASA.145.1234" '
                .'oder „DE.MF.1234". Ohne Nummer gilt der Betrieb hier nicht als zugelassen.',
            'approval_scope' => 'Wofür sie gilt — „Part-145", „Part-M/F", „Part-21G", oder in '
                .'Worten. Freitext, weil es auch Drittland-Betriebe und FAA-Repair-Stations gibt.',
            'approval_expires_at' => 'Leer lassen heißt „unbefristet". Wer es nicht weiß, '
                .'trägt besser gar keine Nummer ein — eine Zulassung, die niemand nachhält, '
                .'fällt erst beim Audit auf, und dann rückwirkend.',
        ],
        'singular' => 'Lieferant',
        'plural' => 'Lieferanten',
        'field' => [
            'name' => 'Name',
            'approval_number' => 'Zulassungsnummer',
            'approval_scope' => 'Umfang der Zulassung',
            'approval_expires_at' => 'Zulassung gültig bis',
            'address' => 'Adresse',
            'contact' => 'Kontaktdaten',
            'description' => 'Bemerkung',
            'part_types' => 'Bauteiltypen',
        ],
    ],

    'location' => [
        'action' => [
            'print_label' => 'Regalschild drucken',
            'print_labels' => 'Alle Regalschilder drucken',
        ],
        'singular' => 'Lagerort',
        'plural' => 'Lagerorte',
        'field' => [
            'name' => 'Name',
            'description' => 'Beschreibung',
            'is_quarantine' => 'Sperrlager',
        ],
        'help' => [
            'is_quarantine' => 'Unbrauchbare und ausgemusterte Teile müssen getrennt von '
                .'brauchbaren gelagert werden. Aus einem Sperrlager wird nicht entnommen.',
        ],
    ],

    'compartment' => [
        'singular' => 'Fach',
        'plural' => 'Fächer',
        'add' => 'Fach hinzufügen',
        'field' => [
            'name' => 'Name',
            'description' => 'Beschreibung',
        ],
    ],

    'part_type' => [
        'form_one_duty_title' => ':count Los(e) gesperrt',
        'form_one_duty_body' => 'Für diesen Bauteiltyp gilt ab jetzt die Form-1-Pflicht. '
            .'Vorhandener Bestand ohne Nachweis ist gesperrt: :lots — er lässt sich '
            .'freigeben, sobald der Nachweis am Los eingetragen ist.',
        'singular' => 'Bauteiltyp',
        'plural' => 'Bauteiltypen',
        'section' => [
            'identity' => 'Bezeichnung',
            'evidence' => 'Nachweis und Lagerung',
            'stock' => 'Bestand',
            'procurement' => 'Beschaffung',
        ],
        'field' => [
            'name' => 'Name',
            'classification' => 'Klassifizierung',
            'description' => 'Beschreibung',
            'ipc_part_number' => 'IPC-Teilenummer',
            'requires_form_one' => 'Form-1-pflichtig',
            'serial_tracked' => 'Seriennummerngeführt',
            'shelf_life_days' => 'Maximale Lagerzeit',
            'life_limit_type' => 'Laufzeitbegrenzung',
            'compartment' => 'Lagerort',
            'unit' => 'Einheit',
            'unit_own' => 'Eigene Einheit',
            'minimum_stock' => 'Mindestbestand',
            'maximum_stock' => 'Maximalbestand',
            'supplier' => 'Lieferant',
            'order_code' => 'Bestellnummer',
            'price' => 'Einkaufspreis (netto)',
            'stock' => 'Verfügbar',
            'lot_tracked' => 'Losgeführt',
        ],
        'help' => [
            'classification' => 'Entscheidet, welcher Nachweis das Teil braucht. '
                .'Bauteile brauchen in der Regel eine Form 1, Standard Parts eine '
                .'Konformitätsbescheinigung, Verbrauchsmaterial eine Herstellererklärung.',
            'ipc_part_number' => 'Teilenummer aus dem Illustrated Parts Catalogue.',
            'requires_form_one' => 'Ohne Form 1 kann dieses Teil nicht eingebucht werden. Die Ware '
                .'bleibt so lange im Wareneingang — eingelagert wird erst, wenn der '
                .'Nachweis vorliegt.',
            'serial_tracked' => 'Jedes Stück wird einzeln geführt. Beim Einbuchen wird '
                .'die Seriennummer abgefragt.',
            'shelf_life_days' => 'Leer lassen, wenn das Teil nicht verfällt. Nur '
                .'kalendarische Lagerzeit — Betriebsstunden beginnen erst mit dem Einbau.',
            'life_limit_type' => 'Entscheidet, ob ein ausgebautes Teil wieder ins Lager '
                .'darf. TBR-Teile (Zündkerzen, Schläuche) werden getauscht und kommen '
                .'nicht zurück; TBO-Teile (z. B. Schleppkupplung) werden überholt und '
                .'wieder eingebaut.',
            'unit' => 'Wird nicht umgerechnet. Zoll und Fuß bleiben Zoll und Fuß — '
                .'umgerechnete Mengen sind im Regal nicht wiederzuerkennen.',
            'unit_own' => 'Nur, wenn nichts aus der Liste passt. Eine Einheit, die in '
                .'der Bezeichnung statt hier steht, findet keine Auswertung.',
            'lot_tracked_yes' => 'Wird losgeführt: jede Lieferung bleibt einzeln '
                .'nachvollziehbar.',
            'lot_tracked_no' => 'Sammelbestand: es wird nur die Menge geführt.',
            'procurement' => 'Nur zur Information. Bestellungen, Preisverläufe und '
                .'Rechnungen sind bewusst nicht Teil des Lagers.',
        ],
        'minimum_is' => 'Mindestens :n',
        'filter' => [
            'below_minimum' => 'Unter Mindestbestand',
        ],
    ],

    'receive' => [
        'title' => 'Einbuchen',
        'subheading' => 'Wareneingang erfassen. Das Formular passt sich an, was der '
            .'Bauteiltyp verlangt.',
        'action' => 'Einbuchen',
        'section' => [
            'what' => 'Was kommt herein',
            'evidence' => 'Nachweis',
            'where' => 'Ablage',
        ],
        'field' => [
            'quantity' => 'Menge',
            'received_at' => 'Eingangsdatum',
            'note' => 'Bemerkung',
        ],
        'help' => [
            'evidence' => 'Die Felder folgen den Blöcken des Vordrucks, damit ein '
                .'Papierdokument von oben nach unten abgeschrieben werden kann.',
            'document_reference' => 'Nummer der Bescheinigung (Block 12/13). Sie wird '
                .'zugleich die Losnummer.',
            'document_type_own' => 'Für Papiere, die weder Form 1 noch CoC sind. '
                .'ACHTUNG: Ein selbst benanntes Papier gilt NICHT als Form 1 — auch '
                .'dann nicht, wenn es so heißt. Wo eine Form 1 verlangt ist, führt nur '
                .'die Auswahl oben zum Ziel.',
            'batch_number' => 'Chargennummer aus Block 10, falls angegeben.',
            'compartment' => 'Voreingestellt ist der Lagerort des Bauteiltyps.',
            'document_file' => 'PDF oder Foto, höchstens 20 MB. Die Datei liegt außerhalb '
                .'des Webverzeichnisses und ist nur nach Anmeldung abrufbar.',
            'expires' => 'Verfällt am :date (:days Tage Lagerzeit).',
        ],
        'notification' => [
            'done' => 'Wareneingang gebucht.',
            'lot' => 'Los :lot angelegt.',
            'refused' => 'Das geht so nicht',
            'quarantined_title' => 'Ware wurde gesperrt',
            'quarantined_body' => 'Ohne den erforderlichen Nachweis lässt sich der '
                .'Lufttüchtigkeitsstatus nicht bestimmen. Das Los liegt im Sperrbestand, '
                .'bis das Dokument nachgereicht und der Zustand von berechtigtem Personal '
                .'festgestellt wurde.',
        ],
    ],

    'issue' => [
        'title' => 'Entnehmen',
        'subheading' => 'Material entnehmen und festhalten, wohin es geht.',
        'action' => 'Entnehmen',
        'expires_on' => 'verfällt :date',
        'only_for' => 'nur für :aircraft',
        'section' => [
            'what' => 'Was wird entnommen',
            'where' => 'Wohin',
        ],
        'field' => [
            'lot' => 'Los',
            'quantity' => 'Menge',
            'aircraft' => 'Luftfahrzeug',
            'work_order' => 'Vorgang',
            'note' => 'Bemerkung',
        ],
        'help' => [
            'fefo' => 'Vorgeschlagen ist das Los, das zuerst verfällt. Ein anderes zu '
                .'wählen ist zulässig und braucht keine Begründung.',
            'pick_serial' => 'Bitte das Teil anhand seiner Seriennummer auswählen.',
            'restricted_hidden' => ':n Los/Lose sind ausgeblendet: sie stammen aus dem '
                .'Ausbau eines anderen Luftfahrzeugs und dürfen ohne Form 1 nicht in '
                .':aircraft.',
            'restricted_no_aircraft' => ':n Los/Lose sind ausgeblendet: sie dürfen nur '
                .'wieder in das Luftfahrzeug, aus dem sie stammen. Bitte unten das '
                .'Kennzeichen eintragen.',
            'available' => 'Verfügbar: :quantity :unit',
            'destination' => 'Freiwillig, aber die Angabe schließt die Kette vom Nachweis '
                .'bis zum Luftfahrzeug.',
        ],
        'notification' => [
            'done' => 'Entnahme gebucht.',
            'refused' => 'Das geht so nicht',
            'lot_dropped' => 'Los :lot passt nicht zu diesem Luftfahrzeug und wurde '
                .'abgewählt.',
        ],
    ],

    'origin' => [
        'supplier' => 'Lieferant',
        'removal' => 'Ausbau',
        'repair' => 'Reparatur',
    ],

    'movement' => [
        'singular' => 'Bewegung',
        'plural' => 'Journal',
        'correct_action' => 'Korrektur buchen',
        'reverses' => 'Gegenbuchung zu Bewegung #:id',
        'reversed_by' => 'am :date korrigiert',
        'filter' => [
            'corrections' => 'Nur Korrekturen',
        ],
        'field' => [
            'occurred_at' => 'Zeitpunkt',
            'type' => 'Art',
            'quantity' => 'Menge',
            'user' => 'Gebucht von',
            'note' => 'Bemerkung',
            'reason' => 'Grund',
        ],
        'help' => [
            'correction' => 'Die ursprüngliche Buchung bleibt stehen. Daneben entsteht '
                .'eine zweite, entgegengesetzte, die auf sie verweist — beide zusammen '
                .'erklären, was passiert ist. Nichts wird überschrieben.',
            'reason' => 'Ohne Grund sagt die Gegenbuchung nur, dass jemand es sich '
                .'anders überlegt hat.',
        ],
        'notification' => [
            'corrected' => 'Korrektur gebucht.',
            'refused' => 'Das geht so nicht',
        ],
    ],

    'transfer' => [
        'action' => 'Umlagern',
        'is_quarantine' => 'Sperrlager',
        'quarantined_reason' => 'Ins Sperrlager :compartment umgelagert. :reason',
        'field' => [
            'target' => 'Neues Fach',
        ],
        'help' => [
            'quarantine' => 'Ins Sperrlager umzulagern sperrt das Los — räumliche '
                .'Trennung ist die Sperrung. Heraus geht es erst, wenn es freigegeben '
                .'ist; das ist eine Feststellung und keine Umlagerung. Innerhalb des '
                .'normalen Lagers ist jede Umlagerung frei.',
            'reason' => 'Nur nötig, wenn brauchbarer Bestand ins Sperrlager geht — dann '
                .'steht er auf dem Sperrzettel.',
        ],
        'notification' => [
            'moved' => 'Umgelagert nach :compartment.',
            'quarantined' => 'Das Los liegt jetzt im Sperrbestand — ein Sperrzettel mit '
                .'laufender Nummer ist angelegt.',
            'belongs_in_quarantine' => 'Achtung: Dieses Los ist gesperrt und liegt jetzt '
                .'zwischen brauchbarem Bestand. 145.A.42 verlangt räumliche Trennung — es '
                .'gehört ins Sperrlager.',
            'refused' => 'Das geht so nicht',
        ],
    ],

    'expiry' => [
        'reason' => 'Lagerzeit am :date abgelaufen. Automatisch als unbrauchbar '
            .'erfasst — keine Feststellung, sondern ein überschrittenes Datum.',
        'by_system' => 'automatisch',
    ],

    'disposal' => [
        'title' => 'Vernichten',
        'subheading' => 'Bestand ausbuchen, weil er vernichtet wurde.',
        'action' => 'Endgültig vernichten',
        'pick' => 'Übernehmen',
        'expired_on' => 'verfallen am :date',
        'expired_reason' => 'Verfallsdatum überschritten (:date)',
        'field' => [
            'occurred_at' => 'Datum der Vernichtung',
        ],
        'section' => [
            'what' => 'Was wurde vernichtet',
            'expired' => 'Bereits verfallen',
        ],
        'help' => [
            'expired' => 'Verfallenes liegt im Regal und sieht aus wie alles andere. '
                .'Deshalb steht es hier oben.',
            'lots' => 'Gesperrte und unbrauchbare Lose stehen hier bewusst zur Auswahl — '
                .'das ist das meiste, was vernichtet wird.',
            'partial' => 'Auch Teilmengen. Der Rest des Loses bleibt, wie er war.',
            'reason' => 'Bleibt dauerhaft am Datensatz. „Vernichtet" ohne Grund ist eine '
                .'Menge, die verschwunden ist.',
        ],
        'notification' => [
            'done' => 'Vernichtung gebucht.',
            'no_way_back' => 'Das lässt sich nicht mehr zurücknehmen: Eine Gegenbuchung '
                .'würde behaupten, das Teil liege wieder im Regal. Der Datensatz bleibt '
                .'mit Menge null erhalten.',
            'refused' => 'Das geht so nicht',
        ],
    ],

    'repair' => [
        'refused' => [
            'approval_lapsed' => 'Die Zulassung von :shop ist am :date abgelaufen. Was von '
                .'dort zurückkommt, trägt eine Bescheinigung, die nichts wert ist — und das '
                .'fällt sonst erst auf, wenn Jahre später jemand danach fragt.',
        ],
        'singular' => 'Reparatur',
        'plural' => 'In Reparatur',
        'title' => 'Zur Reparatur geben',
        'subheading' => 'Ein Teil aus dem Lager an einen Betrieb geben, der es '
            .'instand setzen darf.',
        'action' => 'Zur Reparatur geben',
        'return_action' => 'Rückkehr buchen',
        'write_off_action' => 'Als verloren abschreiben',
        'return_note' => 'Rückkehr aus Reparatur bei :shop',
        'destination' => [
            'external' => 'Externer Betrieb',
            'in_house' => 'Eigene Komponentenwerkstatt',
        ],
        'state' => [
            'dispatched' => 'unterwegs',
            'returned' => 'zurück',
            'written_off' => 'abgeschrieben',
        ],
        'section' => [
            'what' => 'Was geht weg',
            'where' => 'Wohin',
            'back' => 'Rückkehr',
        ],
        'filter' => [
            'overdue' => 'Überfällig',
        ],
        'field' => [
            'state' => 'Stand',
            'restriction' => 'Gebunden an',
            'returned_lot' => 'Rückkehr-Los',
            'shop_from_register' => 'Betrieb aus dem Verzeichnis',
            'shop_name' => 'Betrieb',
            'shop_approval' => 'Betriebsnummer',
            'dispatch_reference' => 'Versandbeleg / Paketnummer',
            'reason' => 'Grund',
            'expected_back_at' => 'Erwartet zurück',
            'dispatched_at' => 'Versanddatum',
            'destination' => 'Ziel',
            'returned_at' => 'Rückkehrdatum',
            'return_note' => 'Bemerkung',
        ],
        'help' => [
            'shop_from_register' => 'Wird ein Betrieb gewählt, kommen Name und Zulassungsnummer '
                .'von dort — und eine abgelaufene Zulassung fällt sofort auf. Ohne Auswahl '
                .'bleibt der Freitext daneben.',
            'shop_approval' => 'Die EASA-Betriebsnummer dessen, der die Form 1 '
                .'unterschreibt — z. B. DE.145.0123 oder DE.CAO.0456.',
            'restriction_at_stake' => 'Dieses Teil ist an :aircraft gebunden. Kommt es '
                .'mit einer Form 1 zurück, ist die Bindung erledigt — genau dafür geht '
                .'es weg. Ohne Form 1 bleibt sie bestehen.',
            'unserviceable_ok' => 'Gesperrte und als unbrauchbar festgestellte Teile '
                .'dürfen hier weg — das ist der Normalfall. Nur was als nicht '
                .'instandsetzbar festgestellt wurde, darf nicht mehr zurück ins System.',
            'form_one_lifts' => 'Mit Form 1 kommt das Teil brauchbar und frei verwendbar '
                .'zurück. Ohne Form 1 landet es im Sperrbestand und bleibt an sein '
                .'Luftfahrzeug gebunden.',
            'write_off' => 'Bucht nichts ins Lager zurück — die Menge ist beim Versand '
                .'abgegangen und im Journal seitdem sichtbar. Es schließt nur den '
                .'offenen Vorgang, damit das Teil nicht ewig als unterwegs geführt wird.',
        ],
        'notification' => [
            'dispatched' => ':part ist unterwegs zu :shop.',
            'returned' => 'Los :lot angelegt.',
            'returned_free' => 'Mit Form 1 zurück — die Bindung an :aircraft ist erledigt.',
            'returned_quarantined' => 'Ohne Form 1 zurück: das Teil liegt im Sperrbestand.',
            'written_off' => 'Als verloren abgeschrieben.',
            'refused' => 'Das geht so nicht',
        ],
    ],

    'life_limit' => [
        'none' => 'keine Begrenzung',
        'on_condition' => 'nach Befund',
        'tbo' => 'TBO — Überholungsintervall',
        'tbr' => 'TBR — Austauschintervall',
    ],

    'removal' => [
        'title' => 'Ausbau einlagern',
        'subheading' => 'Ein Teil, das aus einem Luftfahrzeug kommt, ins Lager nehmen.',
        'action' => 'Einlagern',
        'determined_serviceable' => 'Beim Ausbau als brauchbar festgestellt. :reason',
        'condition_unknown' => 'Ausgebaut, Zustand nicht festgestellt. :reason',
        'section' => [
            'what' => 'Was wurde ausgebaut',
            'condition' => 'Zustand',
            'where' => 'Ablage',
        ],
        'field' => [
            'aircraft' => 'Kennzeichen',
            'aircraft_type' => 'Muster',
            'removed_at' => 'Ausbaudatum',
            'reason' => 'Grund des Ausbaus',
            'serviceable' => 'Beim Ausbau als brauchbar festgestellt',
        ],
        'help' => [
            'aircraft' => 'Solange das Flottenmodul fehlt, freier Text. Danach kommt die '
                .'Angabe von dort.',
            'serviceable' => 'Das ist eine Feststellung, für die jemand einsteht — sie '
                .'verlangt eine gültige Part-66-Lizenz und wird unveränderlich '
                .'festgeschrieben. Ohne sie liegt das Teil im Sperrbestand.',
            'restriction' => 'Ohne Form 1 darf dieses Teil nur wieder in dasselbe '
                .'Luftfahrzeug. Ein Einbau anderswo braucht eine Bescheinigung von einem '
                .'Betrieb mit Komponentenberechtigung.',
            'tbr' => 'Teile mit Austauschintervall (TBR) werden nicht wieder eingelagert.',
        ],
        'notification' => [
            'stored' => 'Los :lot angelegt.',
            'quarantined' => 'Zustand nicht festgestellt — das Teil liegt im Sperrbestand, '
                .'bis berechtigtes Personal es beurteilt.',
            'restricted' => 'Ohne Form 1 nur für :aircraft verwendbar.',
            'refused' => 'Das geht so nicht',
        ],
    ],

    'stocktake' => [
        'title' => 'Inventur erfassen',
        'subheading' => 'Gezählte Mengen eintragen. Aufgebaut wie die Zählliste — '
            .'Lagerort für Lagerort, Los für Los.',
        'action' => 'Differenzen buchen',
        'print_list' => 'Zählliste drucken',
        'all_locations' => 'Alle Lagerorte',
        'no_lots' => 'Keine Lose mit Bestand.',
        'note' => 'Inventur',
        'field' => [
            'location' => 'Lagerort',
            'counted_at' => 'Zähldatum',
        ],
        'found_label' => 'Darüber hinaus gefunden',
        'found_note_placeholder' => 'Wo gefunden, Vermutung zur Herkunft …',
        'found_pick_part' => 'Bauteiltyp wählen …',
        'found_add' => 'Weiteren Fund eintragen',
        'found_remove' => 'Entfernen',
        'found_hint' => 'Für Teile, die im Regal liegen, aber in keinem Los stehen. '
            .'Mehrbestand wird NICHT auf ein vorhandenes Los gebucht — das würde '
            .'behaupten, das Teil sei von dessen Form 1 gedeckt. Es entsteht ein eigenes '
            .'Los ohne Nachweis, gesperrt, bis jemand die Herkunft klärt. Zur Auswahl '
            .'stehen losgeführte Bauteiltypen; bei Sammelbestand genügt die Zählzahl oben.',
        'found_default_note' => 'Bei der Inventur gefunden, Herkunft ungeklärt',
        'nothing_to_book' => 'Keine Differenzen eingetragen.',
        'booked' => ':n Korrekturbuchung(en) erfasst.',
        'found_title' => ':n Los(e) mit ungeklärter Herkunft angelegt',
        'found_body' => 'Angelegt: :lots — gesperrt, bis die Herkunft geklärt ist. '
            .'Ohne Nachweis lässt sich der Lufttüchtigkeitsstatus nicht bestimmen.',
        'refused' => 'Nicht gebucht',
    ],

    /*
     * Der Losaufkleber. Was daraufsteht, ist ueber die Lebensdauer des Loses
     * unveraenderlich -- Menge und Lagerort ausdruecklich nicht, siehe die
     * Sicht.
     */
    /*
     * Der Scanner. Kamera und Tastatur sind gleichwertig -- ein Thermodruck
     * verblasst, und die Losnummer steht im Klartext daneben.
     */
    /*
     * Bestellungen. Zweck ist der Erinnerer, nicht die Beschaffung -- siehe
     * die Migration. Keine Preise, keine Rechnungen, keine Konditionen.
     */
    'order' => [
        'singular' => 'Bestellung',
        'plural' => 'Bestellungen',
        'without_number' => 'ohne Nummer (#:id)',

        'field' => [
            'number' => 'Bestellnummer',
            'supplier' => 'Lieferant',
            'ordered' => 'Bestellt am',
            'expected' => 'Zugesagt für',
            'state' => 'Stand',
            'note' => 'Notiz',
            'created_by' => 'Eingetragen von',
            'cancelled_at' => 'Storniert am',
            'cancel_reason' => 'Grund der Stornierung',
            'lines' => 'Positionen',
            'part' => 'Bauteil',
            'quantity_ordered' => 'Bestellt',
            'quantity_received' => 'Geliefert',
            'outstanding' => 'Offen',
        ],

        'help' => [
            'number' => 'Die Nummer des Lieferanten, so wie sie auf dessen Bestätigung '
                .'steht. Keine eigene — eine hausgemachte Nummer steht auf keinem '
                .'Lieferschein.',
            'expected' => 'An diesem Datum hängt die Erinnerung. Voreingestellt ist eine '
                .'Woche nach der Bestellung — viele Lieferanten sagen gar kein Datum zu, '
                .'und gerade bei denen will man erinnert werden. Zugesagtes Datum ruhig '
                .'überschreiben; leeren heißt: nicht erinnern.',
            'lines' => 'Was bestellt wurde. Teillieferungen sind vorgesehen — erst wenn '
                .'alles eingebucht ist, gilt die Bestellung als erledigt.',
        ],

        'state' => [
            'open' => 'offen',
            'partially_received' => 'teilweise geliefert',
            'received' => 'vollständig geliefert',
            'cancelled' => 'storniert',
        ],

        'action' => [
            'receive' => 'Einbuchen',
            'receive_heading' => ':part einbuchen',
            'cancel' => 'Stornieren',
            'cancel_heading' => 'Bestellung :order stornieren',
            'cancel_description' => 'Bereits gelieferte Ware bleibt eingebucht — sie liegt '
                .'ja im Regal. Die Stornierung sagt nur, dass auf den Rest niemand mehr '
                .'wartet.',
            'from_shortage' => 'Bestellung anlegen',
        ],

        'filter' => [
            'outstanding' => 'Offene Bestellungen',
            'overdue' => 'Überfällig',
        ],

        'notification' => [
            'received' => ':quantity × :part eingebucht.',
            'received_hint' => 'Das Los ist angelegt — der Aufkleber lässt sich aus der '
                .'Bestandsliste drucken.',
            'cancelled' => 'Bestellung :order storniert.',
            'refused' => 'Das geht nicht.',
        ],

        'refused' => [
            'not_outstanding' => 'Diese Bestellung ist :state — darauf wird nichts mehr '
                .'gebucht. Kommt doch noch Ware, gehört sie als regulärer Wareneingang '
                .'gebucht.',
            'no_reason' => 'Eine Stornierung braucht einen Grund. In einem halben Jahr '
                .'weiß sonst niemand mehr, warum.',
            'already_cancelled' => 'Diese Bestellung ist bereits storniert.',
            'already_received' => 'Diese Bestellung ist vollständig geliefert — es wartet '
                .'niemand mehr auf etwas.',
        ],

        'reminder' => [
            'nothing' => 'Keine überfälligen Lieferungen.',
            'no_mailer' => ':anzahl überfällige Lieferung(en), aber kein Mailversand '
                .'eingerichtet. Unter Einstellungen → E-Mail eintragen; die Liste steht '
                .'solange in der Oberfläche.',
            'no_recipient' => ':anzahl überfällige Lieferung(en) ohne erreichbaren '
                .'Empfänger — wer sie eingetragen hat, ist ausgeschieden oder hat keine '
                .'Adresse.',
            'sent' => 'Erinnerung zu :anzahl Lieferung(en) verschickt.',
        ],

        'mail' => [
            'subject' => '{1}Eine Lieferung ist überfällig|[2,*]:anzahl Lieferungen sind überfällig',
            'heading' => 'Überfällige Lieferungen',
            'intro' => '{1}Für diese Bestellung ist das zugesagte Lieferdatum verstrichen, '
                .'und es fehlt noch Ware:|[2,*]Für diese :anzahl Bestellungen ist das '
                .'zugesagte Lieferdatum verstrichen, und es fehlt noch Ware:',
            'outstanding' => 'Offen',
            'days_late' => ':tage Tage überfällig',
            'hint' => 'Wenn die Ware inzwischen da ist, buche sie ein — dann meldet sich '
                .'diese Mail nicht wieder. Kommt sie nicht mehr, storniere die Bestellung.',
            'button' => 'Bestellungen ansehen',
            'footer' => 'Diese Erinnerung kommt einmal alle paar Tage, nicht täglich.',
        ],

        'widget' => [
            'title' => '{1}Eine Lieferung ist überfällig|[2,*]:anzahl Lieferungen sind überfällig',
            'hint' => 'Zugesagt war früher. Einbuchen, wenn die Ware da ist — sonst beim '
                .'Lieferanten nachfassen.',
            'open' => 'Ansehen',
        ],
    ],

    'scan' => [
        'field' => 'Los scannen oder Nummer eintippen',
        'placeholder' => 'Losnummer …',
        'open' => 'Kamera',
        'close' => 'Kamera zu',
        'stop' => 'Kamera aus',
        'hint' => 'Code vor die Kamera halten.',
        'found' => 'Gelesen.',
        'denied' => 'Keine Kamera verfügbar. Nummer bitte eintippen.',
        'insecure' => 'Die Kamera geht nur über HTTPS. Nummer bitte eintippen.',
        'help' => 'Ein Scan setzt Bauteiltyp und Los in einem Schritt — das Los '
            .'weiß, zu welchem Bauteil es gehört.',
        'foreign' => 'Das ist kein Aeronance-Code.',
        'unknown' => 'Zu diesem Code gibt es kein Los (mehr).',
        'not_a_lot' => 'Das ist ein Lagerortschild, kein Los.',
        'applied' => ':lot übernommen.',
        'location_open' => 'Regalschild scannen',
        'location_hint' => 'Regalschild vor die Kamera halten.',
        'location_applied' => 'Lagerort :location gewählt.',
        'unknown_location' => 'Zu diesem Code gibt es keinen Lagerort (mehr).',
        'not_a_location' => 'Das ist ein Losaufkleber, kein Regalschild.',
    ],

    'label' => [
        'title' => 'Losaufkleber',
        'part_number' => 'P/N',
        'serial' => 'S/N',
        'batch' => 'Charge',
        'document' => 'Form 1',
        'received' => 'Eingang',
        'expires' => 'Verfall',
        'none' => 'Keine Lose ausgewählt. Der Aufruf braucht die Lose in der '
            .'Adresse — aus der Bestandsliste heraus setzt der Knopf sie ein.',
        'variant' => [
            'roll' => 'Rolle (Etikettendrucker)',
            'sheet' => 'A4-Bogen',
        ],
        'print_hint_title' => 'Vor dem Drucken',
        'print_hint' => 'Im Druckdialog „Tatsächliche Größe" wählen und Kopf- und '
            .'Fußzeilen abschalten. Skaliert der Drucker, passt kein Etikett — '
            .'einmal mit dem Kalibrierbogen prüfen.',
        'roll_hint' => 'Beim Etikettendrucker ist die Seite das Etikett: als Papierformat '
            .'die Rolle wählen, nicht A4.',
        'skipped' => 'Übersprungene Positionen auf dem Bogen: :positions',
        'location_title' => 'Lagerortschilder',
        'no_locations' => 'Keine Lagerorte angelegt.',
        'scan_hint' => 'Zum Zählen in Aeronance scannen',
        'calibration_link' => 'Passt das Etikett nicht? Kalibrierbogen drucken',
        'calibration_title' => 'Kalibrierbogen für Losaufkleber',
        'calibration_hint' => 'Einmal je Drucker: Der Kasten muss genau :w × :h mm '
            .'messen. Tut er das nicht, skaliert der Drucker — dann im Druckdialog '
            .'„Tatsächliche Größe" wählen und erneut prüfen.',
    ],

    'inventory' => [
        'title' => 'Inventurbericht',
        'hint' => 'Bestand zu einem Stichtag. Weil jede Menge aus den Bewegungen '
            .'entsteht und nie überschrieben wird, ist die Zahl für jeden vergangenen '
            .'Tag exakt berechenbar — nicht geschätzt.',
        'as_of' => 'Stichtag :date',
        'created' => 'Erstellt am :date',
        'available' => 'Verfügbar',
        'blocked' => 'Gesperrt',
        'total' => 'Gesamt',
        'missing' => 'Fehlmenge',
        'until' => 'bis',
        'destination' => 'Ziel / Person',
        'compiled_by' => 'Erstellt von',
        'no_reference_at_all' => 'kein Nachweis erfasst',
        'stock_hint' => 'Verfügbar und gesperrt getrennt: beim Zählen liegt beides in '
            .'der Hand, in der Verwendbarkeit gehen sie auseinander.',
        'missing_evidence_hint' => 'Lose, die einen Form 1 brauchen und bei denen kein '
            .'Dokument hinterlegt ist. Für die tägliche Arbeit reicht die Nummer, für '
            .'ein Audit nicht.',
        'expiry_expired' => 'Bereits abgelaufen',
        'expiry_soon' => 'Läuft in den nächsten 90 Tagen ab',
        'section' => [
            'stock' => '1. Bestand zum Stichtag',
            'shortfalls' => '2. Unter Mindestbestand',
            'expiry' => '3. Verfall',
            'blocked' => '4. Gesperrter Bestand',
            'missing_evidence' => '5. Nachweislücken',
            'journal' => '6. Bewegungsjournal',
        ],
        'no_stock' => 'Kein Bestand zum Stichtag.',
        'no_shortfalls' => 'Keine Unterschreitung.',
        'no_expiry' => 'Nichts abgelaufen, nichts läuft demnächst ab.',
        'no_blocked' => 'Nichts gesperrt.',
        'no_missing_evidence' => 'Zu jedem Los liegt der erforderliche Nachweis vor.',
    ],

    'counting' => [
        'title' => 'Zählliste',
        'hint' => 'Ausdrucken und mit ins Lager nehmen. Der erwartete Bestand steht '
            .'daneben — ein blindes Zählen ist theoretisch strenger, produziert im '
            .'Vereinslager aber vor allem Abweichungen, die sich als Übertragungsfehler '
            .'herausstellen.',
        'printed' => 'Gedruckt am :date',
        'part' => 'Bauteil',
        'compartment' => 'Fach',
        'expected' => 'Erwartet',
        'counted' => 'Gezählt',
        'note' => 'Bem.',
        'unassigned' => 'Ohne Lagerort',
        'empty' => 'Keine Bauteiltypen erfasst.',
        'counted_by' => 'Gezählt von',
        'date' => 'Datum',
        'signature' => 'Unterschrift',
    ],

    'attention' => [
        'title' => 'Was liegt an',
        'subheading' => 'Alltagsfragen: Was ist abgelaufen, was fehlt, was liegt gesperrt '
            .'herum. Der Inventurbericht beantwortet dagegen, was zu einem Stichtag da war.',
        'all_clear' => 'Nichts zu tun — kein abgelaufener Bestand, keine Unterschreitung, '
            .'nichts Gesperrtes.',
        'expired' => 'Abgelaufen',
        'expired_hint' => 'Liegt im Regal und sieht brauchbar aus. Ist es nicht.',
        'below_minimum' => 'Unter Mindestbestand',
        'below_minimum_hint' => 'Was nachbestellt werden sollte.',
        'expiring' => 'Läuft in den nächsten 90 Tagen ab',
        'blocked' => 'Gesperrter Bestand',
        'blocked_hint' => 'Wie lange etwas schon unentschieden liegt, ist meist die '
            .'interessantere Zahl als der Grund.',
        'without_certificate' => 'Form-1-pflichtig, aber ohne Nachweis',
        'without_certificate_hint' => 'Ohne Nachweis lässt sich die Lufttüchtigkeit '
            .'nicht feststellen — solche Ware wird nicht mehr zur Ausgabe angeboten '
            .'und darf nicht eingebaut werden. Entweder die Form-1-Nummer am Los '
            .'nachtragen oder das Los sperren.',
        'missing_documents' => 'Nachweis erfasst, Dokument fehlt',
        'missing_documents_hint' => 'Für die tägliche Arbeit reicht die Nummer, für ein '
            .'Audit nicht.',
        'no_supplier' => 'kein Lieferant hinterlegt',
        'no_reference' => 'auch keine Nummer',
        'short_by' => 'es fehlen :n',
        'in_days' => 'in :n Tagen',
        'since_days' => 'seit :n Tagen',
    ],

    'tag' => [
        'sheet_title' => 'Sperrzettel — Bogen',
        'single_title' => 'Sperrzettel :tag',
        'calibration_title' => 'Kalibrierbogen',
        'none' => 'Keine ungedruckten Sperrzettel vorhanden.',
        'part' => 'Bauteil',
        'lot' => 'Los',
        'aircraft' => 'Kennzeichen / Muster',
        'date' => 'Datum',
        'signature' => 'Unterschrift',
        'state' => [
            'quarantined' => 'Gesperrt',
            'unserviceable' => 'Unbrauchbar',
            'unsalvageable' => 'Ausgemustert',
            'serviceable' => 'Freigegeben',
            'disposed' => 'Entsorgt',
        ],
        'variant' => [
            'sheet' => 'Anhängerbogen T2002-10',
            'label' => 'Etiketten zum Aufkleben',
        ],
        'label_hint' => 'Etiketten zum Aufkleben auf vorgefertigte Anhänger aus farbigem '
            .'Karton. Die Farbe steckt dann im Karton — der Zustand steht trotzdem in '
            .'Worten auf dem Etikett, damit ein falsch gegriffener Anhänger nicht falsch '
            .'gelesen wird.',
        'print_hint_title' => 'Vor dem Drucken',
        'print_hint' => 'Im Druckdialog „Tatsächliche Größe" bzw. Skalierung 100 % wählen '
            .'und Seitenränder auf „Keine" stellen. Ob die Einstellung stimmt, zeigt der '
            .'Kalibrierbogen — einmal je Drucker genügt.',
        'single_hint' => 'Einzelner Zettel oben links, mit Schnittmarke. Gedacht für '
            .'Blankokarton in Rot, Weiß oder Grün, der von Hand zugeschnitten wird.',
        'skipped' => 'Übersprungene Positionen auf dem Bogen: :positions',
        'calibration_hint' => 'Diesen Bogen einmal auf normalem Papier ausdrucken und '
            .'nachmessen. Stimmen Lineal und Kästchen, sitzen auch die Etiketten.',
        'calibration_note' => '<strong>So wird geprüft:</strong> Das Lineal oben muss über '
            .'100&nbsp;mm messen, jedes Kästchen genau :w&nbsp;×&nbsp;:h&nbsp;mm. '
            .'Weicht es ab, skaliert der Drucker — im Druckdialog auf 100&nbsp;% stellen. '
            .'Sitzen die Kästchen richtig groß, aber versetzt zur Stanzung des Bogens '
            .':template, sind die Randmaße in <code>config/aeronance.php</code> unter '
            .'<code>quarantine_tag.sheet</code> anzupassen.',
    ],

    'lot' => [
        'singular' => 'Los',
        'plural' => 'Lose',
        'expired' => 'abgelaufen',
        'no_expiry' => 'verfällt nicht',
        'qualified_act' => 'festgestellt (qualifiziert)',
        'precautionary' => 'vorsorglich',
        'section' => [
            'identity' => 'Los',
            'certificate' => 'Nachweis',
            'movements' => 'Bewegungen',
            'determinations' => 'Zustandshistorie',
        ],
        'field' => [
            'lot_number' => 'Losnummer',
            'part_type' => 'Bauteiltyp',
            'remaining' => 'Restmenge',
            'state' => 'Zustand',
            'new_state' => 'Neuer Zustand',
            'expires_at' => 'Verfällt am',
            'received_at' => 'Eingelagert am',
            'document' => 'Nachweis',
            'document_type' => 'Art des Nachweises',
            'document_type_own' => 'Papier selbst benennen',
            'document_reference' => 'Nummer des Nachweises',
            'document_issuer' => 'Ausstellende Organisation',
            'document_issuer_approval' => 'Betriebsnummer',
            'document_issued_at' => 'Ausgestellt am',
            'document_signatory' => 'Unterzeichner',
            'serial_number' => 'Seriennummer',
            'batch_number' => 'Chargennummer',
            'document_file' => 'Scan des Nachweises',
            'reason' => 'Begründung',
            'when' => 'Wann',
            'movement' => 'Art',
            'quantity' => 'Menge',
            'aircraft' => 'Luftfahrzeug',
            'work_order' => 'Vorgang',
            'by' => 'Durch',
            'transition' => 'Übergang',
            'tag' => 'Sperrzettel',
            'determined_by' => 'Festgestellt von',
        ],
        'form_one_duty_reason' => 'Form-1-Pflicht nachträglich gesetzt — Nachweis fehlt',
        'action' => [
            'record_certificate' => 'Nachweis eintragen',
            'quarantine' => 'Sperren',
            'determine' => 'Zustand feststellen',
            'print_tag' => 'Sperrzettel drucken',
            'print_label' => 'Losaufkleber drucken',
            'print_labels' => 'Losaufkleber drucken',
        ],
        'help' => [
            'record_certificate' => 'Die Nummer ist der Nachweis — ohne sie wird ein '
                .'Form-1-pflichtiges Teil nicht mehr ausgegeben. Der Scan ist die '
                .'Vollständigkeit fürs Audit und darf nachkommen.',
            'document_file' => 'PDF oder Foto. Liegt auf der privaten Ablage, nicht im '
                .'Webroot — abrufbar nur für Angemeldete.',
            'quarantine_reason' => 'Vorsorgliches Sperren, jederzeit rücknehmbar. '
                .'Es wird ein Sperrzettel mit laufender Nummer erzeugt.',
            'determination_reason' => 'Diese Feststellung wird dauerhaft festgeschrieben, '
                .'zusammen mit Ihrem Namen und Ihrer Qualifikation. Ausgemusterte Teile '
                .'können nicht zurück in den Bestand.',
        ],
        'filter' => [
            'expiring' => 'Verfällt in 90 Tagen',
            'expired' => 'Abgelaufen',
            'in_stock' => 'Nur mit Bestand',
        ],
        'notification' => [
            'certificate_recorded' => 'Nachweis eingetragen.',
            'quarantined' => 'Los gesperrt.',
            'tag' => 'Sperrzettel :tag — bitte ausdrucken und am Teil anbringen.',
            'state_changed' => 'Zustand festgestellt und festgeschrieben.',
            'refused' => 'Das geht so nicht',
        ],
    ],

];
