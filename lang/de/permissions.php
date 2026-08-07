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
        'stock.scrap' => 'Ausmustern und entsorgen',
        'stock.report' => 'Auswertungen abrufen',
        'parts.types.manage' => 'Bauteiltypen verwalten',
        'storage.locations.manage' => 'Lagerorte und Fächer verwalten',
        'suppliers.manage' => 'Lieferanten verwalten',
    ],

    'hint' => [
        'core.audit.pseudonymise' => 'Ersetzt Personendaten im Protokoll. Namen und '
            .'Lizenznummern in Freigaben bleiben unberührt — Aufbewahrungspflicht '
            .'geht vor.',
        'stock.quarantine' => 'Vorsorglich, jederzeit rücknehmbar. Keine Qualifikation '
            .'nötig — fehlendes Papier reicht als Grund.',
        'stock.quarantine.certify' => 'Zusätzlich ist eine gültige Part-66-Lizenz nötig. '
            .'Auch die Rückgabe in den Bestand ist eine Feststellung.',
        'stock.scrap' => 'Zusätzlich ist eine gültige Part-66-Lizenz nötig. Ausmustern '
            .'ist endgültig: ein ausgemustertes Teil kommt nicht zurück in den Bestand.',
        'parts.types.manage' => 'Legt fest, welchen Nachweis ein Teil braucht — nicht '
            .'dasselbe wie Ware einbuchen.',
    ],

];
