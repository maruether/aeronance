<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Documents\PdfLayoutText;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Sources\DirectiveRow;
use App\Modules\Directives\Sources\Nfl\NflClient;
use App\Modules\Directives\Sources\Nfl\NflSource;
use App\Modules\Directives\Sources\SinglePageSource;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The gazette, read from saved originals.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * the requirement was for a second route "zum abgleich" and because it should cover
 * other authorities. Both are asserted here rather than described: one bulletin
 * carries EASA, UK CAA, FAA and Transport Canada side by side, and every row
 * keeps the national LTA number that exists in no other source this module has.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class NflTest extends TestCase
{
    #[Test]
    public function it_reads_the_directives_out_of_one_bulletin(): void
    {
        $rows = $this->rows();

        /*
         * Neunzehn, und die Zahl ist gegengezählt: Das Blatt fuehrt sechs
         * Aenderungen und dreizehn Neuausgaben. Ohne die Entdopplung waren es
         * 57 -- die Anweisungen stehen hinter der Uebersicht noch einmal im
         * Volltext, und jede zaehlte doppelt.
         */
        $this->assertCount(19, $rows);

        $numbers = array_map(static fn (DirectiveRow $r): string => $r->number, $rows);
        $this->assertContains('D-2026-152', $numbers);
        $this->assertContains('D-2026-046R2', $numbers);

        foreach ($rows as $row) {
            $this->assertSame(DirectiveKind::Lta, $row->kind);
            $this->assertSame('2026-07-28', $row->issuedAt);
        }
    }

    #[Test]
    public function every_row_keeps_the_authoritys_own_number(): void
    {
        /*
         * That number IS the cross-check: without it the German list and the
         * EASA list cannot be laid side by side at all.
         */
        $without = array_filter($this->rows(), static fn (DirectiveRow $r): bool => blank($r->externalReference));

        $this->assertSame([], $without);
    }

    #[Test]
    public function it_carries_four_authorities_not_only_easa(): void
    {
        $refs = implode(' ', array_map(
            static fn (DirectiveRow $r): string => (string) $r->externalReference,
            $this->rows(),
        ));

        $this->assertStringContainsString('EASA AD', $refs);
        $this->assertStringContainsString('UK CAA AD', $refs);
        $this->assertStringContainsString('FAA AD', $refs);
        $this->assertStringContainsString('TC', $refs);
    }

    #[Test]
    public function a_wrapped_holder_name_is_not_mixed_with_its_type_certificates(): void
    {
        /*
         * "ROLLS-ROYCE" and "DEUTSCHLAND Ltd & Co KG" stand on two lines, and the
         * type certificate sits in the column beside them. Joining the whole
         * remainder produced "ROLLS-ROYCE EASA.E.036 DEUTSCHLAND Ltd" -- a holder
         * that does not exist, with a certificate number wedged into its name.
         */
        $byNumber = [];

        foreach ($this->rows() as $row) {
            $byNumber[$row->number] = $row;
        }

        $this->assertSame('ROLLS-ROYCE DEUTSCHLAND Ltd & Co KG', $byNumber['D-2024-199R1']->title);
        $this->assertStringNotContainsString('EASA.E.036', $byNumber['D-2024-199R1']->title);
    }

    #[Test]
    public function the_archive_is_read_only_when_it_is_asked_for(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * THE WINDOW IS THE DEFAULT, and it has to be: the whole archive is 664
         * bulletins, each its own PDF, and a weekly job that downloads all of
         * them is an hour of somebody else's bandwidth for nothing new.
         *
         * But a fresh installation needs them once -- the directives still in
         * force were published long before the club installed this. So the depth
         * is a choice, and this asserts that the choice is actually made rather
         * than the flag being decoration.
         * ─────────────────────────────────────────────────────────────────────
         */
        $gazette = new CountingGazette;

        (new NflSource($gazette, new PdfLayoutText, 1))->fetch();
        $window = $gazette->asked;

        $gazette->asked = 0;
        (new NflSource($gazette, new PdfLayoutText, 1))->fetch(['all' => true]);

        $this->assertSame(20, $window, 'Ohne all wird ein Fenster gelesen.');
        $this->assertSame(PHP_INT_MAX, $gazette->asked, 'Mit all das ganze Archiv.');
    }

    #[Test]
    public function the_gazette_is_fetched_once_and_not_once_per_aircraft_type(): void
    {
        /*
         * The gazette is national, not per model -- it never sees the `model`
         * option. Without saying so, the refresh asked it once for every type
         * the club flies: the same list downloaded and parsed five times over.
         * Nothing broke, which is why it went unnoticed.
         */
        $this->assertInstanceOf(
            SinglePageSource::class,
            new NflSource(new SavedGazette, new PdfLayoutText, 1),
        );
    }

    /** @return list<DirectiveRow> */
    private function rows(): array
    {
        return (new NflSource(new SavedGazette, new PdfLayoutText, 1))->fetch();
    }
}

/** Records how deep it was asked to look. */
final class CountingGazette extends NflClient
{
    public int $asked = 0;

    public function entries(int $limit): array
    {
        $this->asked = $limit;

        return (new SavedGazette)->entries(min($limit, 50));
    }

    public function document(string $nflId): string
    {
        return (new SavedGazette)->document($nflId);
    }
}

/** The saved list and the saved bulletin, in place of the live service. */
final class SavedGazette extends NflClient
{
    public function entries(int $limit): array
    {
        $xml = (string) file_get_contents(__DIR__.'/../../Fixtures/Nfl/liste.xml');
        preg_match_all("#<row id='(\d+)'>(.*?)</row>#s", $xml, $matches, PREG_SET_ORDER);

        $rows = [];

        foreach ($matches as $match) {
            preg_match_all('#<!\[CDATA\[(.*?)\]\]>#s', $match[2], $cells);
            $cell = $cells[1] ?? [];

            if (count($cell) >= 7) {
                $rows[] = [
                    'id' => $match[1],
                    'part' => trim($cell[1]),
                    'number' => trim($cell[2]),
                    'issued' => trim($cell[5]),
                    'title' => trim($cell[6]),
                ];
            }
        }

        return array_slice($rows, 0, $limit);
    }

    public function document(string $nflId): string
    {
        return (string) file_get_contents(__DIR__.'/../../Fixtures/Nfl/lta-2026-2-910.pdf');
    }
}
