<?php

declare(strict_types=1);

namespace App\Core\Settings;

/**
 * Alles, was eine Organisation einstellen kann -- und nichts, was sie nicht kann.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WAS HIER NICHT STEHT, IST DIE EIGENTLICHE AUSSAGE.
 *
 * Zwei Dinge bleiben in Dateien, und zwar zwingend:
 *
 *   APP_KEY   entschlüsselt genau diese Tabelle. In ihr zu liegen ginge nicht.
 *   DB_*      ist die Verbindung, über die die Tabelle erreicht wird.
 *
 * Das ist die harte Untergrenze. Alles andere -- 25 Werte, gezählt -- lag
 * vorher in .env oder in config/aeronance.php und wandert hierher.
 *
 * Der schlimmste Fall waren die Aufbewahrungsfristen: nicht einmal über .env
 * erreichbar, sondern nur durch Editieren einer PHP-Datei, die im Docker-Kanal
 * IM IMAGE liegt und bei jedem Update verlorengeht. Sie waren dort faktisch
 * nicht einschaltbar.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WAS BEWUSST NICHT EINSTELLBAR IST
 *
 * Es gibt keinen Schlüssel, der das Audit-Log abschaltet, und keinen, der die
 * Aufbewahrung unter die gesetzliche Frist drückt. Dass eine Einstellung fehlt,
 * ist hier gelegentlich der Mechanismus -- siehe E3.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SettingsCatalogue
{
    public const GROUP_ORGANISATION = 'organisation';

    public const GROUP_BACKUP = 'backup';

    public const GROUP_OFFSITE = 'offsite';

    public const GROUP_RETENTION = 'retention';

    public const GROUP_OPERATION = 'operation';

    public const GROUP_MAIL = 'mail';

    public const GROUP_VEREINSFLIEGER = 'vereinsflieger';

    /**
     * @return list<SettingDefinition>
     */
    public static function all(): array
    {
        return [
            // ── Organisation ────────────────────────────────────────────────
            new SettingDefinition(
                key: 'organisation.name',
                configPath: 'aeronance.organisation.name',
                envVar: 'ORGANISATION_NAME',
                group: self::GROUP_ORGANISATION,
                label: 'Name der Organisation',
                default: 'Aeronance',
                help: 'Steht in der Kopfzeile und auf jedem Ausdruck.',
            ),
            new SettingDefinition(
                key: 'organisation.logo',
                configPath: 'aeronance.organisation.logo',
                envVar: 'ORGANISATION_LOGO',
                group: self::GROUP_ORGANISATION,
                label: 'Logo',
                type: 'image',
                help: 'Erscheint in der Kopfzeile, auf der Anmeldeseite und auf '
                    .'jedem Ausdruck. PNG, JPEG oder WebP, höchstens 1 MB.',
            ),
            new SettingDefinition(
                key: 'organisation.timezone',
                configPath: 'aeronance.organisation.timezone',
                envVar: 'ORGANISATION_TIMEZONE',
                group: self::GROUP_ORGANISATION,
                label: 'Zeitzone',
                default: 'Europe/Berlin',
                help: 'Die Anwendung rechnet in UTC; Papierdokumente wie der '
                    .'Sperrzettel tragen diese Zeit. Ein Teil, das um 00:30 '
                    .'Ortszeit gesperrt wird, darf nicht den Vortag tragen.',
            ),

            /*
             * ── E-Mail ──────────────────────────────────────────────────────
             *
             * ZIELT AUF config/mail.php, nicht auf aeronance.* -- Laravels
             * Mailer liest von dort, und ein eigener Zweig daneben waere ein
             * zweiter Ort fuer dieselbe Wahrheit.
             *
             * OHNE ZUGANG WIRD NICHT VERSCHICKT, sondern ins Log geschrieben
             * (Laravels Vorgabe "log"). Das ist die richtige Richtung: Eine
             * Einladung, die scheinbar rausging und nie ankam, kostet mehr als
             * eine, die gar nicht erst losfliegt.
             */
            new SettingDefinition(
                key: 'mail.host',
                configPath: 'mail.mailers.smtp.host',
                envVar: 'MAIL_HOST',
                group: self::GROUP_MAIL,
                label: 'SMTP-Server',
                help: 'Leer lassen heisst: Es werden keine Mails verschickt. Anmeldung '
                    .'und Passwortwechsel laufen dann ausschliesslich ueber den Admin '
                    .'oder einen Identity-Provider.',
            ),
            new SettingDefinition(
                key: 'mail.port',
                configPath: 'mail.mailers.smtp.port',
                envVar: 'MAIL_PORT',
                group: self::GROUP_MAIL,
                label: 'Port',
                type: 'int',
                default: 587,
                help: '587 mit STARTTLS ist der Regelfall, 465 fuer implizites TLS.',
            ),
            new SettingDefinition(
                key: 'mail.username',
                configPath: 'mail.mailers.smtp.username',
                envVar: 'MAIL_USERNAME',
                group: self::GROUP_MAIL,
                label: 'Benutzername',
            ),
            new SettingDefinition(
                key: 'mail.password',
                configPath: 'mail.mailers.smtp.password',
                envVar: 'MAIL_PASSWORD',
                group: self::GROUP_MAIL,
                label: 'Passwort',
                type: 'secret',
            ),
            new SettingDefinition(
                key: 'mail.encryption',
                configPath: 'mail.mailers.smtp.scheme',
                envVar: 'MAIL_SCHEME',
                group: self::GROUP_MAIL,
                label: 'Verschluesselung',
                type: 'select',
                default: 'smtps',
                options: [
                    'smtp' => 'STARTTLS (Port 587)',
                    'smtps' => 'TLS (Port 465)',
                ],
                help: 'Unverschluesselt gibt es hier bewusst nicht: Ueber diese '
                    .'Verbindung gehen Einladungslinks, und wer sie mitliest, hat ein '
                    .'Konto.',
            ),
            new SettingDefinition(
                key: 'mail.from_address',
                configPath: 'mail.from.address',
                envVar: 'MAIL_FROM_ADDRESS',
                group: self::GROUP_MAIL,
                label: 'Absenderadresse',
                help: 'Muss zum SMTP-Zugang passen -- die meisten Anbieter weisen eine '
                    .'fremde Absenderadresse ab, und der Fehler steht dann nur im Log.',
            ),
            new SettingDefinition(
                key: 'mail.invite_automatically',
                configPath: 'aeronance.mail.invite_automatically',
                envVar: 'MAIL_INVITE_AUTOMATICALLY',
                group: self::GROUP_MAIL,
                label: 'Einladungen automatisch versenden',
                type: 'bool',
                default: false,
                help: 'Jedes neu angelegte Konto bekommt sofort eine Einladung. Aus '
                    .'heisst nicht "nie", sondern "auf Knopfdruck" -- in der '
                    .'Benutzerliste steht dafuer eine Schaltflaeche. Beim ersten '
                    .'Mitgliederabgleich koennen auf einen Schlag hunderte Konten '
                    .'entstehen; ob die alle sofort eine Mail bekommen sollen, ist '
                    .'eine Entscheidung und keine Voreinstellung.',
            ),
            new SettingDefinition(
                key: 'mail.from_name',
                configPath: 'mail.from.name',
                envVar: 'MAIL_FROM_NAME',
                group: self::GROUP_MAIL,
                label: 'Absendername',
                help: 'Leer lassen: Dann steht der Name der Organisation davor.',
            ),

            // ── Sicherung ───────────────────────────────────────────────────
            new SettingDefinition(
                key: 'backup.encryption.mode',
                configPath: 'aeronance.backup.encryption.mode',
                envVar: 'BACKUP_ENCRYPTION',
                group: self::GROUP_BACKUP,
                label: 'Verschlüsselung',
                type: 'select',
                default: 'none',
                options: [
                    'none' => 'aus (nur lokal zulässig)',
                    'recipient' => 'öffentlicher Schlüssel (empfohlen)',
                    'passphrase' => 'Passwort',
                ],
                help: 'Ohne Verschlüsselung verlässt keine Sicherung das System: '
                    .'Ist ein Auslagerungsziel eingerichtet, scheitert der Lauf.',
            ),
            new SettingDefinition(
                key: 'backup.encryption.public_key',
                configPath: 'aeronance.backup.encryption.public_key',
                envVar: 'BACKUP_PUBLIC_KEY',
                group: self::GROUP_BACKUP,
                label: 'Öffentlicher Schlüssel',
                type: 'file',
                help: 'Der ÖFFENTLICHE Teil (PEM). Der private bleibt bei der Organisation '
                    .'— geht er verloren, sind die Sicherungen wertlos.',
            ),
            new SettingDefinition(
                key: 'backup.encryption.passphrase',
                configPath: 'aeronance.backup.encryption.passphrase',
                envVar: 'BACKUP_PASSPHRASE',
                group: self::GROUP_BACKUP,
                label: 'Backup-Passwort',
                type: 'secret',
                help: 'Mindestens 12 Zeichen. Unbedingt ausserhalb dieses Systems '
                    .'notieren — ohne es sind die Sicherungen nicht zu öffnen.',
            ),

            // ── Auslagerung ─────────────────────────────────────────────────
            new SettingDefinition(
                key: 'backup.offsite.disk',
                configPath: 'aeronance.backup.offsite.disk',
                envVar: 'BACKUP_OFFSITE_DISK',
                group: self::GROUP_OFFSITE,
                label: 'Ziel',
                type: 'select',
                default: '',
                options: [
                    '' => 'keines — die Sicherung liegt nur hier',
                    'offsite_local' => 'eingehängtes Verzeichnis',
                    'offsite_sftp' => 'SFTP (z. B. Storage Box)',
                    'offsite_s3' => 'S3-kompatibel',
                ],
            ),
            new SettingDefinition(
                key: 'backup.offsite.prefix',
                configPath: 'aeronance.backup.offsite.prefix',
                envVar: 'BACKUP_OFFSITE_PREFIX',
                group: self::GROUP_OFFSITE,
                label: 'Unterverzeichnis am Ziel',
                help: 'Damit sich zwei Organisationen einen Speicher teilen können.',
            ),
            new SettingDefinition(
                key: 'backup.offsite.keep',
                configPath: 'aeronance.backup.offsite.keep',
                envVar: 'BACKUP_OFFSITE_KEEP',
                group: self::GROUP_OFFSITE,
                label: 'Sicherungen am Ziel behalten',
                type: 'int',
                default: 30,
            ),
            new SettingDefinition(
                key: 'backup.offsite.path',
                configPath: 'filesystems.disks.offsite_local.root',
                envVar: 'BACKUP_OFFSITE_PATH',
                group: self::GROUP_OFFSITE,
                label: 'Pfad (eingehängtes Verzeichnis)',
                default: '/mnt/backup',
            ),
            new SettingDefinition(
                key: 'backup.sftp.host',
                configPath: 'filesystems.disks.offsite_sftp.host',
                envVar: 'BACKUP_SFTP_HOST',
                group: self::GROUP_OFFSITE,
                label: 'SFTP-Server',
            ),
            new SettingDefinition(
                key: 'backup.sftp.port',
                configPath: 'filesystems.disks.offsite_sftp.port',
                envVar: 'BACKUP_SFTP_PORT',
                group: self::GROUP_OFFSITE,
                label: 'SFTP-Port',
                type: 'int',
                default: 22,
            ),
            new SettingDefinition(
                key: 'backup.sftp.username',
                configPath: 'filesystems.disks.offsite_sftp.username',
                envVar: 'BACKUP_SFTP_USERNAME',
                group: self::GROUP_OFFSITE,
                label: 'SFTP-Benutzer',
            ),
            new SettingDefinition(
                key: 'backup.sftp.password',
                configPath: 'filesystems.disks.offsite_sftp.password',
                envVar: 'BACKUP_SFTP_PASSWORD',
                group: self::GROUP_OFFSITE,
                label: 'SFTP-Passwort',
                type: 'secret',
                help: 'Nur nötig, wenn kein Schlüssel hinterlegt ist.',
            ),
            new SettingDefinition(
                key: 'backup.sftp.private_key',
                configPath: 'filesystems.disks.offsite_sftp.privateKey',
                envVar: 'BACKUP_SFTP_PRIVATE_KEY',
                group: self::GROUP_OFFSITE,
                label: 'Privater SFTP-Schlüssel',
                type: 'file',
                help: 'Wird verschlüsselt in der Datenbank abgelegt — und damit '
                    .'auch in jeder Sicherung. Wer das nicht will, nimmt ein Passwort.',
            ),
            new SettingDefinition(
                key: 'backup.sftp.root',
                configPath: 'filesystems.disks.offsite_sftp.root',
                envVar: 'BACKUP_SFTP_ROOT',
                group: self::GROUP_OFFSITE,
                label: 'Verzeichnis auf dem SFTP-Server',
            ),
            new SettingDefinition(
                key: 'backup.s3.key',
                configPath: 'filesystems.disks.offsite_s3.key',
                envVar: 'BACKUP_S3_KEY',
                group: self::GROUP_OFFSITE,
                label: 'S3 Access Key',
            ),
            new SettingDefinition(
                key: 'backup.s3.secret',
                configPath: 'filesystems.disks.offsite_s3.secret',
                envVar: 'BACKUP_S3_SECRET',
                group: self::GROUP_OFFSITE,
                label: 'S3 Secret',
                type: 'secret',
            ),
            new SettingDefinition(
                key: 'backup.s3.region',
                configPath: 'filesystems.disks.offsite_s3.region',
                envVar: 'BACKUP_S3_REGION',
                group: self::GROUP_OFFSITE,
                label: 'S3-Region',
                default: 'eu-central-1',
            ),
            new SettingDefinition(
                key: 'backup.s3.bucket',
                configPath: 'filesystems.disks.offsite_s3.bucket',
                envVar: 'BACKUP_S3_BUCKET',
                group: self::GROUP_OFFSITE,
                label: 'S3-Bucket',
            ),
            new SettingDefinition(
                key: 'backup.s3.endpoint',
                configPath: 'filesystems.disks.offsite_s3.endpoint',
                envVar: 'BACKUP_S3_ENDPOINT',
                group: self::GROUP_OFFSITE,
                label: 'S3-Endpunkt',
                help: 'Bei Backblaze, Wasabi oder MinIO nötig; bei AWS leer lassen.',
            ),

            // ── Aufbewahrung ────────────────────────────────────────────────
            new SettingDefinition(
                key: 'retention.activity_log.enabled',
                configPath: 'aeronance.retention.activity_log.enabled',
                envVar: null,
                group: self::GROUP_RETENTION,
                label: 'Aktivitätsprotokoll aufräumen',
                type: 'bool',
                default: false,
            ),
            new SettingDefinition(
                key: 'retention.activity_log.days',
                configPath: 'aeronance.retention.activity_log.days',
                envVar: null,
                group: self::GROUP_RETENTION,
                label: 'Aufbewahrung Aktivitätsprotokoll (Tage)',
                type: 'int',
                default: 365 * 3,
                help: 'Nicht kürzer als die Frist der Aufzeichnungen, über die es '
                    .'Auskunft gibt — für uns drei Jahre (145.A.55).',
            ),
            new SettingDefinition(
                key: 'retention.break_glass_log.enabled',
                configPath: 'aeronance.retention.break_glass_log.enabled',
                envVar: null,
                group: self::GROUP_RETENTION,
                label: 'Break-glass-Protokoll aufräumen',
                type: 'bool',
                default: false,
            ),
            new SettingDefinition(
                key: 'retention.break_glass_log.days',
                configPath: 'aeronance.retention.break_glass_log.days',
                envVar: null,
                group: self::GROUP_RETENTION,
                label: 'Aufbewahrung Break-glass-Protokoll (Tage)',
                type: 'int',
                default: 365 * 5,
                help: 'Überlebt das Aktivitätsprotokoll bewusst — ein '
                    .'privilegierter Zugriff ist das, was man am ehesten '
                    .'rekonstruieren will.',
            ),
            new SettingDefinition(
                key: 'retention.pseudonymise_former_members.enabled',
                configPath: 'aeronance.retention.pseudonymise_former_members.enabled',
                envVar: null,
                group: self::GROUP_RETENTION,
                label: 'Ausgetretene Mitglieder pseudonymisieren',
                type: 'bool',
                default: false,
                help: 'Lässt Freigabeinhalte unberührt: Ein Name in einer '
                    .'Freigabe ist der Inhalt der Bescheinigung, und die '
                    .'Aufbewahrungspflicht sticht das Löschrecht (E3a).',
            ),
            new SettingDefinition(
                key: 'retention.pseudonymise_former_members.days',
                configPath: 'aeronance.retention.pseudonymise_former_members.days',
                envVar: null,
                group: self::GROUP_RETENTION,
                label: 'Frist nach Austritt (Tage)',
                type: 'int',
                default: 28,
            ),

            /*
             * ── Vereinsflieger ──────────────────────────────────────────────
             *
             * DIE ZUGANGSDATEN STEHEN NICHT MEHR HIER. Sie sind Datensaetze
             * geworden (vereinsflieger_connections), weil eine CAO
             * Luftfahrzeuge MEHRERER Vereine betreut und jeder Verein seinen
             * eigenen Vereinsflieger hat. Eine Einstellung kann genau einen
             * Zugang halten -- das reichte nicht mehr.
             *
             * Was hier bleibt, gilt fuer die ganze Installation und nicht je
             * Verein: ob Arbeitsstunden ueberhaupt geschrieben werden, in
             * welche Kategorie, und mit welchem Status.
             */
            new SettingDefinition(
                key: 'vereinsflieger.workhours.enabled',
                configPath: 'aeronance.vereinsflieger.workhours.enabled',
                envVar: 'VF_WORKHOURS_ENABLED',
                group: self::GROUP_VEREINSFLIEGER,
                label: 'Arbeitsstunden nach Vereinsflieger schreiben',
                type: 'bool',
                default: false,
                help: 'Schreibt in ein fremdes, produktives System — und zwar '
                    .'endgültig: Vereinsflieger kann eine gebuchte Stunde weder '
                    .'ändern noch löschen. Ab Werk aus.',
            ),
            new SettingDefinition(
                key: 'vereinsflieger.workhours.category',
                configPath: 'aeronance.vereinsflieger.workhours.category',
                envVar: 'VF_WORKHOURS_CATEGORY',
                group: self::GROUP_VEREINSFLIEGER,
                label: 'Kategorie (Nummer)',
                help: 'Die Nummer aus Vereinsflieger, z. B. „7265" für Wartung/Werkstatt. '
                    .'Eine Kategorie darf dort auch abgeschaltet sein — über die '
                    .'Schnittstelle ist sie trotzdem beschreibbar, und genau so lässt '
                    .'sich trennen, was aus Aeronance kommt.',
            ),
            new SettingDefinition(
                key: 'vereinsflieger.workhours.status',
                configPath: 'aeronance.vereinsflieger.workhours.status',
                envVar: 'VF_WORKHOURS_STATUS',
                group: self::GROUP_VEREINSFLIEGER,
                label: 'Status der gebuchten Stunde',
                type: 'select',
                options: [
                    '1' => 'Nicht bewertet — der Verein bewertet wie gewohnt',
                    '2' => 'Akzeptiert — sofort gültig und unveränderlich',
                ],
                default: '1',
                help: 'GEMESSEN: Vereinsflieger übernimmt den Status beim Anlegen. '
                    .'„Akzeptiert" spart dem Werkstattleiter die Freigabe UND macht '
                    .'den Eintrag drüben unveränderlich — solange er „nicht bewertet" '
                    .'ist, kann das Mitglied ihn dort noch ändern. Ein Bewerter wird '
                    .'nicht mitgeschrieben; wer akzeptiert hat, steht nur in Aeronance.',
            ),
            new SettingDefinition(
                key: 'vereinsflieger.writeback.maintenance',
                configPath: 'aeronance.vereinsflieger.writeback.maintenance',
                envVar: 'VF_WRITEBACK_MAINTENANCE',
                group: self::GROUP_VEREINSFLIEGER,
                label: 'Instandhaltungspunkte zurückschreiben',
                type: 'bool',
                default: false,
                help: 'Ohne Wirkung, und das bleibt vermutlich so: Vereinsflieger hat '
                    .'für Wartung genau einen Endpunkt, und der ist LESEND. Nachgesehen '
                    .'in allen 48 Methoden des offiziellen Clients — einen Schreibweg '
                    .'gibt es nicht.',
            ),

            // ── Betrieb ─────────────────────────────────────────────────────
            new SettingDefinition(
                key: 'documents.max_size_mb',
                configPath: 'aeronance.documents.max_size_mb',
                envVar: 'AERONANCE_DOCUMENT_MAX_MB',
                group: self::GROUP_OPERATION,
                label: 'Grösste Dokumentgrösse (MB)',
                type: 'int',
                default: 20,
            ),
            new SettingDefinition(
                key: 'due_window_days',
                configPath: 'aeronance.due_window_days',
                envVar: 'AERONANCE_DUE_WINDOW_DAYS',
                group: self::GROUP_OPERATION,
                label: 'Vorschau auf Fälligkeiten (Tage)',
                type: 'int',
                default: 30,
            ),
            new SettingDefinition(
                key: 'virus_scanner',
                configPath: 'aeronance.virus_scanner',
                envVar: 'AERONANCE_VIRUS_SCANNER',
                group: self::GROUP_OPERATION,
                label: 'Virenscanner',
                type: 'select',
                default: 'none',
                options: ['none' => 'keiner', 'clamav' => 'ClamAV'],
            ),
            new SettingDefinition(
                key: 'clamav.host',
                configPath: 'aeronance.clamav.host',
                envVar: 'AERONANCE_CLAMAV_HOST',
                group: self::GROUP_OPERATION,
                label: 'ClamAV-Server',
            ),
            new SettingDefinition(
                key: 'clamav.port',
                configPath: 'aeronance.clamav.port',
                envVar: 'AERONANCE_CLAMAV_PORT',
                group: self::GROUP_OPERATION,
                label: 'ClamAV-Port',
                type: 'int',
                default: 3310,
            ),
            new SettingDefinition(
                key: 'clamav.fail_closed',
                configPath: 'aeronance.clamav.fail_closed',
                envVar: 'AERONANCE_CLAMAV_FAIL_CLOSED',
                group: self::GROUP_OPERATION,
                label: 'Ohne erreichbaren Scanner keine Uploads',
                type: 'bool',
                default: true,
                help: 'Aus bedeutet: Fällt der Scanner aus, werden Dateien '
                    .'ungeprüft angenommen.',
            ),
        ];
    }

    /**
     * @return array<string, SettingDefinition>
     */
    public static function byKey(): array
    {
        $nach = [];

        foreach (self::all() as $definition) {
            $nach[$definition->key] = $definition;
        }

        return $nach;
    }

    /**
     * @return array<string, list<SettingDefinition>>
     */
    public static function byGroup(): array
    {
        $nach = [];

        foreach (self::all() as $definition) {
            $nach[$definition->group][] = $definition;
        }

        return $nach;
    }
}
