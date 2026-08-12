<?php

declare(strict_types=1);

namespace Tests\Unit\TaskCards;

use App\Modules\TaskCards\Support\WorkDuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Arbeitszeit-Eingabe: "90" und "1:30" sind dieselbe Aussage.
 *
 * Feldtest: hh:mm muss gehen, und "90min" soll beim Verlassen des Feldes zu
 * "1:30" werden. Alles andere ist keine Zeit -- "1,5" abzulehnen ist besser,
 * als es still mal als 90 und mal als 15 zu lesen.
 */
final class WorkDurationTest extends TestCase
{
    /** @return array<string, array{string|null, int|null}> */
    public static function eingaben(): array
    {
        return [
            'nackte Minuten' => ['90', 90],
            'Stunden:Minuten' => ['1:30', 90],
            'unter einer Stunde' => ['0:45', 45],
            'viele Stunden' => ['12:05', 725],
            'mit Leerraum' => ['  90 ', 90],
            'null Minuten' => ['0', null],
            'null als Zeit' => ['0:00', null],
            'Minutenteil zu gross' => ['1:75', null],
            'Komma ist keine Zeit' => ['1,5', null],
            'Text' => ['anderthalb', null],
            'leer' => ['', null],
            'nichts' => [null, null],
        ];
    }

    #[Test]
    #[DataProvider('eingaben')]
    public function parse_liest_beide_schreibweisen(?string $eingabe, ?int $erwartet): void
    {
        $this->assertSame($erwartet, WorkDuration::parse($eingabe));
    }

    #[Test]
    public function format_schreibt_immer_dieselbe_anzeige(): void
    {
        $this->assertSame('1:30', WorkDuration::format(90));
        $this->assertSame('0:45', WorkDuration::format(45));
        $this->assertSame('12:05', WorkDuration::format(725));
    }

    #[Test]
    public function normalise_ersetzt_lesbares_und_laesst_unlesbares_stehen(): void
    {
        $this->assertSame('1:30', WorkDuration::normalise('90'));
        $this->assertSame('1:30', WorkDuration::normalise('1:30'));

        // Unlesbares bleibt stehen, damit die Validierung es BENENNT --
        // still verschluckt waere es eine Fehldeutung um Faktor sechzig.
        $this->assertSame('1,5', WorkDuration::normalise('1,5'));
    }
}
