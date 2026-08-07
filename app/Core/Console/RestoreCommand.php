<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Core\Backup\ArchiveCipher;
use App\Core\Backup\ClientOptions;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Der Rückweg -- und der Grund, warum eine Sicherung überhaupt eine ist.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CLAUDE.md verlangt "automatisierte Backups (DB + Dokumente) mit GETESTETEM
 * Restore". Der Sicherungsbefehl gab es lange, diesen nicht -- und eine
 * Sicherung, aus der noch nie jemand zurückgekommen ist, ist ein Gefühl und
 * kein Backup. Ein Test spielt Sichern → Leeren → Zurückholen → Prüfen
 * vollständig durch.
 *
 * ENTSCHLÜSSELT VON SELBST. Der Befehl sieht der Datei an, ob und wie sie
 * verschlüsselt ist, und sagt, was fehlt ("die braucht ein Passwort"), statt
 * den Nutzer im Ernstfall raten zu lassen. Das ist der Moment, in dem niemand
 * Lust auf Rätsel hat.
 *
 * WAS ER NICHT TUT: die Dokumente ohne Nachfrage überschreiben. Ein Restore auf
 * ein laufendes System ist fast immer ein Missverständnis, und die Dokumente
 * sind die Nachweise -- Form 1, CRS, Wägeberichte. Er verlangt deshalb --force,
 * wenn schon etwas da ist.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class RestoreCommand extends Command
{
    protected $signature = 'aeronance:restore
        {dump : Die Datenbanksicherung (.sql.gz oder .enc)}
        {--documents= : Das Dokumentenarchiv (.zip oder .enc)}
        {--passphrase= : Passwort, wenn die Sicherung damit verschlüsselt ist}
        {--private-key= : Pfad zum privaten Schlüssel, wenn sie an einen gerichtet ist}
        {--key-passphrase= : Passwort des privaten Schlüssels, falls er eines hat}
        {--force : Vorhandene Dokumente überschreiben}';

    protected $description = 'Stellt Datenbank und Dokumente aus einer Sicherung wieder her.';

    public function handle(): int
    {
        $dump = (string) $this->argument('dump');

        if (! is_readable($dump)) {
            $this->components->error(sprintf('%s ist nicht lesbar.', $dump));

            return self::FAILURE;
        }

        $temporaer = [];

        try {
            $dump = $this->entschluesseln($dump, $temporaer);
            $this->datenbankZurueck($dump);
            $this->components->twoColumnDetail('Datenbank', 'wiederhergestellt');

            $dokumente = (string) ($this->option('documents') ?? '');

            if ($dokumente !== '') {
                $dokumente = $this->entschluesseln($dokumente, $temporaer);
                $anzahl = $this->dokumenteZurueck($dokumente);
                $this->components->twoColumnDetail('Dokumente', $anzahl.' Dateien');
            }
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } finally {
            /*
             * Der entschlüsselte Zwischenstand verschwindet immer -- auch wenn
             * es schiefging. Sonst läge nach einem misslungenen Restore genau
             * das im Verzeichnis, wovor die Verschlüsselung schützen sollte.
             */
            foreach ($temporaer as $datei) {
                @unlink($datei);
            }
        }

        $this->newLine();
        $this->components->info('Wiederhergestellt. Bitte danach die Caches neu aufbauen.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $temporaer
     *
     * @throws RuntimeException
     */
    private function entschluesseln(string $datei, array &$temporaer): string
    {
        $cipher = new ArchiveCipher;

        if (! $cipher->isEncrypted($datei)) {
            return $datei;
        }

        $modus = $cipher->modeOf($datei);
        $ziel = sys_get_temp_dir().'/aeronance-restore-'.bin2hex(random_bytes(6));
        $temporaer[] = $ziel;

        if ($modus === ArchiveCipher::MODE_PASSPHRASE) {
            $passwort = (string) ($this->option('passphrase') ?? '');

            if ($passwort === '') {
                throw new RuntimeException(
                    'Diese Sicherung ist mit einem Passwort verschlüsselt. Bitte mit '
                    .'--passphrase= wiederholen.'
                );
            }

            $cipher->decryptWithPassphrase($datei, $ziel, $passwort);

            return $ziel;
        }

        $schluessel = (string) ($this->option('private-key') ?? '');

        if ($schluessel === '') {
            throw new RuntimeException(
                'Diese Sicherung ist an einen Schlüssel gerichtet. Bitte mit '
                .'--private-key=/pfad/zum/privaten/schluessel.pem wiederholen.'
            );
        }

        if (! is_readable($schluessel)) {
            throw new RuntimeException(sprintf('Der private Schlüssel %s ist nicht lesbar.', $schluessel));
        }

        $cipher->decryptWithKey(
            $datei,
            $ziel,
            (string) file_get_contents($schluessel),
            ($this->option('key-passphrase') ?: null),
        );

        return $ziel;
    }

    /**
     * Spielt den Dump ein.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * DER PROZESS LIEST AUS EINER DATEI, NICHT AUS EINER PIPE -- und das ist
     * keine Stilfrage. Der erste Entwurf schrieb den Dump in die stdin-Pipe und
     * leerte stdout/stderr nicht: sobald mariadb genug ausgibt, um seinen
     * Puffer zu fuellen, blockiert es -- und dieser Prozess blockiert
     * gleichzeitig beim Schreiben. Beide warten aufeinander, der Test lief in
     * den Timeout. Ein Restore, der haengt statt zu scheitern, ist im Ernstfall
     * das Schlimmste von beidem.
     *
     * Mit einem Datei-Deskriptor pumpt PHP gar nichts, und das Problem kann
     * nicht auftreten. Ein gzip-Dump wird dafuer vorher ausgepackt.
     *
     * Zugangsdaten wie beim Sichern: 0600-Optionsdatei, Argument-ARRAY, keine
     * Shell. Ein Passwort auf der Kommandozeile steht in der Prozessliste.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @throws RuntimeException
     */
    private function datenbankZurueck(string $dump): void
    {
        $verbindung = (string) config('database.default');
        $c = (array) config('database.connections.'.$verbindung);

        $binary = $this->locate(['mariadb', 'mysql']);
        $entpackt = null;

        if ($this->istGepackt($dump)) {
            $entpackt = sys_get_temp_dir().'/aeronance-restore-sql-'.bin2hex(random_bytes(6));
            $ein = gzopen($dump, 'rb');
            $aus = fopen($entpackt, 'wb');

            if ($ein === false || $aus === false) {
                throw new RuntimeException('Die Sicherung liess sich nicht auspacken.');
            }

            while (! gzeof($ein)) {
                fwrite($aus, (string) gzread($ein, 1 << 20));
            }

            gzclose($ein);
            fclose($aus);
            $dump = $entpackt;
        }

        /*
         * DIESELBE Optionsdatei wie beim Sichern -- siehe ClientOptions.
         *
         * Hier stand eine zweite, eigene Fassung OHNE die TLS-Zeile. Folge: Die
         * Sicherung lief, das Zurueckspielen nicht. Ein Client ab 11.4 verlangt
         * von sich aus TLS, und ein Server ohne TLS weist ihn ab -- also genau
         * der Fall, vor dem der Kommentar beim Sichern warnt.
         */
        $optionen = ClientOptions::writeFor($c);

        try {
            $prozess = proc_open(
                [$binary, '--defaults-extra-file='.$optionen, (string) ($c['database'] ?? '')],
                [
                    0 => ['file', $dump, 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $rohre,
            );

            if (! is_resource($prozess)) {
                throw new RuntimeException(sprintf('%s liess sich nicht starten.', $binary));
            }

            $fehler = (string) stream_get_contents($rohre[2]);
            fclose($rohre[1]);
            fclose($rohre[2]);

            if (proc_close($prozess) !== 0) {
                throw new RuntimeException('Das Einspielen schlug fehl: '.mb_substr(trim($fehler), 0, 300));
            }
        } finally {
            @unlink($optionen);

            if ($entpackt !== null) {
                @unlink($entpackt);
            }
        }
    }

    /**
     * Ob diese Datei gzip ist -- am INHALT erkannt, nicht am Namen.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Erst stand hier str_ends_with($dump, '.gz'), und das war falsch, sobald
     * verschluesselt wird: Die Sicherung heisst db-….sql.gz.enc, und die
     * entschluesselte Zwischendatei traegt einen Zufallsnamen ohne Endung. Der
     * gzip-Strom ging damit ROH an mariadb, das mit
     *
     *     ASCII '\0' appeared in the statement
     *
     * abbrach. Gefunden hat das der Rundlauftest -- genau wofuer er da ist.
     *
     * Die zwei Magic Bytes luegen nicht, der Dateiname schon.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private function istGepackt(string $datei): bool
    {
        $handle = @fopen($datei, 'rb');

        if ($handle === false) {
            return false;
        }

        $magic = (string) fread($handle, 2);
        fclose($handle);

        return $magic === "\x1f\x8b";
    }

    /**
     * Das erste vorhandene der genannten Programme.
     *
     * @param  list<string>  $kandidaten
     *
     * @throws RuntimeException
     */
    private function locate(array $kandidaten): string
    {
        foreach ($kandidaten as $kandidat) {
            $pfad = trim((string) shell_exec('command -v '.escapeshellarg($kandidat).' 2>/dev/null'));

            if ($pfad !== '') {
                return $pfad;
            }
        }

        throw new RuntimeException(sprintf(
            'Keines dieser Programme ist vorhanden: %s. Ohne Datenbankclient laesst sich '
            .'keine Sicherung einspielen.',
            implode(', ', $kandidaten),
        ));
    }

    /** @throws RuntimeException */
    private function dokumenteZurueck(string $archiv): int
    {
        $ziel = storage_path('app');
        $vorhanden = glob($ziel.'/*') ?: [];

        // backups/ zaehlt nicht: das ist der Ort, aus dem gerade
        // wiederhergestellt wird.
        $vorhanden = array_filter($vorhanden, static fn (string $p): bool => basename($p) !== 'backups');

        if ($vorhanden !== [] && ! $this->option('force')) {
            throw new RuntimeException(
                'In storage/app liegen bereits Dateien. Ein Restore darüber ist fast immer '
                .'ein Missverständnis -- die Dokumente sind die Nachweise. Mit --force '
                .'wiederholen, wenn es Absicht ist.'
            );
        }

        $zip = new \ZipArchive;

        if ($zip->open($archiv) !== true) {
            throw new RuntimeException(sprintf('%s liess sich nicht öffnen.', $archiv));
        }

        $anzahl = $zip->numFiles;

        if (! $zip->extractTo($ziel)) {
            $zip->close();

            throw new RuntimeException('Das Archiv liess sich nicht auspacken.');
        }

        $zip->close();

        return $anzahl;
    }
}
