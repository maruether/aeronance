<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        /*
         * Nachweise: Form 1, Konformitätsbescheinigungen, später CRS.
         *
         * Bewusst eine eigene Disk und ausserhalb des Webroots. Ausgeliefert
         * wird ausschliesslich ueber eine auth-geprueste Route -- eine Datei,
         * die sich durch Raten der URL abrufen laesst, ist kein geschuetztes
         * Dokument. Der Dateiname wird erzeugt, der Originalname nur als
         * Metadatum gefuehrt.
         */
        /*
         * ─────────────────────────────────────────────────────────────────────
         * ZIELE FUER DIE AUSLAGERUNG DER SICHERUNGEN.
         *
         * Ausgewaehlt wird ueber BACKUP_OFFSITE_DISK. Keines davon ist
         * vorgegeben -- ein Verein richtet ein, was er hat.
         *
         * offsite_local  Ein gemountetes Verzeichnis: NFS, CIFS, eine per SSHFS
         *                eingehaengte Storage Box. Braucht KEIN zusaetzliches
         *                Paket und ist damit der Weg, der ueberall sofort
         *                funktioniert.
         *
         * offsite_sftp   SFTP -- der Weg fuer eine Hetzner Storage Box. Ist
         *                eingebaut (league/flysystem-sftp-v3, 3,3 MB).
         *
         * offsite_s3     S3-kompatibel (Hetzner Object Storage, Backblaze B2,
         *                Wasabi, MinIO). Ebenfalls eingebaut -- ueber async-aws
         *                statt ueber das AWS-SDK: 2,3 statt 63 MB.
         *
         * Beide sind damit ohne Nacharbeit nutzbar, auch im Webserver-Kanal,
         * wo das Tarball vendor/ fertig mitbringt und auf dem Zielsystem kein
         * Composer laeuft.
         * ─────────────────────────────────────────────────────────────────────
         */
        'offsite_local' => [
            'driver' => 'local',
            'root' => env('BACKUP_OFFSITE_PATH', '/mnt/backup'),
            'throw' => true,
            'visibility' => 'private',
        ],

        'offsite_s3' => [
            // async-s3, nicht s3: derselbe Dienst fuer 2,3 statt 63 MB.
            // Siehe die Anmeldung in ModuleServiceProvider.
            'driver' => 'async-s3',
            'key' => env('BACKUP_S3_KEY'),
            'secret' => env('BACKUP_S3_SECRET'),
            'region' => env('BACKUP_S3_REGION', 'eu-central-1'),
            'bucket' => env('BACKUP_S3_BUCKET'),
            'endpoint' => env('BACKUP_S3_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('BACKUP_S3_PATH_STYLE', false),
            'throw' => true,
            'visibility' => 'private',
        ],

        'offsite_sftp' => [
            'driver' => 'sftp',
            'host' => env('BACKUP_SFTP_HOST'),
            'username' => env('BACKUP_SFTP_USERNAME'),
            'password' => env('BACKUP_SFTP_PASSWORD'),
            'privateKey' => env('BACKUP_SFTP_PRIVATE_KEY'),
            'port' => (int) env('BACKUP_SFTP_PORT', 22),
            'root' => env('BACKUP_SFTP_ROOT', ''),
            'throw' => true,

            /*
             * ─────────────────────────────────────────────────────────────────
             * 0600 UND 0700, nicht die Vorgabe des Servers.
             *
             * Gemessen in einem echten SFTP-Lauf: ohne diese Angabe legt der
             * Adapter die Dateien mit 0644 ab -- auf einem NAS oder einem
             * geteilten Speicher waere die Sicherung damit fuer jeden anderen
             * Benutzer lesbar. Verschluesselt ist sie zwar, aber eine Datei, die
             * niemand kopieren koennen sollte, gehoert auch nicht offen
             * hingelegt: Der Angreifer, der sie heute mitnimmt, wartet auf den
             * Tag, an dem das Passwort auftaucht.
             * ─────────────────────────────────────────────────────────────────
             */
            'visibility' => 'private',
            'directory_visibility' => 'private',
        ],

        'documents' => [
            'driver' => 'local',
            'root' => storage_path('app/documents'),
            'serve' => false,
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
