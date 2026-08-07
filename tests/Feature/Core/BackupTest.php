<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Backup\ArchiveCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The backup, which is worth nothing until it has been read back.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * An untested backup is the classic one: it runs nightly for two years, writes a
 * file every time, and the first person to open one is somebody who has already
 * lost the database.
 *
 * So these assert on the CONTENT, not on "a file appeared". The bug that
 * prompted them wrote a perfectly plausible file: the first version read the
 * .env with parse_ini_file(), which chokes on an unquoted parenthesis and
 * returns an EMPTY database name rather than an error -- the dump would have
 * been of nothing at all, at the usual size for nothing at all.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/backups-'.uniqid());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    #[Test]
    public function the_dump_contains_the_schema_and_the_data(): void
    {
        $this->backup(['--path' => $this->directory]);

        $dump = $this->newest('db-*.sql.gz');

        $this->assertNotNull($dump, 'Es wurde kein Dump geschrieben.');

        $sql = (string) gzdecode((string) file_get_contents($dump));

        // The tables an Aeronance database always has -- one from the core and
        // one from a module, so a dump restricted to the wrong schema fails here
        // rather than in a club's worst week.
        $this->assertStringContainsString('CREATE TABLE `users`', $sql);
        $this->assertStringContainsString('CREATE TABLE `modules`', $sql);

        /*
         * And the ROWS, not just the shape -- a --no-data dump would satisfy
         * every assertion above and restore an empty system.
         *
         * The migrations table and not a domain one, for a reason worth knowing
         * before writing the next test here: RefreshDatabase wraps each test in
         * a transaction, and mariadb-dump connects separately. Anything a test
         * inserts is therefore INVISIBLE to the dump. The migration rows are
         * written by migrate:fresh before the transaction opens, so they are the
         * data that genuinely exists on disk at this moment.
         */
        $this->assertStringContainsString('INSERT INTO `migrations`', $sql);
        $this->assertStringContainsString('create_users_table', $sql);
    }

    #[Test]
    public function the_documents_travel_with_it(): void
    {
        // A Form 1 is the record. A backup of the database alone restores rows
        // pointing at files nobody has any more.
        File::ensureDirectoryExists(storage_path('app/private'));
        File::put(storage_path('app/private/form-1-probe.txt'), 'Herkunftsnachweis');

        try {
            $this->backup(['--path' => $this->directory]);

            $archive = $this->newest('documents-*.zip');
            $this->assertNotNull($archive);

            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($archive) === true);

            $names = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = (string) $zip->getNameIndex($i);
            }

            $zip->close();

            $this->assertContains('private/form-1-probe.txt', $names);
        } finally {
            File::delete(storage_path('app/private/form-1-probe.txt'));
        }
    }

    #[Test]
    public function a_backup_never_contains_its_predecessors(): void
    {
        // Without the exclusion each backup carries every earlier one, and the
        // directory doubles in size every night until the disk fills.
        $this->backup(['--path' => storage_path('app/backups')]);

        try {
            $this->backup(['--path' => $this->directory]);

            $zip = new \ZipArchive;
            $zip->open((string) $this->newest('documents-*.zip'));

            $names = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = (string) $zip->getNameIndex($i);
            }

            $zip->close();

            $this->assertSame([], array_filter(
                $names,
                static fn (string $n): bool => str_starts_with($n, 'backups/'),
            ));
        } finally {
            File::deleteDirectory(storage_path('app/backups'));
        }
    }

    #[Test]
    public function old_backups_are_pruned_but_only_the_ones_it_wrote(): void
    {
        File::ensureDirectoryExists($this->directory);

        // Something else living in the same directory. A cleanup that removes
        // whatever it does not recognise is how a club loses the one file it
        // copied there by hand.
        File::put($this->directory.'/bitte-nicht-loeschen.txt', 'x');

        foreach (range(1, 3) as $i) {
            File::put($this->directory.sprintf('/db-2020010%d-000000.sql.gz', $i), 'alt');
            touch($this->directory.sprintf('/db-2020010%d-000000.sql.gz', $i), 1577836800 + $i);
        }

        $this->backup([
            '--path' => $this->directory,
            '--keep' => 2,
            '--database-only' => true,
        ]);

        $this->assertCount(2, glob($this->directory.'/db-*.sql.gz') ?: []);
        $this->assertFileExists($this->directory.'/bitte-nicht-loeschen.txt');
    }

    /**
     * Runs the backup and, if it fails, says WHY.
     *
     * assertSuccessful() reports "expected 0, received 1" and throws the
     * command's own message away -- which is the only part that helps. This cost
     * a full CI round-trip to learn: four red tests, and the reason nowhere in
     * the log.
     *
     * @param  array<string, mixed>  $options
     */
    private function backup(array $options): void
    {
        $status = Artisan::call('aeronance:backup', $options);

        $this->assertSame(0, $status, 'aeronance:backup schlug fehl: '.Artisan::output());
    }

    private function newest(string $pattern): ?string
    {
        $files = glob($this->directory.'/'.$pattern) ?: [];

        if ($files === []) {
            return null;
        }

        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files[0];
    }

    #[Test]
    public function an_encrypted_backup_leaves_no_plaintext_behind(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * DIE ZUSICHERUNG, DIE DEN GANZEN ZWECK TRAEGT. Bliebe der Klartext neben
         * der verschluesselten Fassung liegen, waere die Verschluesselung eine
         * Verzierung: Wer das Verzeichnis kopiert -- und genau das tut die
         * Auslagerung in einen Backup-Space -- nimmt beides mit.
         * ─────────────────────────────────────────────────────────────────────
         */
        config()->set('aeronance.backup.encryption.mode', 'passphrase');
        config()->set('aeronance.backup.encryption.passphrase', 'ein sehr langes Passwort');

        $verzeichnis = storage_path('app/backups-test-'.bin2hex(random_bytes(4)));

        $this->artisan('aeronance:backup', ['--path' => $verzeichnis, '--database-only' => true])
            ->assertSuccessful();

        $dateien = array_values(array_filter(
            scandir($verzeichnis) ?: [],
            static fn (string $f): bool => ! in_array($f, ['.', '..'], true),
        ));

        $this->assertNotEmpty($dateien);

        foreach ($dateien as $datei) {
            $this->assertStringEndsWith('.enc', $datei, 'Neben der Sicherung liegt Klartext.');
            $this->assertTrue(
                (new ArchiveCipher)->isEncrypted($verzeichnis.'/'.$datei),
                $datei.' ist nicht verschluesselt.',
            );
        }

        array_map('unlink', glob($verzeichnis.'/*') ?: []);
        rmdir($verzeichnis);
    }

    #[Test]
    public function a_misconfigured_encryption_fails_the_backup_rather_than_writing_plaintext(): void
    {
        /*
         * Eine Instanz, die Verschluesselung bestellt hat und stillschweigend
         * Klartext ablegt, haette das Gegenteil dessen bekommen, was sie wollte
         * -- und wuesste es nicht. Deshalb ist die fehlende Angabe ein
         * Fehlschlag und keine Warnung.
         */
        config()->set('aeronance.backup.encryption.mode', 'passphrase');
        config()->set('aeronance.backup.encryption.passphrase', '');

        $verzeichnis = storage_path('app/backups-test-'.bin2hex(random_bytes(4)));

        $this->artisan('aeronance:backup', ['--path' => $verzeichnis, '--database-only' => true])
            ->assertFailed();

        array_map('unlink', glob($verzeichnis.'/*') ?: []);
        @rmdir($verzeichnis);
    }
}
