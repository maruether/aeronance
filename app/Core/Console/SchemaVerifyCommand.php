<?php

declare(strict_types=1);

namespace App\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sagt der Schema-Abzug dasselbe wie die Migrationen?
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WARUM ES DIESE PRÜFUNG BRAUCHT.
 *
 * Seit `database/schema/mariadb-schema.sql` existiert, baut `migrate:fresh` das
 * Schema nicht mehr aus 77 Migrationen, sondern lädt eine Datei und fährt nur
 * noch die Migrationen, die darin fehlen. Das ist der Unterschied zwischen 45
 * und 8 Sekunden -- je Testfall, zweimal.
 *
 * Der Preis ist eine zweite Quelle für dieselbe Wahrheit. Solange nur neue
 * Migrationen dazukommen, ist ein veralteter Abzug harmlos: Sie laufen oben
 * drauf, das Ergebnis stimmt, es dauert nur wieder länger. GEFÄHRLICH wird der
 * Abzug, wenn er etwas anderes sagt als die Migrationen -- weil er aus einer
 * Datenbank mit Drift gezogen wurde, oder weil jemand eine alte Migration
 * geändert hat. Dann bauen Tests, frische Installationen und der Entwickler mit
 * `migrate` von Hand still verschiedene Schemata, und niemand merkt es, weil
 * alles grün ist.
 *
 * Also wird es geprüft, und zwar so, wie man es prüfen muss: Beide Wege einmal
 * gehen und das Ergebnis vergleichen.
 *
 *   Weg A -- die Standardverbindung, gebaut aus dem Abzug plus Restmigrationen.
 *   Weg B -- eine Wegwerf-Datenbank, gebaut aus ALLEN Migrationen.
 *
 * Der Trick für Weg B ist der VERBINDUNGSNAME: Laravel sucht den Abzug unter
 * `database/schema/<verbindung>-schema.sql`. Eine Verbindung namens
 * `schema_check` hat keinen -- also läuft dort zwangsläufig der lange Weg, ohne
 * dass irgendetwas verschoben oder umbenannt werden muss.
 *
 * Verglichen wird die DDL jeder Tabelle und die Liste der angewandten
 * Migrationen. Nicht die Dateien: Ein Abzug von `mariadb-dump 12.3` und einer
 * von `10.11` unterscheiden sich in Kommentarzeilen, ohne dass sich am Schema
 * etwas ändert -- ein Byte-Vergleich meldete Unterschiede, die keine sind.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SchemaVerifyCommand extends Command
{
    protected $signature = 'aeronance:schema-verify
        {--database=aeronance_schema_check : Vergleichsdatenbank -- wird dabei GELEERT}';

    protected $description = 'Prüft, ob der Schema-Abzug dasselbe Schema ergibt wie alle Migrationen.';

    public function handle(): int
    {
        $abzug = database_path('schema/'.config('database.default').'-schema.sql');

        if (! is_file($abzug)) {
            $this->components->error('Es gibt keinen Schema-Abzug ('.$abzug.').');
            $this->line('  Anlegen mit: php artisan schema:dump');

            return self::FAILURE;
        }

        $pruefDb = (string) $this->option('database');

        try {
            $this->useCheckConnection($pruefDb);
        } catch (Throwable $e) {
            $this->components->error('Die Vergleichsdatenbank "'.$pruefDb.'" ist nicht erreichbar.');
            $this->line('  '.$e->getMessage());
            $this->newLine();
            $this->line('  Sie muss existieren und dem Testbenutzer gehören -- angelegt wird sie');
            $this->line('  bewusst nicht hier: Datenbanken anzulegen ist Sache des Betreibers.');
            $this->line('  Einmalig etwa: CREATE DATABASE `'.$pruefDb.'` '
                .'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;');

            return self::FAILURE;
        }

        $this->components->task('Alle Migrationen in '.$pruefDb, function (): bool {
            Artisan::call('migrate:fresh', ['--database' => 'schema_check', '--force' => true]);

            return true;
        });

        $ausAbzug = $this->schemaOf(config('database.default'));
        $ausMigrationen = $this->schemaOf('schema_check');

        $unterschiede = $this->compare($ausAbzug, $ausMigrationen);

        if ($unterschiede === []) {
            $this->components->info('Der Abzug stimmt mit den Migrationen überein ('
                .count($ausAbzug['tables']).' Tabellen, '.count($ausAbzug['migrations']).' Migrationen).');

            return self::SUCCESS;
        }

        $this->components->error('Der Schema-Abzug ist nicht mehr das, was die Migrationen bauen:');

        foreach ($unterschiede as $zeile) {
            $this->line('  '.$zeile);
        }

        $this->newLine();
        $this->line('  Auffrischen mit: php artisan schema:dump');
        $this->line('  (auf einer Datenbank, die frisch migriert und sonst leer ist)');

        return self::FAILURE;
    }

    /**
     * Die Vergleichsdatenbank als Verbindung `schema_check` bekanntmachen --
     * unter genau diesem Namen, damit Laravel dort keinen Abzug findet und
     * wirklich alle Migrationen fährt.
     *
     * Angelegt wird sie NICHT hier. Der Testbenutzer hat in einer vernünftig
     * eingerichteten Datenbank kein CREATE-DATABASE-Recht, und ihm dafür eines
     * zu geben wäre der falsche Weg herum: Ein Prüfwerkzeug soll weniger
     * dürfen als der Betrieb, nicht mehr.
     */
    private function useCheckConnection(string $name): void
    {
        config([
            'database.connections.schema_check' => array_merge(
                config('database.connections.'.config('database.default')),
                ['database' => $name],
            ),
        ]);

        DB::purge('schema_check');

        // Fällt hier hin, wenn es die Datenbank nicht gibt -- mit einer
        // Meldung, die der Aufrufer weiterreicht.
        DB::connection('schema_check')->getPdo();
    }

    /**
     * Was in dieser Datenbank steht: die DDL je Tabelle und die Liste der
     * angewandten Migrationen.
     *
     * @return array{tables: array<string, string>, migrations: list<string>}
     */
    private function schemaOf(string $verbindung): array
    {
        $db = DB::connection($verbindung);
        $tabellen = [];

        foreach ($db->getSchemaBuilder()->getTableListing() as $tabelle) {
            $kurz = str_contains($tabelle, '.') ? substr($tabelle, strrpos($tabelle, '.') + 1) : $tabelle;

            $ddl = (array) $db->selectOne('SHOW CREATE TABLE `'.$kurz.'`');
            $tabellen[$kurz] = $this->normalise((string) (array_values($ddl)[1] ?? ''));
        }

        ksort($tabellen);

        $migrationen = $db->table('migrations')->orderBy('migration')->pluck('migration')->all();

        return ['tables' => $tabellen, 'migrations' => $migrationen];
    }

    /**
     * Zählerstände und Datenbanknamen gehören nicht zum Schema.
     *
     * AUTO_INCREMENT verrät nur, wie viele Zeilen einmal da waren; der
     * Abzug wird ohne ihn geschrieben, die frisch migrierte Datenbank hat ihn
     * auch nicht -- aber verlassen sollte man sich darauf nicht.
     */
    private function normalise(string $ddl): string
    {
        $ddl = (string) preg_replace('/\s+AUTO_INCREMENT=\d+/i', '', $ddl);

        return trim($ddl);
    }

    /**
     * @param  array{tables: array<string, string>, migrations: list<string>}  $abzug
     * @param  array{tables: array<string, string>, migrations: list<string>}  $migrationen
     * @return list<string>
     */
    private function compare(array $abzug, array $migrationen): array
    {
        $unterschiede = [];

        foreach (array_diff(array_keys($abzug['tables']), array_keys($migrationen['tables'])) as $zuviel) {
            $unterschiede[] = 'Tabelle nur im Abzug: '.$zuviel;
        }

        foreach (array_diff(array_keys($migrationen['tables']), array_keys($abzug['tables'])) as $fehlt) {
            $unterschiede[] = 'Tabelle fehlt im Abzug: '.$fehlt;
        }

        foreach ($abzug['tables'] as $tabelle => $ddl) {
            if (! isset($migrationen['tables'][$tabelle]) || $migrationen['tables'][$tabelle] === $ddl) {
                continue;
            }

            $unterschiede[] = 'Tabelle '.$tabelle.' unterscheidet sich:';

            foreach ($this->firstDifference($ddl, $migrationen['tables'][$tabelle]) as $zeile) {
                $unterschiede[] = '    '.$zeile;
            }
        }

        foreach (array_diff($migrationen['migrations'], $abzug['migrations']) as $fehlt) {
            $unterschiede[] = 'Migration nicht im Abzug: '.$fehlt;
        }

        foreach (array_diff($abzug['migrations'], $migrationen['migrations']) as $zuviel) {
            $unterschiede[] = 'Migration nur im Abzug (Datei gelöscht?): '.$zuviel;
        }

        return $unterschiede;
    }

    /**
     * Die erste abweichende Zeile beider Fassungen -- mehr braucht niemand,
     * um zu wissen, wo er nachsehen muss.
     *
     * @return list<string>
     */
    private function firstDifference(string $a, string $b): array
    {
        $zeilenA = explode("\n", $a);
        $zeilenB = explode("\n", $b);

        foreach ($zeilenA as $i => $zeile) {
            if (($zeilenB[$i] ?? null) !== $zeile) {
                return [
                    'Abzug:       '.trim($zeile),
                    'Migrationen: '.trim($zeilenB[$i] ?? '(Zeile fehlt)'),
                ];
            }
        }

        return ['Abzug hat '.count($zeilenA).' Zeilen, die Migrationen '.count($zeilenB).'.'];
    }
}
