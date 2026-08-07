<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Core\Documents\PdfLayoutText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What this installation is missing.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Aeronance depends on things outside PHP -- a database of a particular make, a
 * PDF binary, extensions. Each of them fails in its own way, and one of those
 * ways is silent: without poppler-utils, every Kennblatt lookup answers "kein
 * Treffer" and somebody types the number in by hand believing they checked.
 *
 * One command that names all of them, runnable before the wizard and after an
 * update. The three delivery channels install these; a hand-built server is
 * where something gets forgotten, and this is what it is for.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class RequirementsCommand extends Command
{
    /**
     * The floor, and it is set by the dependencies rather than by the code.
     *
     * composer.json declared ^8.3 for a while, while the lock held eighteen
     * Symfony packages needing 8.4.1 and an activitylog needing ^8.4. Nobody
     * noticed until the release stage built on the version being promised.
     * config.platform.php now keeps the two in step; this constant is what an
     * operator gets told.
     */
    private const MINIMUM_PHP = '8.4.0';

    protected $signature = 'aeronance:requirements';

    protected $description = 'Prüft, ob dieses System alles mitbringt, was Aeronance braucht.';

    public function handle(): int
    {
        $missing = 0;

        foreach ($this->checks() as $check) {
            [$ok, $detail] = $check['run']();

            $this->components->twoColumnDetail(
                $check['label'],
                $ok ? '<fg=green>vorhanden</>' : '<fg=red>FEHLT</>',
            );

            if (! $ok) {
                $missing++;
                $this->line('    '.$detail);
            }
        }

        $this->newLine();

        if ($missing > 0) {
            $this->components->error(sprintf('%d Voraussetzung(en) fehlen.', $missing));

            return self::FAILURE;
        }

        $this->components->info('Alle Voraussetzungen erfüllt.');

        return self::SUCCESS;
    }

    /**
     * @return list<array{label: string, run: callable(): array{0: bool, 1: string}}>
     */
    private function checks(): array
    {
        $checks = [
            [
                'label' => 'pdftotext (poppler-utils)',
                'run' => fn (): array => [
                    PdfLayoutText::isAvailable(),
                    'Ohne pdftotext bleiben die Kennblatt-Listen des LBA ungelesen — und '
                    .'zwar lautlos: jede Suche meldet dann „kein Treffer". '
                    .'Debian/Ubuntu: apt install poppler-utils',
                ],
            ],
            [
                'label' => 'MariaDB erreichbar',
                'run' => function (): array {
                    try {
                        DB::connection()->getPdo();

                        return [true, ''];
                    } catch (Throwable $e) {
                        return [false, $e->getMessage()];
                    }
                },
            ],
            [
                // Not MySQL. CLAUDE.md calls this a hard limit, and the two
                // drift further apart with every release -- a schema that
                // migrates cleanly today is not the promise being made.
                'label' => 'Datenbank ist MariaDB',
                'run' => function (): array {
                    try {
                        $version = (string) DB::connection()->getPdo()
                            ->getAttribute(\PDO::ATTR_SERVER_VERSION);
                    } catch (Throwable $e) {
                        return [false, 'Version nicht feststellbar: '.$e->getMessage()];
                    }

                    return [
                        stripos($version, 'mariadb') !== false,
                        sprintf('Gemeldet wird "%s". Aeronance unterstützt ausschliesslich '
                            .'MariaDB; MySQL ist ausdrücklich nicht unterstützt.', $version),
                    ];
                },
            ],
        ];

        $checks[] = [
            'label' => 'PHP '.self::MINIMUM_PHP.' oder neuer',
            'run' => fn (): array => [
                version_compare(PHP_VERSION, self::MINIMUM_PHP, '>='),
                sprintf(
                    'Installiert ist %s. Aeronance selbst käme mit weniger aus, aber '
                    .'seine Abhängigkeiten nicht -- unter %s bricht schon das Laden ab.',
                    PHP_VERSION,
                    self::MINIMUM_PHP,
                ),
            ],
        ];

        foreach (['pdo_mysql', 'intl', 'gd', 'zip', 'bcmath', 'fileinfo'] as $extension) {
            $checks[] = [
                'label' => 'PHP-Erweiterung '.$extension,
                'run' => fn (): array => [
                    extension_loaded($extension),
                    'Installieren und PHP-FPM neu starten.',
                ],
            ];
        }

        return $checks;
    }
}
