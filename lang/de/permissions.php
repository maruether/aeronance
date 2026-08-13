<?php

declare(strict_types=1);

/*
 * Rechtebezeichnungen.
 *
 * Bewusst FLACH mit dem vollen Rechtenamen als Schlüssel, nicht verschachtelt:
 * "stock.quarantine" und "stock.quarantine.certify" sind beides gültige Rechte,
 * und ein Schlüssel kann nicht zugleich Text und Unterbaum sein. Nachgeschlagen
 * wird deshalb in PHP über das ganze Array, nicht per Punktnotation.
 */
return [

    'group' => [
        'core.people' => 'Personen und Rollen',
        'core.system' => 'System',
        'warehouse.stock' => 'Lager — Bestand',
        'warehouse.master_data' => 'Lager — Stammdaten',
        'fleet.aircraft' => 'Flotte — Luftfahrzeuge',
        'fleet.airworthiness' => 'Flotte — Lufttüchtigkeit',
        'taskcards.work' => 'Werkstatt — Arbeit',
        'taskcards.certify' => 'Werkstatt — Freigabe und Befunde',
        // Eigene Gruppe, damit ein Verein sie grob verteilen kann: Melden geht
        // an „jeden P/O oder höher" — Rollen, die sonst nichts aus der
        // Werkstatt tragen.
        'taskcards.report' => 'Werkstatt — Melden',
        'directives' => 'LTA / TM',
        'inspection' => 'Eingangsprüfung',
        'tooling' => 'Werkzeuge',
        'part66.logs' => 'Part-66 — Erfahrungsnachweis',
    ],

    'label' => [
        // Kern
        'core.users.view' => 'Benutzer ansehen',
        'core.users.manage' => 'Benutzer verwalten',
        'core.roles.manage' => 'Rollen und Rechte verwalten',
        'core.qualifications.manage' => 'Qualifikationen eintragen und ändern',
        'core.audit.view' => 'Protokoll einsehen',
        'core.audit.pseudonymise' => 'Personendaten im Protokoll pseudonymisieren',
        'core.modules.manage' => 'Module aktivieren und deaktivieren',
        'core.settings.manage' => 'Einstellungen ändern',

        // Lager
        'stock.view' => 'Bestand ansehen',
        'stock.receive' => 'Wareneingang buchen',
        'stock.issue' => 'Material entnehmen',
        'stock.quarantine' => 'Vorsorglich sperren',
        'stock.quarantine.certify' => 'Zustand feststellen und freigeben',
        'stock.quarantine.release' => 'Wareneingang nach Prüfung annehmen',
        'stock.scrap' => 'Ausmustern und entsorgen',
        'stock.transfer' => 'Bestand umlagern',
        'stock.correct' => 'Bestand korrigieren',
        'stock.repair' => 'Reparaturversand abwickeln',
        'stock.report' => 'Auswertungen abrufen',
        'stock.orders.manage' => 'Bestellungen verwalten',
        'parts.types.manage' => 'Bauteiltypen verwalten',
        'storage.locations.manage' => 'Lagerorte und Fächer verwalten',
        'suppliers.manage' => 'Lieferanten verwalten',

        // Flotte
        'fleet.view' => 'Flotte ansehen',
        'fleet.manage' => 'Luftfahrzeuge und Stammdaten verwalten',
        'fleet.counters.record' => 'Zählerstände erfassen',
        'fleet.components.manage' => 'Komponenten ein- und ausbauen',
        'fleet.programme.manage' => 'Instandhaltungsprogramm und Dokumente pflegen',
        'fleet.reviews.record' => 'Nachprüfungen (ARC) erfassen',
        'fleet.external_work.manage' => 'Fremdvergaben beauftragen',
        'fleet.external_work.accept' => 'Fremdarbeiten übernehmen',

        // Werkstatt
        'workorders.view' => 'Vorgänge und Arbeitskarten ansehen',
        'workorders.manage' => 'Vorgänge und Arbeitskarten verwalten',
        'workorders.cards.work' => 'An Arbeitskarten arbeiten und Zeiten erfassen',
        'workorders.cards.inspect' => 'Unabhängige Kontrolle durchführen',
        'workorders.cards.certify' => 'Arbeiten freigeben (CRS)',
        'workorders.findings.record' => 'Befunde erfassen',
        'workorders.findings.report' => 'Befunde melden (Befundbericht)',
        'workorders.findings.defer' => 'Befunde zurückstellen',

        // LTA / TM
        'directives.view' => 'LTA/TM ansehen',
        'directives.manage' => 'Quellen und Listen verwalten',
        'directives.assess' => 'LTA/TM beurteilen',

        // Eingangsprüfung
        'inspection.view' => 'Eingangsprüfungen ansehen',
        'inspection.perform' => 'Eingangsprüfung durchführen',

        // Werkzeuge
        'tools.view' => 'Werkzeuge ansehen',
        'tools.issue' => 'Werkzeuge ausgeben und zurücknehmen',
        'tools.manage' => 'Werkzeuge verwalten',
        'tools.assess' => 'Werkzeugzustand beurteilen',

        // Part-66
        'part66.logs.view_all' => 'Erfahrungsnachweise aller Personen einsehen',
    ],

    'hint' => [
        'core.audit.pseudonymise' => 'Ersetzt Personendaten im Protokoll. Namen und '
            .'Lizenznummern in Freigaben bleiben unberührt — Aufbewahrungspflicht '
            .'geht vor.',
        'stock.quarantine' => 'Vorsorglich, jederzeit rücknehmbar. Keine Qualifikation '
            .'nötig — fehlendes Papier reicht als Grund.',
        'stock.quarantine.certify' => 'Zusätzlich ist eine gültige Part-66-Lizenz nötig. '
            .'Gilt für Urteile über den Zustand — unbrauchbar erklären und den Weg '
            .'zurück in den Bestand. Die Annahme des Wareneingangs hat ihr eigenes '
            .'Recht und braucht keine Lizenz.',
        'stock.quarantine.release' => 'Hebt die vorsorgliche Sperre des Wareneingangs '
            .'nach bestandener Prüfung auf — eine Rechtefrage, keine Lizenzfrage '
            .'(145.A.42: Aufgabe kompetenten Lagerpersonals). Wer die Eingangsprüfung '
            .'durchführt, braucht dieses Recht dazu.',
        'stock.scrap' => 'Zusätzlich ist eine gültige Part-66-Lizenz nötig. Ausmustern '
            .'ist endgültig: ein ausgemustertes Teil kommt nicht zurück in den Bestand.',
        'parts.types.manage' => 'Legt fest, welchen Nachweis ein Teil braucht — nicht '
            .'dasselbe wie Ware einbuchen.',
        'workorders.cards.certify' => 'Zusätzlich ist eine gültige Qualifikation nötig '
            .'(Part-66 oder Pilot-Owner in dessen Grenzen). Die zweite Unterschrift '
            .'auf der Karte — die mit dem Urteil.',
        'workorders.findings.defer' => 'Zusätzlich ist eine gültige Qualifikation nötig: '
            .'Einen Riss bemerken kann jeder — entscheiden, dass er bis zur nächsten '
            .'Prüfung hält, ist eine Feststellung.',
        'fleet.external_work.accept' => 'Zusätzlich ist eine gültige Qualifikation '
            .'nötig: Hier unterschreibt jemand für Arbeit, bei der er nicht '
            .'zugesehen hat.',
        'part66.logs.view_all' => 'Fremde Erfahrungsnachweise sind Personendaten — '
            .'diese Einsicht braucht der Werkstattleiter zum Bestätigen, sonst '
            .'niemand.',
    ],

];
