<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Setup\EnvFileWriter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Der .env-Schreiber des Setup-Assistenten.
 *
 * Ein Webformular, das die .env schreibt, ist genau dann ein Einfallstor, wenn
 * es Werte ungeprueft uebernimmt -- der Zeilenumbruch im Passwortfeld waere
 * eine eigene Konfigurationszeile. Diese Tests halten die Tuer zu.
 */
final class EnvFileWriterTest extends TestCase
{
    #[Test]
    public function it_replaces_an_existing_key_in_place(): void
    {
        $env = "APP_NAME=Aeronance\nDB_HOST=alt\nDB_PORT=3306\n";

        $result = EnvFileWriter::withValues($env, ['DB_HOST' => 'neu.example.org']);

        $this->assertStringContainsString('DB_HOST="neu.example.org"', $result);
        $this->assertStringNotContainsString('DB_HOST=alt', $result);
        $this->assertStringContainsString('APP_NAME=Aeronance', $result, 'Fremde Zeilen bleiben unberuehrt.');
    }

    #[Test]
    public function it_appends_a_missing_key(): void
    {
        $result = EnvFileWriter::withValues("APP_NAME=Aeronance\n", ['DB_PASSWORD' => 'geheim']);

        $this->assertStringContainsString("\nDB_PASSWORD=\"geheim\"\n", $result);
    }

    #[Test]
    public function comments_and_blank_lines_survive(): void
    {
        // Die .env einer Installation traegt handgeschriebene Notizen -- ein
        // Schreiber, der die Datei "neu ordnet", wirft sie weg.
        $env = "# Zugang zur Datenbank\n\nDB_HOST=alt\n# Ende\n";

        $result = EnvFileWriter::withValues($env, ['DB_HOST' => 'neu']);

        $this->assertStringContainsString("# Zugang zur Datenbank\n\n", $result);
        $this->assertStringContainsString('# Ende', $result);
    }

    #[Test]
    public function a_newline_in_the_value_is_refused_not_cleaned(): void
    {
        // Abgelehnt statt bereinigt: Ein stillschweigend veraendertes
        // Passwort waere ein anderes Passwort.
        $this->expectException(InvalidArgumentException::class);

        EnvFileWriter::withValues('', ['DB_PASSWORD' => "geheim\nAPP_DEBUG=true"]);
    }

    #[Test]
    public function a_carriage_return_is_refused_too(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EnvFileWriter::withValues('', ['DB_PASSWORD' => "geheim\rAPP_DEBUG=true"]);
    }

    #[Test]
    public function an_unknown_key_shape_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EnvFileWriter::withValues('', ['db host' => 'wert']);
    }

    #[Test]
    public function quotes_dollar_and_backslash_are_escaped(): void
    {
        $result = EnvFileWriter::withValues('', ['DB_PASSWORD' => 'a"b$c\\d']);

        $this->assertStringContainsString('DB_PASSWORD="a\\"b\\$c\\\\d"', $result);
    }

    #[Test]
    public function a_key_is_only_replaced_as_a_whole_line(): void
    {
        // DB_HOST darf nicht die Zeile MARIADB_HOST treffen.
        $env = "MARIADB_HOST=anders\nDB_HOST=alt\n";

        $result = EnvFileWriter::withValues($env, ['DB_HOST' => 'neu']);

        $this->assertStringContainsString('MARIADB_HOST=anders', $result);
        $this->assertStringContainsString('DB_HOST="neu"', $result);
    }
}
