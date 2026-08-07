<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Core\Backup\ArchiveCipher;
use App\Core\Backup\ClientOptions;
use App\Core\Backup\OffsiteCopy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Throwable;
use ZipArchive;

/**
 * Database and documents, in one file each.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CLAUDE.md asks for automated backups with a tested restore. This is the half
 * that makes one: the update script runs it before touching anything, and a
 * club can run it whenever.
 *
 * WHY AN ARTISAN COMMAND RATHER THAN A FEW LINES OF SHELL, which is what it
 * replaced:
 *
 *  - The .env is not an INI file. parse_ini_file() chokes on perfectly ordinary
 *    values -- an unquoted parenthesis is enough -- and returns an EMPTY
 *    database name rather than an error. A dump taken with an empty name is not
 *    a dump.
 *  - Credentials never reach a command line. This project has already leaked a
 *    password once by letting a shell see it (see SessionFetcher); arguments are
 *    visible in the process list to every user on the machine. The password goes
 *    into a 0600 options file, and mariadb-dump is started through proc_open
 *    with an argument ARRAY -- no shell parses anything.
 *  - Laravel resolves the connection the same way the application does, so the
 *    backup is of the database the application actually uses.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class BackupCommand extends Command
{
    protected $signature = 'aeronance:backup
        {--path= : Where to write; default storage/app/backups}
        {--database-only : Skip the documents}
        {--keep=10 : How many previous backups to keep, 0 for all}';

    protected $description = 'Sichert Datenbank und Dokumente.';

    public function handle(): int
    {
        $directory = (string) ($this->option('path') ?: storage_path('app/backups'));

        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            $this->components->error(sprintf('%s liess sich nicht anlegen.', $directory));

            return self::FAILURE;
        }

        // Passed in rather than taken from the clock inside each step, so the
        // database dump and the documents beside it carry the SAME stamp and can
        // be restored as a pair. A backup whose halves are minutes apart is a
        // backup somebody has to think about at the worst possible moment.
        $stamp = now()->format('Ymd-His');

        try {
            $dump = $this->dumpDatabase($directory, $stamp);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $dump = $this->encrypt($dump);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Datenbank', $this->describe($dump));

        if (! $this->option('database-only')) {
            try {
                $documents = $this->archiveDocuments($directory, $stamp);
            } catch (Throwable $e) {
                // Said out loud and treated as a failure: the dump alone is not
                // a backup of a system whose Form 1 documents are the records.
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            try {
                $documents = $this->encrypt($documents);
            } catch (Throwable $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            $this->components->twoColumnDetail('Dokumente', $this->describe($documents));
        }

        $ausgelagert = $this->offsite(array_filter([$dump, $documents ?? null]));

        if ($ausgelagert === false) {
            return self::FAILURE;
        }

        $this->prune($directory);

        $this->newLine();
        $this->components->info(sprintf('Sicherung %s in %s.', $stamp, $directory));

        return self::SUCCESS;
    }

    /**
     * Bringt die fertigen Dateien an den zweiten Ort.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * EIN FEHLSCHLAG HIER IST EIN FEHLSCHLAG DER SICHERUNG. Vorgabe: "ein backup
     * ohne offsite storage ist nur halb soviel wert." Wer ein Ziel eingerichtet
     * hat, verlaesst sich darauf -- eine Meldung im Protokoll, die niemand liest,
     * waere das Gegenteil.
     *
     * Die lokalen Dateien BLEIBEN trotzdem liegen. Sie sind gueltig; sie sind
     * nur nicht in Sicherheit. Sie wegzuwerfen, weil das Hochladen scheiterte,
     * machte aus einem halben Problem ein ganzes.
     *
     * @param  list<string>  $dateien
     */
    private function offsite(array $dateien): bool
    {
        $ziel = new OffsiteCopy;

        /*
         * ─────────────────────────────────────────────────────────────────────
         * KEIN EXPORT OHNE VERSCHLUESSELUNG. die Regel, und sie greift genau
         * hier: Solange eine Sicherung auf dem eigenen Server liegt, teilt sie
         * dessen Schutz. In dem Moment, in dem sie ihn VERLAESST, tut sie das
         * nicht mehr -- sie liegt beim Anbieter, auf dessen Platten, in dessen
         * Sicherungen, und niemand von uns weiss, wer dort hineinsieht.
         *
         * Ein HARTER FEHLER und keine Warnung. Eine Warnung im Protokoll eines
         * naechtlichen Laufs liest niemand, und der Klartext waere trotzdem
         * unterwegs. Lieber keine Auslagerung als eine unverschluesselte.
         *
         * Lokal ohne Verschluesselung bleibt erlaubt: Eine frische Installation
         * muss sichern koennen, bevor jemand Schluessel verwaltet -- und
         * update.sh aktualisiert nicht ohne erfolgreiche Sicherung. Der Lauf
         * sagt dann aber, dass sie unverschluesselt ist.
         * ─────────────────────────────────────────────────────────────────────
         */
        $verschluesselung = (string) config('aeronance.backup.encryption.mode', 'none');

        if ($ziel->isConfigured() && ($verschluesselung === 'none' || $verschluesselung === '')) {
            $this->components->error(
                'Auslagerung eingerichtet, aber BACKUP_ENCRYPTION steht auf "none". '
                .'Unverschluesselt verlaesst hier nichts das System: die Sicherung ginge '
                .'im Klartext zu einem Anbieter, dessen Zugriffe niemand hier kennt. '
                .'Bitte BACKUP_ENCRYPTION auf "recipient" (empfohlen) oder "passphrase" '
                .'setzen -- oder BACKUP_OFFSITE_DISK leeren.'
            );

            return false;
        }

        if (! $ziel->isConfigured()) {
            $this->components->twoColumnDetail(
                'Auslagerung',
                '<fg=yellow>keine eingerichtet -- die Sicherung liegt nur hier</>',
            );

            if ($verschluesselung === 'none' || $verschluesselung === '') {
                $this->components->twoColumnDetail(
                    'Verschluesselung',
                    '<fg=yellow>aus -- die Sicherung liegt im Klartext</>',
                );
            }

            return true;
        }

        foreach ($dateien as $datei) {
            try {
                $pfad = $ziel->put($datei);
            } catch (Throwable $e) {
                $this->components->error($e->getMessage());
                $this->components->warn(sprintf(
                    'Die Sicherung liegt lokal in %s und ist gueltig -- sie ist nur nicht '
                    .'ausgelagert.',
                    dirname($datei),
                ));

                return false;
            }

            $this->components->twoColumnDetail('Ausgelagert', $ziel->diskName().':'.$pfad);
        }

        try {
            $entfernt = $ziel->prune((int) config('aeronance.backup.offsite.keep', 30));

            if ($entfernt !== []) {
                $this->components->twoColumnDetail(
                    'Am Ziel entfernt',
                    count($entfernt).' aeltere Datei(en)',
                );
            }
        } catch (Throwable $e) {
            // Aufraeumen ist kein Fehlschlag: die Sicherung IST angekommen. Nur
            // sagen muss man es, sonst laeuft der Speicher unbemerkt voll.
            $this->components->warn('Am Ziel liess sich nicht aufraeumen: '.$e->getMessage());
        }

        return true;
    }

    /**
     * Verschluesselt eine fertige Datei, wenn die Instanz das so eingestellt hat.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * DAS ORIGINAL WIRD GELOESCHT, und zwar erst, NACHDEM die verschluesselte
     * Fassung steht. Andersherum -- oder mit einem Fehler dazwischen -- bliebe
     * der Klartext neben dem Geheimtext liegen, und die Verschluesselung waere
     * eine Verzierung: Wer das Verzeichnis kopiert, nimmt beides mit.
     *
     * Ein Fehler hier ist ein FEHLSCHLAG der ganzen Sicherung. Eine Instanz, die
     * Verschluesselung eingeschaltet hat und stillschweigend Klartext ablegt,
     * haette das Gegenteil dessen, was sie bestellt hat.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @throws RuntimeException
     */
    private function encrypt(string $datei): string
    {
        $modus = (string) config('aeronance.backup.encryption.mode', 'none');

        if ($modus === 'none' || $modus === '') {
            return $datei;
        }

        $ziel = $datei.'.enc';
        $cipher = new ArchiveCipher;

        match ($modus) {
            ArchiveCipher::MODE_RECIPIENT => $cipher->encryptForRecipient($datei, $ziel, $this->publicKey()),
            ArchiveCipher::MODE_PASSPHRASE => $cipher->encryptWithPassphrase($datei, $ziel, $this->passphrase()),
            default => throw new RuntimeException(sprintf(
                'BACKUP_ENCRYPTION kennt "%s" nicht. Moeglich sind: none, recipient, passphrase.',
                $modus,
            )),
        };

        if (! is_file($ziel) || filesize($ziel) === 0) {
            throw new RuntimeException('Die verschluesselte Fassung ist leer -- die Sicherung wird verworfen.');
        }

        @unlink($datei);

        return $ziel;
    }

    /** @throws RuntimeException */
    private function publicKey(): string
    {
        $wert = (string) config('aeronance.backup.encryption.public_key', '');

        if ($wert === '') {
            throw new RuntimeException(
                'BACKUP_ENCRYPTION=recipient, aber BACKUP_PUBLIC_KEY ist leer. Ohne '
                .'oeffentlichen Schluessel gibt es niemanden, fuer den verschluesselt '
                .'werden koennte.'
            );
        }

        // Entweder der PEM-Block selbst oder ein Pfad darauf.
        if (str_contains($wert, 'BEGIN')) {
            return $wert;
        }

        if (! is_readable($wert)) {
            throw new RuntimeException(sprintf('Der oeffentliche Schluessel %s ist nicht lesbar.', $wert));
        }

        return (string) file_get_contents($wert);
    }

    /** @throws RuntimeException */
    private function passphrase(): string
    {
        $wert = (string) config('aeronance.backup.encryption.passphrase', '');

        if ($wert === '') {
            throw new RuntimeException(
                'BACKUP_ENCRYPTION=passphrase, aber BACKUP_PASSPHRASE ist leer.'
            );
        }

        return $wert;
    }

    /**
     * The database, through mariadb-dump.
     *
     * @throws RuntimeException
     */
    private function dumpDatabase(string $directory, string $stamp): string
    {
        $connection = DB::connection()->getConfig();
        $database = (string) ($connection['database'] ?? '');

        if ($database === '') {
            throw new RuntimeException('Die Verbindung nennt keine Datenbank.');
        }

        $binary = $this->locate(['mariadb-dump', 'mysqldump']);

        if ($binary === null) {
            throw new RuntimeException(
                'Weder mariadb-dump noch mysqldump gefunden. Debian/Ubuntu: '
                .'apt install mariadb-client'
            );
        }

        // Password in a 0600 file, TLS the way the application connects -- see
        // ClientOptions. Shared with the restore, because the two drifting apart
        // is exactly what broke restoring while backing up kept working.
        $options = ClientOptions::writeFor($connection);

        $target = rtrim($directory, '/').'/db-'.$stamp.'.sql.gz';

        try {
            $process = proc_open(
                [
                    $binary,
                    '--defaults-extra-file='.$options,
                    '--single-transaction',
                    '--quick',
                    '--default-character-set=utf8mb4',

                    /*
                     * Deliberately NOT --routines --events. Aeronance's schema
                     * comes from migrations and defines no stored procedures and
                     * no scheduled events, so those flags copy nothing -- but
                     * they make the dump read mysql.proc, which fails outright on
                     * a server that has been upgraded without mariadb-upgrade.
                     * A backup that refuses on an unrelated server-maintenance
                     * detail is a backup that does not happen.
                     *
                     * Triggers are not affected: mariadb-dump includes those by
                     * default, so a migration that adds one stays covered.
                     */
                    $database,
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );

            if (! is_resource($process)) {
                throw new RuntimeException('mariadb-dump liess sich nicht starten.');
            }

            // Streamed through gzip rather than buffered: a club's database is
            // small, but "small" is not a promise worth holding in memory.
            $out = gzopen($target, 'wb9');

            if ($out === false) {
                throw new RuntimeException(sprintf('%s liess sich nicht schreiben.', $target));
            }

            while (! feof($pipes[1])) {
                $chunk = fread($pipes[1], 1 << 20);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                gzwrite($out, $chunk);
            }

            gzclose($out);

            $error = (string) stream_get_contents($pipes[2]);

            foreach ($pipes as $pipe) {
                fclose($pipe);
            }

            $status = proc_close($process);

            if ($status !== 0) {
                @unlink($target);

                throw new RuntimeException(sprintf(
                    'mariadb-dump brach mit Code %d ab: %s',
                    $status,
                    trim($error) !== '' ? trim($error) : 'keine Meldung',
                ));
            }
        } finally {
            @unlink($options);
        }

        // A dump that produced almost nothing is a failed dump wearing a
        // filename. Every Aeronance database has a schema, so a few hundred
        // bytes cannot be right.
        if (! is_file($target) || filesize($target) < 512) {
            @unlink($target);

            throw new RuntimeException('Der Dump ist verdächtig klein -- er wurde verworfen.');
        }

        return $target;
    }

    /**
     * The documents: Form 1, CRS, weighing reports, photos.
     *
     * @throws RuntimeException
     */
    private function archiveDocuments(string $directory, string $stamp): string
    {
        $source = storage_path('app');
        $target = rtrim($directory, '/').'/documents-'.$stamp.'.zip';

        $zip = new ZipArchive;

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException(sprintf('%s liess sich nicht anlegen.', $target));
        }

        $finder = (new Finder)
            ->files()
            ->in($source)
            // Not the backups themselves -- otherwise every backup carries all
            // its predecessors and the directory doubles each time.
            ->exclude(['backups'])
            ->ignoreDotFiles(false);

        $count = 0;

        foreach ($finder as $file) {
            $zip->addFile($file->getRealPath(), $file->getRelativePathname());
            $count++;
        }

        $zip->close();

        if ($count === 0) {
            // Not an error: a fresh installation has no documents yet. Said
            // plainly so nobody reads a 22-byte archive as a loss.
            $this->components->warn('Keine Dokumente vorhanden -- das Archiv ist leer.');
        }

        return $target;
    }

    /**
     * Older backups, beyond what was asked for.
     *
     * Deliberately conservative: it only ever removes files this command wrote,
     * matched by name, and never touches anything else in the directory.
     */
    private function prune(string $directory): void
    {
        $keep = (int) $this->option('keep');

        if ($keep <= 0) {
            return;
        }

        foreach (['db-*.sql.gz', 'documents-*.zip'] as $pattern) {
            $files = glob(rtrim($directory, '/').'/'.$pattern) ?: [];

            if (count($files) <= $keep) {
                continue;
            }

            // Newest first, then drop the tail.
            usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

            foreach (array_slice($files, $keep) as $old) {
                @unlink($old);
                $this->components->twoColumnDetail('entfernt', basename($old));
            }
        }
    }

    private function describe(string $path): string
    {
        $bytes = is_file($path) ? (int) filesize($path) : 0;

        return sprintf('%s (%s)', basename($path), $this->humanBytes($bytes));
    }

    private function humanBytes(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return sprintf('%d %s', $bytes, $unit);
            }

            $bytes = intdiv($bytes, 1024);
        }

        return sprintf('%d TB', $bytes);
    }

    /** @param list<string> $candidates */
    private function locate(array $candidates): ?string
    {
        foreach ($candidates as $name) {
            foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
                $path = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;

                if (is_file($path) && is_executable($path)) {
                    return $path;
                }
            }
        }

        return null;
    }
}
