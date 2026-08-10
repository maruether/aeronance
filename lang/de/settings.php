<?php

declare(strict_types=1);

return [
    'title' => 'Einstellungen',
    'subheading' => 'Alles, was eine Organisation selbst festlegt — ohne eine einzige Datei anzufassen.',
    'save' => 'Speichern',
    /*
     * Der Testversand. Er prueft, was im Formular STEHT, nicht was gespeichert
     * ist -- sonst muesste man erst speichern, um zu erfahren, ob der Zugang
     * stimmt, und haette im Fehlerfall einen kaputten Zugang in der Datenbank.
     */
    'mail_test' => [
        'action' => 'Testmail senden',
        'heading' => 'Testmail senden',
        'description' => 'Verschickt eine Nachricht mit den Angaben, die gerade in '
            .'diesem Abschnitt stehen — auch wenn sie noch nicht gespeichert sind. '
            .'Bleibt das Passwortfeld leer, gilt das hinterlegte.',
        'recipient' => 'An welche Adresse?',
        'not_configured' => 'Kein SMTP-Server eingetragen — ohne ihn verschickt die '
            .'Anwendung nichts.',
        'sent' => 'Abgeschickt an :empfaenger.',
        'sent_hint' => 'Kommt nichts an, liegt es nicht mehr an dieser Anwendung — '
            .'dann beim Empfänger im Spam nachsehen oder beim Anbieter. Fehlende '
            .'SPF-, DKIM- und DMARC-Einträge sind der häufigste Grund.',
        'failed' => 'Der Versand ist fehlgeschlagen. Der Mailserver sagt:',
    ],

    'saved' => 'Einstellungen gespeichert.',
    'reset' => 'zurücksetzen',
    'reset_confirm' => 'Der gespeicherte Wert wird entfernt. Danach gilt wieder die '
        .'Umgebungsvariable, falls eine gesetzt ist — sonst die Vorgabe.',
    'reset_done' => 'Auf Umgebung bzw. Vorgabe zurückgesetzt.',

    'secret_set' => 'Ein Wert ist hinterlegt. Feld leer lassen heisst: unverändert.',
    'offsite_locked' => 'Gesperrt: erst eine Backup-Verschlüsselung einstellen — '
        .'unverschlüsselt verlässt keine Sicherung das System.',
    'from_environment' => 'Kommt derzeit aus der Umgebung (z. B. docker-compose). '
        .'Sobald hier gespeichert wird, gilt der gespeicherte Wert dauerhaft.',

    'group' => [
        'organisation' => 'Organisation',
        'backup' => 'Sicherung',
        'offsite' => 'Auslagerung',
        'retention' => 'Aufbewahrung',
        'operation' => 'Betrieb',
        'mail' => 'E-Mail',
        'vereinsflieger' => 'Vereinsflieger',
    ],

    'group_help' => [
        'organisation' => 'Name, Logo und Zeitzone. Alles drei steht auf jedem Ausdruck.',
        'backup' => 'Ohne Verschlüsselung verlässt keine Sicherung dieses System.',
        'offsite' => 'Der zweite Ort. Eine Sicherung neben der Datenbank auf '
            .'derselben Platte überlebt genau den Fall nicht, für den sie gemacht ist.',
        'retention' => 'Alle Regeln sind ab Werk AUS. Was sie löschen, ist danach fort — '
            .'Freigabeinhalte bleiben davon unberührt.',
        'operation' => 'Grenzwerte und Prüfungen des laufenden Betriebs.',
        'mail' => 'Der Postausgang. Ohne Zugang wird nichts verschickt, sondern nur '
            .'ins Protokoll geschrieben — Einladungen und „Passwort vergessen" '
            .'kommen dann nie an. Der Testversand unten prüft die Angaben sofort.',
        'vereinsflieger' => 'Zugang und Rückwege. Welche Rolle jemand bekommt, entscheidet '
            .'die Zuordnung im Kern — die Freigabeberechtigung wird grundsätzlich nicht '
            .'übernommen.',
    ],

    /*
     * ─────────────────────────────────────────────────────────────────────────
     * DIE TEXTE ZU JEDER EINSTELLUNG DES KATALOGS.
     *
     * Struktur je Einstellung: label, optional help, bei select-Feldern
     * options. Der Pfad ist der Einstellungs-Schlüssel aus dem Katalog
     * (SettingsCatalogue); die Punkte darin sind hier Verschachtelung.
     *
     * BEIM ÜBERSETZEN: Die SCHLÜSSEL unter options sind gespeicherte WERTE
     * ('none', 'recipient', '1', …) — sie bleiben, wie sie sind. Nur die
     * Beschriftungen sind Sprache.
     * ─────────────────────────────────────────────────────────────────────────
     */
    'catalogue' => [
        'organisation' => [
            'name' => [
                'label' => 'Name der Organisation',
                'help' => 'Steht in der Kopfzeile und auf jedem Ausdruck.',
            ],
            'logo' => [
                'label' => 'Logo',
                'help' => 'Erscheint in der Kopfzeile, auf der Anmeldeseite und auf '
                    .'jedem Ausdruck. PNG, JPEG oder WebP, höchstens 1 MB.',
            ],
            'timezone' => [
                'label' => 'Zeitzone',
                'help' => 'Die Anwendung rechnet in UTC; Papierdokumente wie der '
                    .'Sperrzettel tragen diese Zeit. Ein Teil, das um 00:30 '
                    .'Ortszeit gesperrt wird, darf nicht den Vortag tragen.',
            ],
        ],

        'mail' => [
            'host' => [
                'label' => 'SMTP-Server',
                'help' => 'Leer lassen heisst: Es werden keine Mails verschickt. Anmeldung '
                    .'und Passwortwechsel laufen dann ausschliesslich ueber den Admin '
                    .'oder einen Identity-Provider.',
            ],
            'port' => [
                'label' => 'Port',
                'help' => '587 mit STARTTLS ist der Regelfall, 465 fuer implizites TLS.',
            ],
            'username' => [
                'label' => 'Benutzername',
            ],
            'password' => [
                'label' => 'Passwort',
            ],
            'encryption' => [
                'label' => 'Verschluesselung',
                'options' => [
                    'smtp' => 'STARTTLS (Port 587)',
                    'smtps' => 'TLS (Port 465)',
                ],
                'help' => 'Unverschluesselt gibt es hier bewusst nicht: Ueber diese '
                    .'Verbindung gehen Einladungslinks, und wer sie mitliest, hat ein '
                    .'Konto.',
            ],
            'from_address' => [
                'label' => 'Absenderadresse',
                'help' => 'Muss zum SMTP-Zugang passen -- die meisten Anbieter weisen eine '
                    .'fremde Absenderadresse ab, und der Fehler steht dann nur im Log.',
            ],
            'invite_automatically' => [
                'label' => 'Einladungen automatisch versenden',
                'help' => 'Jedes neu angelegte Konto bekommt sofort eine Einladung. Aus '
                    .'heisst nicht "nie", sondern "auf Knopfdruck" -- in der '
                    .'Benutzerliste steht dafuer eine Schaltflaeche. Beim ersten '
                    .'Mitgliederabgleich koennen auf einen Schlag hunderte Konten '
                    .'entstehen; ob die alle sofort eine Mail bekommen sollen, ist '
                    .'eine Entscheidung und keine Voreinstellung.',
            ],
            'from_name' => [
                'label' => 'Absendername',
                'help' => 'Leer lassen: Dann steht der Name der Organisation davor.',
            ],
        ],

        'backup' => [
            'encryption' => [
                'mode' => [
                    'label' => 'Verschlüsselung',
                    'options' => [
                        'none' => 'aus (nur lokal zulässig)',
                        'recipient' => 'öffentlicher Schlüssel (empfohlen)',
                        'passphrase' => 'Passwort',
                    ],
                    'help' => 'Ohne Verschlüsselung verlässt keine Sicherung das System: '
                        .'Ist ein Auslagerungsziel eingerichtet, scheitert der Lauf.',
                ],
                'public_key' => [
                    'label' => 'Öffentlicher Schlüssel',
                    'help' => 'Der ÖFFENTLICHE Teil (PEM). Der private bleibt bei der Organisation '
                        .'— geht er verloren, sind die Sicherungen wertlos.',
                ],
                'passphrase' => [
                    'label' => 'Backup-Passwort',
                    'help' => 'Mindestens 12 Zeichen. Unbedingt ausserhalb dieses Systems '
                        .'notieren — ohne es sind die Sicherungen nicht zu öffnen.',
                ],
            ],
            'offsite' => [
                'disk' => [
                    'label' => 'Ziel',
                    'options' => [
                        '' => 'keines — die Sicherung liegt nur hier',
                        'offsite_local' => 'eingehängtes Verzeichnis',
                        'offsite_sftp' => 'SFTP (z. B. Storage Box)',
                        'offsite_s3' => 'S3-kompatibel',
                    ],
                    'help' => 'Je nach Ziel erscheinen die passenden Felder darunter.',
                ],
                'prefix' => [
                    'label' => 'Unterverzeichnis am Ziel',
                    'help' => 'Damit sich zwei Organisationen einen Speicher teilen können.',
                ],
                'keep' => [
                    'label' => 'Sicherungen am Ziel behalten',
                ],
                'path' => [
                    'label' => 'Pfad (eingehängtes Verzeichnis)',
                ],
            ],
            'sftp' => [
                'host' => [
                    'label' => 'SFTP-Server',
                ],
                'port' => [
                    'label' => 'SFTP-Port',
                ],
                'username' => [
                    'label' => 'SFTP-Benutzer',
                ],
                'password' => [
                    'label' => 'SFTP-Passwort',
                    'help' => 'Nur nötig, wenn kein Schlüssel hinterlegt ist.',
                ],
                'private_key' => [
                    'label' => 'Privater SFTP-Schlüssel',
                    'help' => 'Wird verschlüsselt in der Datenbank abgelegt — und damit '
                        .'auch in jeder Sicherung. Wer das nicht will, nimmt ein Passwort.',
                ],
                'root' => [
                    'label' => 'Verzeichnis auf dem SFTP-Server',
                ],
            ],
            's3' => [
                'key' => [
                    'label' => 'S3 Access Key',
                ],
                'secret' => [
                    'label' => 'S3 Secret',
                ],
                'region' => [
                    'label' => 'S3-Region',
                ],
                'bucket' => [
                    'label' => 'S3-Bucket',
                ],
                'endpoint' => [
                    'label' => 'S3-Endpunkt',
                    'help' => 'Bei Backblaze, Wasabi oder MinIO nötig; bei AWS leer lassen.',
                ],
            ],
        ],

        'retention' => [
            'activity_log' => [
                'enabled' => [
                    'label' => 'Aktivitätsprotokoll aufräumen',
                ],
                'days' => [
                    'label' => 'Aufbewahrung Aktivitätsprotokoll (Tage)',
                    'help' => 'Nicht kürzer als die Frist der Aufzeichnungen, über die es '
                        .'Auskunft gibt — für uns drei Jahre (145.A.55).',
                ],
            ],
            'break_glass_log' => [
                'enabled' => [
                    'label' => 'Break-glass-Protokoll aufräumen',
                ],
                'days' => [
                    'label' => 'Aufbewahrung Break-glass-Protokoll (Tage)',
                    'help' => 'Überlebt das Aktivitätsprotokoll bewusst — ein '
                        .'privilegierter Zugriff ist das, was man am ehesten '
                        .'rekonstruieren will.',
                ],
            ],
            'pseudonymise_former_members' => [
                'enabled' => [
                    'label' => 'Ausgetretene Mitglieder pseudonymisieren',
                    'help' => 'Lässt Freigabeinhalte unberührt: Ein Name in einer '
                        .'Freigabe ist der Inhalt der Bescheinigung, und die '
                        .'Aufbewahrungspflicht sticht das Löschrecht (E3a).',
                ],
                'days' => [
                    'label' => 'Frist nach Austritt (Tage)',
                ],
            ],
        ],

        'vereinsflieger' => [
            'workhours' => [
                'enabled' => [
                    'label' => 'Arbeitsstunden nach Vereinsflieger schreiben',
                    'help' => 'Schreibt in ein fremdes, produktives System — und zwar '
                        .'endgültig: Vereinsflieger kann eine gebuchte Stunde weder '
                        .'ändern noch löschen. Ab Werk aus.',
                ],
                'category' => [
                    'label' => 'Kategorie',
                    'help' => 'Die Arbeitsstunden-Kategorie, in die Aeronance drüben bucht. '
                        .'Die Liste kommt aus Vereinsflieger und wird bei jedem Abgleich '
                        .'aufgefrischt. Eine dort abgeschaltete Kategorie ist über die '
                        .'Schnittstelle trotzdem beschreibbar — genau so lässt sich trennen, '
                        .'was aus Aeronance kommt.',
                    'help_empty' => 'Die Nummer aus Vereinsflieger, z. B. „7265" für '
                        .'Wartung/Werkstatt. Nach dem ersten Abgleich steht hier eine '
                        .'Auswahlliste — die Kategorien werden dabei mitgelesen.',
                    'disabled_suffix' => '(in Vereinsflieger abgeschaltet)',
                    'unknown_suffix' => '(nicht mehr in der Liste)',
                ],
                'status' => [
                    'label' => 'Status der gebuchten Stunde',
                    'options' => [
                        '1' => 'Nicht bewertet — der Verein bewertet wie gewohnt',
                        '2' => 'Akzeptiert — sofort gültig; das Mitglied kann nichts mehr ändern',
                    ],
                    'help' => 'GEMESSEN: Vereinsflieger übernimmt den Status beim Anlegen. '
                        .'„Akzeptiert" spart dem Werkstattleiter die Freigabe und sperrt den '
                        .'Eintrag drüben für das MITGLIED — die Abzeichner des Vereins kommen '
                        .'weiterhin dran. Solange er „nicht bewertet" ist, kann auch das '
                        .'Mitglied ihn dort noch ändern. Ein Bewerter wird nicht '
                        .'mitgeschrieben; wer akzeptiert hat, steht nur in Aeronance.',
                ],
            ],
            // "writeback" stand hier — ein Schalter ohne Funktion (Vereinsfliegers
            // Wartungs-Endpunkt ist lesend). Entfernt: funktionslose Schalter taugen nix.
        ],

        'documents' => [
            'max_size_mb' => [
                'label' => 'Grösste Dokumentgrösse (MB)',
            ],
        ],
        'due_window_days' => [
            'label' => 'Vorschau auf Fälligkeiten (Tage)',
        ],
        'virus_scanner' => [
            'label' => 'Virenscanner',
            'options' => ['none' => 'keiner', 'clamav' => 'ClamAV'],
        ],
        'clamav' => [
            'host' => [
                'label' => 'ClamAV-Server',
            ],
            'port' => [
                'label' => 'ClamAV-Port',
            ],
            'fail_closed' => [
                'label' => 'Ohne erreichbaren Scanner keine Uploads',
                'help' => 'Aus bedeutet: Fällt der Scanner aus, werden Dateien '
                    .'ungeprüft angenommen.',
            ],
        ],
    ],
];
