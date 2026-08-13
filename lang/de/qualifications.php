<?php

declare(strict_types=1);

return [

    'singular' => 'Qualifikation',
    'plural' => 'Qualifikationen',
    'add' => 'Qualifikation eintragen',
    'expired' => 'abgelaufen',
    'no_end_date' => 'unbefristet',

    'field' => [
        'type' => 'Art',
        'subject' => 'Gegenstand der Schulung',
        'reference' => 'Nummer',
        'issuer' => 'Ausgestellt von',
        'certificate' => 'Urkunde',
        'category' => 'Kategorie',
        'scope' => 'Gilt für',
        'valid_from' => 'Gültig ab',
        'valid_until' => 'Gültig bis',
        'note' => 'Bemerkung',
    ],

    'placeholder' => [
        'subject' => 'z. B. Rotax 912 Service Training',
        'issuer' => 'z. B. Rotax Service Center Musterstadt',
    ],

    'help' => [
        'subject' => 'Worum es ging — die Bezeichnung der Schulung, nicht ihre Nummer. '
            .'Freitext, weil die Bandbreite kein Schema hergibt: Musterschulungen, '
            .'Verfahren wie Kleben oder zerstörungsfreie Prüfung, Ausrüstung wie '
            .'Rettungsgeräte oder Sauerstoffanlagen, im 145-Umfeld Human Factors.',
        'reference' => 'Lizenz- oder Berechtigungsnummer. Wird bei Feststellungen und '
            .'Befundberichten unveränderlich mitgeschrieben. Bei einer '
            .'Pilot-Owner-Berechtigung gehört hier die Flugscheinnummer hinein — '
            .'mit ihr zeichnet der P/O seine Befundberichte ab.',
        'issuer' => 'Wer geschult oder ausgestellt hat — Schulungsstelle, Hersteller oder '
            .'Behörde. Ohne Aussteller ist ein Zertifikat eine Behauptung ohne Absender.',
        'certificate' => 'Die Urkunde als PDF oder Bild. Liegt auf der privaten Ablage, '
            .'nicht im Webroot.',
        'valid_until' => 'Leer lassen, wenn unbefristet. Eine abgelaufene Qualifikation '
            .'deckt keine Feststellung mehr ab.',
    ],

    'type' => [
        'part66_licence' => 'Part-66-Lizenz',
        'training_certificate' => 'Schulungsnachweis',
        'pilot_owner_authorisation' => 'Pilot-Owner-Berechtigung',
    ],

    'scope' => [
        'general' => 'allgemein',
        'aircraft' => 'nur für :registration',
    ],

    'hint' => [
        'pilot_owner' => 'Die Pilot-Owner-Berechtigung gilt nur für das Luftfahrzeug, '
            .'für das die Person im Instandhaltungsprogramm eingetragen ist.',
        'expired' => 'Diese Qualifikation ist am :date abgelaufen und deckt keine '
            .'Freigaben mehr ab.',
    ],

];
