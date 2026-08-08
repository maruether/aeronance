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
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE TEXTE STEHEN IN lang/de/settings.php unter "catalogue", aufgelöst über
 * den Einstellungs-Schlüssel (SettingDefinition::label() und Verwandte). Hier
 * steht nur noch Struktur -- Schlüssel, Typ, Vorgabe, Geheimnisstatus.
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
                default: 'Aeronance',
            ),
            new SettingDefinition(
                key: 'organisation.logo',
                configPath: 'aeronance.organisation.logo',
                envVar: 'ORGANISATION_LOGO',
                group: self::GROUP_ORGANISATION,
                type: 'image',
            ),
            new SettingDefinition(
                key: 'organisation.timezone',
                configPath: 'aeronance.organisation.timezone',
                envVar: 'ORGANISATION_TIMEZONE',
                group: self::GROUP_ORGANISATION,
                default: 'Europe/Berlin',
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
            ),
            new SettingDefinition(
                key: 'mail.port',
                configPath: 'mail.mailers.smtp.port',
                envVar: 'MAIL_PORT',
                group: self::GROUP_MAIL,
                type: 'int',
                default: 587,
            ),
            new SettingDefinition(
                key: 'mail.username',
                configPath: 'mail.mailers.smtp.username',
                envVar: 'MAIL_USERNAME',
                group: self::GROUP_MAIL,
            ),
            new SettingDefinition(
                key: 'mail.password',
                configPath: 'mail.mailers.smtp.password',
                envVar: 'MAIL_PASSWORD',
                group: self::GROUP_MAIL,
                type: 'secret',
            ),
            new SettingDefinition(
                key: 'mail.encryption',
                configPath: 'mail.mailers.smtp.scheme',
                envVar: 'MAIL_SCHEME',
                group: self::GROUP_MAIL,
                type: 'select',
                default: 'smtps',
            ),
            new SettingDefinition(
                key: 'mail.from_address',
                configPath: 'mail.from.address',
                envVar: 'MAIL_FROM_ADDRESS',
                group: self::GROUP_MAIL,
            ),
            new SettingDefinition(
                key: 'mail.invite_automatically',
                configPath: 'aeronance.mail.invite_automatically',
                envVar: 'MAIL_INVITE_AUTOMATICALLY',
                group: self::GROUP_MAIL,
                type: 'bool',
                default: false,
            ),
            new SettingDefinition(
                key: 'mail.from_name',
                configPath: 'mail.from.name',
                envVar: 'MAIL_FROM_NAME',
                group: self::GROUP_MAIL,
            ),

            // ── Sicherung ───────────────────────────────────────────────────
            new SettingDefinition(
                key: 'backup.encryption.mode',
                configPath: 'aeronance.backup.encryption.mode',
                envVar: 'BACKUP_ENCRYPTION',
                group: self::GROUP_BACKUP,
                type: 'select',
                default: 'none',
            ),
            new SettingDefinition(
                key: 'backup.encryption.public_key',
                configPath: 'aeronance.backup.encryption.public_key',
                envVar: 'BACKUP_PUBLIC_KEY',
                group: self::GROUP_BACKUP,
                type: 'file',
            ),
            new SettingDefinition(
                key: 'backup.encryption.passphrase',
                configPath: 'aeronance.backup.encryption.passphrase',
                envVar: 'BACKUP_PASSPHRASE',
                group: self::GROUP_BACKUP,
                type: 'secret',
            ),

            // ── Auslagerung ─────────────────────────────────────────────────
            new SettingDefinition(
                key: 'backup.offsite.disk',
                configPath: 'aeronance.backup.offsite.disk',
                envVar: 'BACKUP_OFFSITE_DISK',
                group: self::GROUP_OFFSITE,
                type: 'select',
                default: '',
            ),
            new SettingDefinition(
                key: 'backup.offsite.prefix',
                configPath: 'aeronance.backup.offsite.prefix',
                envVar: 'BACKUP_OFFSITE_PREFIX',
                group: self::GROUP_OFFSITE,
            ),
            new SettingDefinition(
                key: 'backup.offsite.keep',
                configPath: 'aeronance.backup.offsite.keep',
                envVar: 'BACKUP_OFFSITE_KEEP',
                group: self::GROUP_OFFSITE,
                type: 'int',
                default: 30,
            ),
            new SettingDefinition(
                key: 'backup.offsite.path',
                configPath: 'filesystems.disks.offsite_local.root',
                envVar: 'BACKUP_OFFSITE_PATH',
                group: self::GROUP_OFFSITE,
                default: '/mnt/backup',
            ),
            new SettingDefinition(
                key: 'backup.sftp.host',
                configPath: 'filesystems.disks.offsite_sftp.host',
                envVar: 'BACKUP_SFTP_HOST',
                group: self::GROUP_OFFSITE,
            ),
            new SettingDefinition(
                key: 'backup.sftp.port',
                configPath: 'filesystems.disks.offsite_sftp.port',
                envVar: 'BACKUP_SFTP_PORT',
                group: self::GROUP_OFFSITE,
                type: 'int',
                default: 22,
            ),
            new SettingDefinition(
                key: 'backup.sftp.username',
                configPath: 'filesystems.disks.offsite_sftp.username',
                envVar: 'BACKUP_SFTP_USERNAME',
                group: self::GROUP_OFFSITE,
            ),
            new SettingDefinition(
                key: 'backup.sftp.password',
                configPath: 'filesystems.disks.offsite_sftp.password',
                envVar: 'BACKUP_SFTP_PASSWORD',
                group: self::GROUP_OFFSITE,
                type: 'secret',
            ),
            new SettingDefinition(
                key: 'backup.sftp.private_key',
                configPath: 'filesystems.disks.offsite_sftp.privateKey',
                envVar: 'BACKUP_SFTP_PRIVATE_KEY',
                group: self::GROUP_OFFSITE,
                type: 'file',
            ),
            new SettingDefinition(
                key: 'backup.sftp.root',
                configPath: 'filesystems.disks.offsite_sftp.root',
                envVar: 'BACKUP_SFTP_ROOT',
                group: self::GROUP_OFFSITE,
            ),
            new SettingDefinition(
                key: 'backup.s3.key',
                configPath: 'filesystems.disks.offsite_s3.key',
                envVar: 'BACKUP_S3_KEY',
                group: self::GROUP_OFFSITE,
            ),
            new SettingDefinition(
                key: 'backup.s3.secret',
                configPath: 'filesystems.disks.offsite_s3.secret',
                envVar: 'BACKUP_S3_SECRET',
                group: self::GROUP_OFFSITE,
                type: 'secret',
            ),
            new SettingDefinition(
                key: 'backup.s3.region',
                configPath: 'filesystems.disks.offsite_s3.region',
                envVar: 'BACKUP_S3_REGION',
                group: self::GROUP_OFFSITE,
                default: 'eu-central-1',
            ),
            new SettingDefinition(
                key: 'backup.s3.bucket',
                configPath: 'filesystems.disks.offsite_s3.bucket',
                envVar: 'BACKUP_S3_BUCKET',
                group: self::GROUP_OFFSITE,
            ),
            new SettingDefinition(
                key: 'backup.s3.endpoint',
                configPath: 'filesystems.disks.offsite_s3.endpoint',
                envVar: 'BACKUP_S3_ENDPOINT',
                group: self::GROUP_OFFSITE,
            ),

            // ── Aufbewahrung ────────────────────────────────────────────────
            new SettingDefinition(
                key: 'retention.activity_log.enabled',
                configPath: 'aeronance.retention.activity_log.enabled',
                envVar: null,
                group: self::GROUP_RETENTION,
                type: 'bool',
                default: false,
            ),
            new SettingDefinition(
                key: 'retention.activity_log.days',
                configPath: 'aeronance.retention.activity_log.days',
                envVar: null,
                group: self::GROUP_RETENTION,
                type: 'int',
                default: 365 * 3,
            ),
            new SettingDefinition(
                key: 'retention.break_glass_log.enabled',
                configPath: 'aeronance.retention.break_glass_log.enabled',
                envVar: null,
                group: self::GROUP_RETENTION,
                type: 'bool',
                default: false,
            ),
            new SettingDefinition(
                key: 'retention.break_glass_log.days',
                configPath: 'aeronance.retention.break_glass_log.days',
                envVar: null,
                group: self::GROUP_RETENTION,
                type: 'int',
                default: 365 * 5,
            ),
            new SettingDefinition(
                key: 'retention.pseudonymise_former_members.enabled',
                configPath: 'aeronance.retention.pseudonymise_former_members.enabled',
                envVar: null,
                group: self::GROUP_RETENTION,
                type: 'bool',
                default: false,
            ),
            new SettingDefinition(
                key: 'retention.pseudonymise_former_members.days',
                configPath: 'aeronance.retention.pseudonymise_former_members.days',
                envVar: null,
                group: self::GROUP_RETENTION,
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
                type: 'bool',
                default: false,
            ),
            new SettingDefinition(
                key: 'vereinsflieger.workhours.category',
                configPath: 'aeronance.vereinsflieger.workhours.category',
                envVar: 'VF_WORKHOURS_CATEGORY',
                group: self::GROUP_VEREINSFLIEGER,
            ),
            new SettingDefinition(
                key: 'vereinsflieger.workhours.status',
                configPath: 'aeronance.vereinsflieger.workhours.status',
                envVar: 'VF_WORKHOURS_STATUS',
                group: self::GROUP_VEREINSFLIEGER,
                type: 'select',
                default: '1',
            ),
            new SettingDefinition(
                key: 'vereinsflieger.writeback.maintenance',
                configPath: 'aeronance.vereinsflieger.writeback.maintenance',
                envVar: 'VF_WRITEBACK_MAINTENANCE',
                group: self::GROUP_VEREINSFLIEGER,
                type: 'bool',
                default: false,
            ),

            // ── Betrieb ─────────────────────────────────────────────────────
            new SettingDefinition(
                key: 'documents.max_size_mb',
                configPath: 'aeronance.documents.max_size_mb',
                envVar: 'AERONANCE_DOCUMENT_MAX_MB',
                group: self::GROUP_OPERATION,
                type: 'int',
                default: 20,
            ),
            new SettingDefinition(
                key: 'due_window_days',
                configPath: 'aeronance.due_window_days',
                envVar: 'AERONANCE_DUE_WINDOW_DAYS',
                group: self::GROUP_OPERATION,
                type: 'int',
                default: 30,
            ),
            new SettingDefinition(
                key: 'virus_scanner',
                configPath: 'aeronance.virus_scanner',
                envVar: 'AERONANCE_VIRUS_SCANNER',
                group: self::GROUP_OPERATION,
                type: 'select',
                default: 'none',
            ),
            new SettingDefinition(
                key: 'clamav.host',
                configPath: 'aeronance.clamav.host',
                envVar: 'AERONANCE_CLAMAV_HOST',
                group: self::GROUP_OPERATION,
            ),
            new SettingDefinition(
                key: 'clamav.port',
                configPath: 'aeronance.clamav.port',
                envVar: 'AERONANCE_CLAMAV_PORT',
                group: self::GROUP_OPERATION,
                type: 'int',
                default: 3310,
            ),
            new SettingDefinition(
                key: 'clamav.fail_closed',
                configPath: 'aeronance.clamav.fail_closed',
                envVar: 'AERONANCE_CLAMAV_FAIL_CLOSED',
                group: self::GROUP_OPERATION,
                type: 'bool',
                default: true,
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
