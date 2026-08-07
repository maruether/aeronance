<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Documents\PdfLayoutText;
use App\Core\Http\HttpFetcher;
use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\Configured\SourceSpec;
use App\Modules\Directives\Sources\DirectiveRow;
use App\Modules\Directives\Sources\UnknownType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Allstar PZL Glider (SZD) -- "Index of Service Publications".
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * AGAINST THE REAL SHEETS, saved from szdallstar.com. The counts below were
 * taken by hand from the documents, line by line:
 *
 *   SZD-48-3 Jantar Std. 3   16   BE-020/83 … BK-048/96
 *   SZD-50-3 Puchacz         71   BE-01/79 … BE-064/50-3/2022, drei SIL
 *   SZD-51-1 Junior          14   BE-001/85 … BE-014/22
 *   SZD-54-2 Perkoz           6   BE-001, BE-002, BE-004 und dessen Revision 1,
 *                                 SIL-003, SIL-004
 *   SZD-55-1 Nexus           12   BE-01-55-1-89 … BE-012/55-1/2012
 *   SZD-59/-1 Acro           11   BK-01-59-95 … BE-11-59-09
 *   General                  13   BK-001/77 … BE-001/SZD/2023
 *
 * And the count is only half of it. The other half is skipped(): the index is
 * the binding document, so an entry the number pattern refused is a line of it
 * that would be missing from a club's list. Every one of these seven sheets
 * must come back with nothing in that report.
 *
 * THE POINT OF THIS MANUFACTURER is the third thing tested here: SZD prints its
 * MANUALS and its SERVICE BULLETINS in one document, the manuals first. Nothing
 * from that half may arrive as a directive -- see manuals_are_not_read_as_directives().
 *
 * THE ROW BOUNDARIES, which used to be the open wound here: SZD sets its
 * numbers vertically CENTRED, rules its rows with no blank line, AND wraps the
 * numbers themselves over two lines. Read from the number line down, every row
 * bled into its neighbours -- titles began mid-sentence in the words of the row
 * above, and serial ranges came out wrong, which makes a directive silently not
 * apply. overview.rows_tile_around_numbers computes the boundaries instead of
 * guessing them (end = 2 * centre - start); the last test of this file asserts
 * the result and pins the one artefact that is left.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SzdTest extends TestCase
{
    /** @return list<array{0: string, 1: string, 2: int}> */
    public static function sheets(): array
    {
        return [
            'Jantar' => ['szd', 'index-szd-48-3-jantar.pdf', 16],
            'Puchacz' => ['szd', 'index-szd-50-3-puchacz.pdf', 71],
            'Junior' => ['szd', 'index-szd-51-1-junior.pdf', 14],
            'Perkoz' => ['szd', 'index-szd-54-2-perkoz.pdf', 6],
            'Nexus' => ['szd', 'index-szd-55-1-nexus.pdf', 12],
            'Acro' => ['szd', 'index-szd-59-1-acro.pdf', 11],
            'General' => ['szd-general', 'index-general-service-bulletins.pdf', 13],
        ];
    }

    #[Test]
    #[DataProvider('sheets')]
    public function a_sheet_is_read_whole(string $spec, string $fixture, int $expected): void
    {
        $sheet = $this->spec($spec)->overviewSheet();
        $rows = $sheet->rows($this->text($fixture));

        $this->assertCount($expected, $rows, $fixture.': Zeilenzahl gegen das Dokument.');

        // Not a nicety but the completeness check itself -- see the class docblock.
        $this->assertSame([], $sheet->skipped(), $fixture.': nichts darf unerkannt bleiben.');
    }

    // ── Was die Nummer eines SZD-Blattes alles sein kann ────────────────────

    #[Test]
    public function the_four_polish_bulletin_prefixes_are_one_series(): void
    {
        /*
         * BE, BK, BI and BR count through ONE sequence -- …BE-03/81, BK-04/81,
         * BE-05/81… -- so the letter is part of the number, not a marker in
         * front of it. Unlike DG, where "TM 359/1" and "TN 359/1" are the same
         * directive said in two languages.
         */
        $numbers = $this->numbers('szd', 'index-szd-50-3-puchacz.pdf');

        $this->assertContains('BE-03/50-3/81', $numbers);
        $this->assertContains('BK-04/50-3/81', $numbers);
        $this->assertContains('BE-05/50-3/81', $numbers);
        $this->assertContains('BI-27/50-3/84', $numbers);
        $this->assertContains('BR-39/50-3/89', $numbers);

        // The one bulletin SZD numbered with a bare "A".
        $this->assertContains('A-047/50-3/94', $numbers);
    }

    #[Test]
    public function the_year_is_part_of_the_number_because_the_sequence_restarts(): void
    {
        // BE-01/79 and BE-01/50-3/80 are two different bulletins. Truncating to
        // "BE-01" would merge them.
        $numbers = $this->numbers('szd', 'index-szd-50-3-puchacz.pdf');

        $this->assertContains('BE-01/79', $numbers);
        $this->assertContains('BE-01/50-3/80', $numbers);
        $this->assertContains('BE-01/50-3/81', $numbers);
    }

    #[Test]
    public function a_number_split_over_two_lines_is_read_as_one(): void
    {
        // "BE-064/50-" on one line, "3/2022" on the next -- a break after a
        // hyphen closes up.
        $this->assertContains('BE-064/50-3/2022', $this->numbers('szd', 'index-szd-50-3-puchacz.pdf'));

        // "BE/R-10-55-1-" and "96", where the prefix itself carries a slash.
        $nexus = $this->numbers('szd', 'index-szd-55-1-nexus.pdf');
        $this->assertContains('BE/R-10-55-1-96', $nexus);
        $this->assertContains('BE-01-55-1-89', $nexus);

        // "SIL-" alone in the cell, "001/SZD/2013" underneath.
        $this->assertContains('SIL-001/SZD/2013', $this->numbers('szd', 'index-szd-50-3-puchacz.pdf'));
    }

    #[Test]
    public function a_revision_is_a_line_of_its_own(): void
    {
        /*
         * Perkoz lists BE-004/54-2/2026 and, underneath, the same bulletin's
         * Revision 1 -- two rows, two documents. Dropping "Revision 1" from the
         * number would make one row of them.
         */
        $numbers = $this->numbers('szd', 'index-szd-54-2-perkoz.pdf');

        $this->assertContains('BE-004/54-2/2026', $numbers);
        $this->assertContains('BE-004/54-2/2026 Revision 1', $numbers);
    }

    #[Test]
    public function a_number_broken_after_a_slash_is_put_back_together(): void
    {
        /*
         * The sheet prints "BE-048/SZD-50-3/2000" and wraps it inside the cell
         * after the slash. Joined with a space -- as any other break is -- it
         * became "BE-048/ SZD-50-3/2000", a number that stands nowhere in the
         * document and matches nothing the manufacturer publishes. Sixteen of
         * Puchacz's seventy-one lines break this way.
         *
         * A slash left at the end of a line is the sheet saying the word is not
         * finished, exactly as a hyphen is.
         */
        $numbers = $this->numbers('szd', 'index-szd-50-3-puchacz.pdf');

        $this->assertContains('BE-048/SZD-50-3/2000', $numbers);
        $this->assertContains('BE-049/SZD-50-3-2000', $numbers);
        $this->assertContains('BE-063/SZD-50-3/2014', $numbers);

        foreach ($numbers as $number) {
            $this->assertStringNotContainsString('/ ', (string) $number);
        }
    }

    // ── Handbücher sind keine Anweisungen ───────────────────────────────────

    #[Test]
    #[DataProvider('sheets')]
    public function manuals_are_not_read_as_directives(string $spec, string $fixture, int $expected): void
    {
        /*
         * THE RISK OF THIS MANUFACTURER. Every type sheet opens with a "MANUALS"
         * block -- flight manual, technical service manual, repair manual, spare
         * parts catalogue, each with an issue and a revision date -- and only
         * then comes "SERVICE BULLETINS".
         *
         * Three things keep them apart, and all three are checked here at once:
         * the manuals block has no SB number in it, its publisher column is
         * named in overview.ignore, and its own heading row ("Document",
         * "Date of Issue") is not a table head for this reader.
         */
        $rows = $this->spec($spec)->overviewSheet()->rows($this->text($fixture));

        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression(
                '~^(?:BE/R|BE|BK|BI|BR|SIL|A)-\d~',
                $row['number'],
                $fixture.': jede gelesene Zeile muss eine SB-Nummer tragen.',
            );
        }

        $text = implode(' | ', array_column($rows, 'title'));

        foreach (['Flight Manual', 'Repair Manual', 'Spare Parts Catalogue', 'Instrukcja', 'Flughandbuch'] as $manual) {
            $this->assertStringNotContainsString($manual, $text, $fixture.': '.$manual.' ist kein Service Bulletin.');
        }
    }

    #[Test]
    public function the_publisher_of_the_manuals_is_not_a_lost_directive(): void
    {
        /*
         * The other half of the same problem: the manuals block writes "PD-PS
         * „PZL Bielsko”" and "Allstar PZL Glider" into the very column the
         * numbers live in. Those are named in overview.ignore -- if they were
         * not, every fetch would abort with them listed as possibly-lost
         * directives, and the sheet would never import at all.
         */
        foreach (['index-szd-48-3-jantar.pdf', 'index-szd-51-1-junior.pdf', 'index-szd-59-1-acro.pdf'] as $fixture) {
            $sheet = $this->spec('szd')->overviewSheet();
            $sheet->rows($this->text($fixture));

            $this->assertSame([], $sheet->skipped(), $fixture);
        }
    }

    #[Test]
    public function the_table_ends_at_end_of_record(): void
    {
        // Below it stand the language legend and the footer, and on the Junior
        // sheet a note naming BE-010 … BE-014 -- numbers that are already rows.
        $numbers = $this->numbers('szd', 'index-szd-51-1-junior.pdf');

        $this->assertSame('BE-014/22', end($numbers));
        $this->assertCount(14, $numbers);
    }

    // ── Die Spalten dieses Herstellers ──────────────────────────────────────

    #[Test]
    public function the_sheet_carries_no_issue_date(): void
    {
        /*
         * SZD's index has no date column at all -- the year lives only inside
         * the number. Deriving one from "BE-063/SZD-50-3/2014" would be
         * inventing a day and a month nobody wrote down.
         */
        foreach ($this->rows('szd', 'index-szd-50-3-puchacz.pdf') as $row) {
            $this->assertNull($row['issued_at'], $row['number']);
        }
    }

    #[Test]
    public function the_compliance_column_decides_how_binding_a_line_is(): void
    {
        /*
         * Why the sheet is worth reading at all. Checked on the Acro sheet,
         * whose first page rules its rows with blank lines and therefore reads
         * cleanly -- see the last test for what happens where it does not.
         */
        $rows = $this->fetch('szd', 'SZD-59-1', 'index-szd-59-1-acro.pdf');
        $by = [];

        foreach ($rows as $row) {
            $by[$row->number] = $row;
        }

        $this->assertSame('Rudder modification', $by['BK-04-59-97']->title);
        $this->assertSame(Bindingness::Mandatory, $by['BK-04-59-97']->bindingness);
        $this->assertSame(DirectiveKind::Sb, $by['BK-04-59-97']->kind);

        // "Acc. to User's decision" -- the manufacturer's own wording for
        // optional, with SZD's typographic apostrophe.
        $this->assertSame(Bindingness::Optional, $by['BE-07-59-98']->bindingness);

        // "Mandatory after 1500 FH" is not optional, and neither is a bare
        // "After receiving of SB".
        $this->assertSame(Bindingness::Mandatory, $by['BE-06-59-98']->bindingness);
        $this->assertSame(Bindingness::Mandatory, $by['BE-09-59-09']->bindingness);
    }

    #[Test]
    public function an_index_of_sb_line_is_information_rather_than_an_obligation(): void
    {
        // BI-… lines list the bulletins themselves; the sheet marks them
        // "Information". All three of Puchacz's are read as optional.
        $rows = $this->fetch('szd', 'SZD-50-3', 'index-szd-50-3-puchacz.pdf');
        $found = 0;

        foreach ($rows as $row) {
            if (str_starts_with($row->number, 'BI-')) {
                $this->assertSame(Bindingness::Optional, $row->bindingness, $row->number);
                $found++;
            }
        }

        $this->assertSame(3, $found, 'BI-27, BI-36 und BI-41 stehen auf dem Blatt.');
    }

    // ── Das allgemeine Blatt ────────────────────────────────────────────────

    #[Test]
    public function the_general_sheet_keeps_the_identifying_part_of_a_number(): void
    {
        /*
         * The general bulletins are named after the PART they are about --
         * "BK-001/77/Seat belts/J5.00.00". Only the leading identifier becomes
         * the number, because that is how the sheet cites its own bulletins:
         * BI-002/80 refers to "BK-001/77", without the group.
         */
        $numbers = $this->numbers('szd-general', 'index-general-service-bulletins.pdf');

        $this->assertContains('BK-001/77', $numbers);
        $this->assertContains('BE-03/J5/81', $numbers);
        $this->assertContains('BE-006/93/J5', $numbers);
        $this->assertContains('BE-007/94', $numbers);
        $this->assertContains('BE-001/SZD/2023', $numbers);
    }

    #[Test]
    public function the_general_sheet_is_mostly_binding(): void
    {
        /*
         * Vorgabe: "Wir überprüfen jetzt einfach die generals darauf ob sie
         * mandatory sind. wenn ja -> Normale mandatory tm, wenn nein -> wie
         * gehabt als general."
         *
         * The line is drawn per ROW, not per sheet, and the sheets bear it out:
         * this one is 9 binding and 4 not, DG's is 2 and 12. Neither belonged
         * wholly in either box, which is why a flag on the source was always
         * going to be wrong for one of them.
         *
         * What "general" means is unchanged -- approved data the operator MAY
         * apply, kept off an aircraft's open points until it is recorded as
         * carried out. A mandatory line is the opposite of that whatever sheet
         * it was printed on; BE-007/94 on the life of control cables is not an
         * offer.
         */
        $rows = (new ConfiguredSource(
            $this->spec('szd-general'),
            new SzdStubFetcher('index-general-service-bulletins.pdf'),
        ))->fetch([]);

        $this->assertCount(13, $rows);

        $binding = array_filter(
            $rows,
            static fn ($row): bool => $row->bindingness === Bindingness::Mandatory,
        );

        $this->assertCount(9, $binding, '9 der 13 Zeilen sind verbindlich.');

        foreach ($rows as $row) {
            // No type stamp -- the sheet names none, and inventing one is what
            // the single-page fix below prevents.
            $this->assertNull($row->subjectModel, $row->number);
        }
    }

    #[Test]
    public function one_sheet_for_everything_is_fetched_once_not_per_type(): void
    {
        /*
         * The fault this pins. A source that is not a "single page" is asked
         * about every type the club flies, and overviewFor() falls back to the
         * one fixed address for each of them -- so every row came back stamped
         * with whichever type was asked about. A club with an ASK 21 and a
         * DG-300 imported DG's general notes twice, once as ASK 21 notes.
         *
         * Invisible while such lines were kept off the open points. Not
         * invisible once a general sheet is mandatory, as this one is.
         */
        $this->assertTrue($this->spec('szd-general')->isSinglePage());
        $this->assertTrue($this->spec('szd')->isSinglePage() === false);
    }

    // ── Welches Blatt zu welchem Muster gehört ──────────────────────────────

    #[Test]
    public function a_type_is_matched_to_its_own_sheet(): void
    {
        /*
         * The addresses are written out rather than derived: SZD's pages link
         * relatively under a <base href>, the Puchacz folder is spelled
         * "szd-50-3-puchaz", and the Acro file is called
         * "index-of-service-publications-szd-59-szd-59-1-en.pdf". A guessed
         * address would 404, and a 404 reads like "this type has no directives".
         */
        $spec = $this->spec('szd');

        $this->assertStringContainsString(
            'szd-50-3-puchaz/index-of-service-publications-szd-50-3-en.pdf',
            (string) $spec->overviewDocumentFor('SZD-50-3 Puchacz'),
        );

        $this->assertStringContainsString(
            'szd-51-1-junior/index-of-service-publications-szd-51-1-en.pdf',
            (string) $spec->overviewDocumentFor('SZD-51-1 Junior'),
        );

        // The Acro sheet is headed "SZD-59 / SZD-59-1 Acro" and carries both.
        $this->assertSame(
            $spec->overviewDocumentFor('SZD-59'),
            $spec->overviewDocumentFor('SZD-59-1 Acro'),
        );
    }

    #[Test]
    public function a_type_szd_never_built_is_an_unknown_type(): void
    {
        // Not an empty list: most weeks most manufacturers are asked about an
        // aircraft they never built, and "no rows" reads as "no directives".
        $this->expectException(UnknownType::class);

        (new ConfiguredSource($this->spec('szd'), new SzdStubFetcher('index-szd-50-3-puchacz.pdf')))
            ->fetch(['model' => 'ASK 21']);
    }

    #[Test]
    public function the_serial_range_is_read_where_the_sheet_states_one(): void
    {
        $rows = $this->fetch('szd', 'SZD-50-3', 'index-szd-50-3-puchacz.pdf');

        foreach ($rows as $row) {
            if ($row->number === 'BE-01/79') {
                // "From S/N B-903 to B-907"
                $this->assertSame('B-903', $row->serialFrom);
                $this->assertSame('B-907', $row->serialTo);

                return;
            }
        }

        $this->fail('BE-01/79 fehlt.');
    }

    // ── Die Zeilengrenzen ──────────────────────────────────────────────────

    #[Test]
    public function a_row_ends_where_the_sheet_ends_it(): void
    {
        $by = [];

        foreach ($this->rows('szd', 'index-szd-50-3-puchacz.pdf') as $row) {
            $by[$row['number']] = $row;
        }

        /*
         * ─────────────────────────────────────────────────────────────────────
         * THE ROWS NOW HOLD THEIR OWN TEXT -- this used to be markTestIncomplete.
         *
         * SZD centres a number vertically in its row and rules the rows with no
         * blank line, so a row's block used to bleed into its neighbours: the
         * title of one directive began mid-sentence in the words of the one
         * above it, and BE-03/50-3/80's serial range came back as "B-903 … B-EN"
         * -- a wrong upper bound, which makes a directive silently not apply to
         * an aircraft.
         *
         * overview.rows_tile_around_numbers reads the layout for what it is: the
         * rows touch and each is symmetric about its number, so
         * end = 2 * centre - start with no guessing. Titles are the manufacturer's
         * own sentences again on all seven sheets.
         *
         * WHAT IS STILL NOT SEPARABLE, and it is a measurement limit rather than
         * a row-boundary one: pdftotext puts "Vrs." and "S/N" two to four
         * characters apart, so they are ONE measured column. The language marker
         * therefore sits inside the serial text ("From S/N B-903 to B-EN 907"),
         * and no heading can pull them apart -- tried with headings_centred and
         * subject: ['s/n'], which merely moved the marker into skipped().
         *
         * So the serial range is asserted where it is clean and the interleaving
         * is pinned where it is not. Pinned, not hidden: if the column ever
         * separates, this test says so.
         * ─────────────────────────────────────────────────────────────────────
         */
        $this->assertSame(
            'Changes into FM',
            $by['BE-03/50-3/80']['title'],
            'Der Titel ist wieder der Satz des Herstellers und nicht der Rest der Nachbarzeile.',
        );

        $this->assertStringContainsString(
            'From S/N B-903',
            (string) $by['BE-03/50-3/80']['subject'],
            'Die Zeile trägt ihren eigenen Betroffenheitstext.',
        );

        $this->assertStringNotContainsString(
            'B-966',
            (string) $by['BE-02/50-3/79']['subject'],
            'Und nichts aus der Zeile darunter.',
        );

        // The one artefact that is left, named so it cannot pass unnoticed.
        $this->assertStringContainsString(
            'B-EN 907',
            (string) $by['BE-03/50-3/80']['subject'],
            'Vrs. und S/N werden als eine Spalte gemessen -- der Sprachmarker steht im '
            .'Serientext. Wenn das hier nicht mehr greift, sind die Spalten getrennt: '
            .'dann diese Zusicherung durch die saubere Fassung ersetzen.',
        );
    }

    // ── Hilfsmittel ─────────────────────────────────────────────────────────

    private function spec(string $name): SourceSpec
    {
        return SourceSpec::fromArray(
            Yaml::parseFile(resource_path('directive-sources/'.$name.'.yaml')),
            $name.'.yaml',
        );
    }

    private function text(string $fixture): string
    {
        return (new PdfLayoutText)->fromFile(base_path('tests/Fixtures/Szd/'.$fixture));
    }

    /**
     * @return list<array{number: string, issued_at: ?string, authority_number: ?string,
     *                    subject: ?string, title: string, summary: string, compliance: ?string}>
     */
    private function rows(string $spec, string $fixture): array
    {
        $sheet = $this->spec($spec)->overviewSheet();
        $rows = $sheet->rows($this->text($fixture));

        $this->assertSame([], $sheet->skipped(), $fixture.': nichts darf unerkannt bleiben.');

        return $rows;
    }

    /** @return list<string> */
    private function numbers(string $spec, string $fixture): array
    {
        return array_column($this->rows($spec, $fixture), 'number');
    }

    /** @return list<DirectiveRow> */
    private function fetch(string $spec, string $model, string $fixture): array
    {
        return (new ConfiguredSource($this->spec($spec), new SzdStubFetcher($fixture)))
            ->fetch(['model' => $model]);
    }
}

/** SZD's site, as far as an overview fetch sees it: one saved index PDF. */
final class SzdStubFetcher implements HttpFetcher
{
    public function __construct(private readonly string $fixture) {}

    public function get(string $url, array $headers = []): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/Szd/'.$this->fixture));
    }
}
