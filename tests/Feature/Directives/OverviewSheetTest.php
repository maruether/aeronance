<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Documents\PdfLayoutText;
use App\Core\Http\HttpFetcher;
use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\Configured\OverviewSheet;
use App\Modules\Directives\Sources\Configured\SourceSpec;
use App\Modules\Directives\Sources\DirectiveRow;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Reading the manufacturers' overview sheets.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * AGAINST THE REAL DOCUMENTS, and there is no other way to test this. The sheets
 * are PDFs typeset by four different people over forty years; a synthetic
 * fixture would only prove that the reader reads what the test author already
 * believed.
 *
 * The numbers below were counted by hand against the documents:
 *
 *   DG-300     31 Zeilen   359/1 … 359/24, 359/17 Rev.1, DG-SS-01, 99/17,
 *                          DG-SS-05, DG-SS-09, DG-SS-08, DG-SS-10
 *   DG-1000    64 Zeilen   413-01 … 1000/52, elf davon Service Infos
 *   LS4        51 Zeilen   4001 … 4053, LS-S-01, plus eine reine AD-Zeile
 *   General    14 Zeilen   DG-G-01 … DG-G-17 (05, 10 und 15 gibt es nicht)
 *   ASK 21     53 Zeilen   1 … 47 mit a/b-Ausgaben, plus zwei reine LTA-Zeilen
 *   ASK 23     17 Zeilen   1 … 15 mit 1a, plus zwei reine LTA-Zeilen
 *
 * And the count is only half of it. THE OTHER HALF IS skipped(): the sheet is
 * the binding document, so an entry the number pattern refused is a line of the
 * signed list that would be missing. Every one of these six sheets must come
 * back with nothing in that report -- that, not the row count, is what says the
 * document was read whole.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class OverviewSheetTest extends TestCase
{
    private function dg(): OverviewSheet
    {
        return $this->spec('dg')->overviewSheet();
    }

    private function schleicher(): OverviewSheet
    {
        return $this->spec('schleicher')->overviewSheet();
    }

    private function spec(string $name): SourceSpec
    {
        return SourceSpec::fromArray(
            Yaml::parseFile(resource_path('directive-sources/'.$name.'.yaml')),
            $name.'.yaml',
        );
    }

    private function text(string $fixture): string
    {
        return (new PdfLayoutText)->fromFile(base_path('tests/Fixtures/'.$fixture));
    }

    /**
     * @return list<array{number: string, issued_at: ?string, authority_number: ?string,
     *                    subject: ?string, title: string, summary: string, compliance: ?string}>
     */
    private function rows(OverviewSheet $sheet, string $fixture): array
    {
        $rows = $sheet->rows($this->text($fixture));

        // Asserted on every read rather than in one test of its own: a sheet
        // whose pattern has gone stale must never pass quietly here.
        $this->assertSame([], $sheet->skipped(), $fixture.': nichts darf unerkannt bleiben.');

        return $rows;
    }

    /** @param list<array<string, mixed>> $rows */
    private function find(array $rows, string $number): array
    {
        foreach ($rows as $row) {
            if ($row['number'] === $number) {
                return $row;
            }
        }

        $this->fail(sprintf('Keine Zeile %s im Blatt.', $number));
    }

    // ── The four DG layouts ─────────────────────────────────────────────────

    #[Test]
    public function the_dg_300_sheet_is_read_whole(): void
    {
        $rows = $this->rows($this->dg(), 'Dg/uebersicht-dg-300.pdf');

        $this->assertCount(31, $rows);
    }

    #[Test]
    public function the_dg_1000_sheet_is_read_whole(): void
    {
        // Twelve pages in four layouts, and the sheet DG changed notation on
        // twice: 413-01, then 1000/09, then TM1000/22.
        $rows = $this->rows($this->dg(), 'Dg/uebersicht-dg-1000.pdf');

        $this->assertCount(64, $rows);
    }

    #[Test]
    public function the_ls4_sheet_is_read_whole(): void
    {
        // The sheet where the two headings run together into one column, so the
        // number column can only be found by counting where numbers are.
        $rows = $this->rows($this->dg(), 'Dg/uebersicht-ls4.pdf');

        $this->assertCount(51, $rows);
    }

    #[Test]
    public function the_general_sheet_is_read_whole(): void
    {
        $rows = $this->rows($this->spec('dg-general')->overviewSheet(), 'Dg/uebersicht-general.pdf');

        $this->assertCount(14, $rows);
        $this->assertSame('DG-G-01', $rows[0]['number']);
    }

    // ── What a row must carry ───────────────────────────────────────────────

    #[Test]
    public function a_row_carries_the_issue_date_the_document_library_does_not_have(): void
    {
        $rows = $this->rows($this->dg(), 'Dg/uebersicht-dg-300.pdf');

        // 09.03.84 -- two digits, and the sheets go back to 1981.
        $this->assertSame('1984-03-09', $this->find($rows, '359/1')['issued_at']);

        // "31 Jan. 2025" -- DG changed notation around 2015, and the newest rows
        // on every sheet are written out.
        $this->assertSame('2025-01-31', $this->find($rows, 'DG-SS-08')['issued_at']);
    }

    #[Test]
    public function a_row_without_a_date_keeps_none(): void
    {
        // 359/23 and 359/24 carry no TM issue date at all -- only the LTA's. An
        // empty field beats a confidently wrong one, and the earlier reader put
        // the NEXT row's date here.
        $rows = $this->rows($this->dg(), 'Dg/uebersicht-dg-300.pdf');

        $this->assertNull($this->find($rows, '359/23')['issued_at']);
        $this->assertNull($this->find($rows, '359/24')['issued_at']);
    }

    #[Test]
    public function the_authority_number_is_the_number_and_never_its_date(): void
    {
        $rows = $this->rows($this->dg(), 'Dg/uebersicht-dg-300.pdf');

        $this->assertSame('AD 2024/0126', $this->find($rows, 'DG-SS-09')['authority_number']);

        // Where the sheet writes "/" there is none -- and a row without one must
        // not become mandatory because a date was left standing in the cell.
        $this->assertNull($this->find($rows, '359/1')['authority_number']);
    }

    #[Test]
    public function an_authority_number_broken_across_two_lines_is_put_back_together(): void
    {
        // "LTA D-2005-" on one line and "196" on the next: a cell that wraps
        // after a hyphen wrapped inside a word.
        $rows = $this->rows($this->dg(), 'Dg/uebersicht-dg-300.pdf');

        $this->assertStringContainsString('D-2005-196', (string) $this->find($rows, '359/23')['authority_number']);
    }

    #[Test]
    public function the_kind_marker_is_not_part_of_the_number(): void
    {
        /*
         * The same sheet writes "DG-SS-01" and "TM DG-SS-05" for one series, and
         * "1000/21" and "TM1000/22" for another. Marker and identity are
         * different things -- keeping the marker out is what lets one directive
         * be recognised as one directive.
         */
        $rows = $this->rows($this->dg(), 'Dg/uebersicht-dg-300.pdf');
        $numbers = array_column($rows, 'number');

        $this->assertContains('DG-SS-01', $numbers);
        $this->assertContains('DG-SS-05', $numbers);
        $this->assertNotContains('TM DG-SS-05', $numbers);
    }

    #[Test]
    public function a_service_info_keeps_the_number_from_the_line_below_its_marker(): void
    {
        // "Service Info" stands on the row line and "99/17" underneath it.
        $rows = $this->rows($this->dg(), 'Dg/uebersicht-dg-300.pdf');
        $row = $this->find($rows, '99/17');

        $this->assertSame('2017-11-30', $row['issued_at']);
        $this->assertStringContainsString('2017-0225', $row['title']);
        $this->assertStringContainsString('Schroth', $row['summary']);
    }

    #[Test]
    public function an_english_twin_is_the_same_row_and_not_a_second_one(): void
    {
        /*
         * DG writes "TM DG-SS-09" on the German line and "TN DG-SS-09" on the
         * English one below. Read as a row start, every note from TM1000/30
         * onwards would arrive twice -- which is the duplication the overview was
         * supposed to end.
         */
        $rows = $this->rows($this->dg(), 'Dg/uebersicht-dg-1000.pdf');
        $numbers = array_column($rows, 'number');

        $this->assertSame(1, count(array_keys($numbers, '1000/30', true)));
        $this->assertSame(1, count(array_keys($numbers, '1000/51', true)));
    }

    #[Test]
    public function a_revision_with_its_own_line_stays_its_own_row(): void
    {
        // "TM 359/17 Rev.1" is a different document from 359/17 and the sheet
        // gives it a line of its own -- while "TM1000/36 Revision 4" is the same
        // note in a new issue and must not become a second directive.
        $dg300 = $this->rows($this->dg(), 'Dg/uebersicht-dg-300.pdf');
        $dg1000 = $this->rows($this->dg(), 'Dg/uebersicht-dg-1000.pdf');

        $this->assertNotEmpty($this->find($dg300, '359/17 Rev.1'));
        $this->assertNotEmpty($this->find($dg300, '359/17'));
        $this->assertNotEmpty($this->find($dg1000, '1000/36'));
    }

    #[Test]
    public function a_year_left_over_from_a_wrapped_date_is_not_a_directive(): void
    {
        // "7. December" on one line and "2016" on the next. Read as numbers,
        // those produced two directives that do not exist.
        $numbers = array_column($this->rows($this->dg(), 'Dg/uebersicht-dg-1000.pdf'), 'number');

        $this->assertNotContains('2016', $numbers);
        $this->assertNotContains('2018', $numbers);
    }

    #[Test]
    public function a_row_whose_number_sits_below_its_text_keeps_its_text(): void
    {
        // LS4's last page is set that way throughout: the subject and the urgency
        // stand a line above the number.
        $rows = $this->rows($this->dg(), 'Dg/uebersicht-ls4.pdf');

        $this->assertSame('Cockpitluftabsaugung (Mandl)', $this->find($rows, '4050')['title']);
        $this->assertSame('optional als Nachrüstung', $this->find($rows, '4050')['compliance']);
    }

    #[Test]
    public function a_row_with_no_manufacturer_number_is_still_a_row(): void
    {
        /*
         * LS4 carries EASA AD 2022-0230 with a dash where the TM number would
         * be, because the AD is the whole document. Losing it would lose a
         * binding one.
         */
        $rows = $this->rows($this->dg(), 'Dg/uebersicht-ls4.pdf');

        $ads = array_values(array_filter(
            $rows,
            static fn (array $r): bool => $r['number'] === ''
                && str_contains((string) $r['authority_number'], '2022-0230'),
        ));

        $this->assertCount(1, $ads);
        $this->assertStringContainsString('Stoßstangenköpfe', $ads[0]['title']);
    }

    // ── Another manufacturer, the same reader ───────────────────────────────

    #[Test]
    public function schleichers_sheet_needs_a_different_spec_and_no_other_change(): void
    {
        /*
         * The point of the whole exercise. Schleicher heads its urgency column
         * "Termin" where DG heads it "Dringlichkeit", numbers its notes 1 to 47
         * instead of 359/1, rules its rows apart with blank lines and publishes
         * German and English as separate files. All of that is in the spec; the
         * reader is the same object.
         */
        $rows = $this->rows($this->schleicher(), 'Schleicher/uebersicht-ask-21.pdf');

        $this->assertCount(53, $rows);
        $this->assertSame('1980-05-06', $this->find($rows, '1')['issued_at']);
        $this->assertSame('wahlweise', $this->find($rows, '2')['compliance']);
    }

    #[Test]
    public function the_date_column_is_the_notes_own_and_not_the_authoritys(): void
    {
        /*
         * Schleicher writes "Ausgabedatum" twice in one head, under the LTA
         * number and under the TM. Taking the first put the LTA's approval date
         * on six of the ASK 23's fifteen notes.
         */
        $rows = $this->rows($this->schleicher(), 'Schleicher/uebersicht-ask-23.pdf');

        $this->assertCount(17, $rows);
        $this->assertSame('1990-11-26', $this->find($rows, '8')['issued_at']);
        $this->assertSame('1993-09-14', $this->find($rows, '10')['issued_at']);
    }

    #[Test]
    public function a_sentence_wrapped_over_three_lines_is_one_title(): void
    {
        // Single-language sheet: the whole block is the title, unlike DG where
        // the line below is the English translation.
        $rows = $this->rows($this->schleicher(), 'Schleicher/uebersicht-ask-21.pdf');

        $this->assertSame(
            'Änderung des Flughandbuches -Sollbruchstelle im Schleppseil-',
            $this->find($rows, '6')['title'],
        );
    }

    // ── What it refuses to do ───────────────────────────────────────────────

    #[Test]
    public function a_sheet_it_cannot_read_is_refused_rather_than_reported_empty(): void
    {
        // A PDF that is not an overview at all -- the LBA's Kennblatt list.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/keine lesbare Kopfzeile/');

        $this->dg()->rows($this->text('Lba/blaues-buch-segel.pdf'));
    }

    #[Test]
    public function a_pattern_that_matches_nothing_is_refused_rather_than_reported_empty(): void
    {
        $sheet = new OverviewSheet(
            '/^NICHTSDERGLEICHEN$/',
            ['number' => ['tm-nr'], 'title' => ['gegenstand'], 'compliance' => ['dringlichkeit']],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/keine einzige Zeile/');

        $sheet->rows($this->text('Dg/uebersicht-dg-300.pdf'));
    }

    #[Test]
    public function an_unrecognised_entry_is_named_rather_than_dropped(): void
    {
        /*
         * The completeness check itself. With the Service Info alternative taken
         * out of the pattern, four of DG-1000's rows can no longer be read -- and
         * the report has to say so by name, because the sheet is the binding
         * document and those are lines of it.
         */
        $sheet = new OverviewSheet(
            '~^(?:T[MN](?=[\s\d]))?\s*((?:\d{3,4}[-/]\d{1,3}|(?!19|20)\d{4})[a-z]?|[A-Z]{2}-[A-Z]{1,2}-\d{1,3})(?![\w./-])~x',
            [
                'authority' => ['lba-lta', 'easa ad'],
                'number' => ['tm-nr'],
                'subject' => ['betrifft'],
                'title' => ['gegenstand'],
                'compliance' => ['dringlichkeit'],
            ],
            bilingual: true,
        );

        $rows = $sheet->rows($this->text('Dg/uebersicht-dg-1000.pdf'));

        $this->assertCount(56, $rows, 'Acht Zeilen weniger als die 64 des Blattes.');

        foreach (['76/12', '85-13', '89-14', '93-15', 'SI 116-2025'] as $lost) {
            $this->assertContains($lost, $sheet->skipped(), $lost.' muss namentlich gemeldet werden.');
        }
    }

    // ── The driver around it ────────────────────────────────────────────────

    #[Test]
    public function the_driver_turns_a_sheet_into_directive_rows(): void
    {
        $rows = $this->fetch('dg', 'DG-300', 'Dg/uebersicht-dg-300.pdf');

        $this->assertCount(31, $rows);

        $first = $rows[0];
        $this->assertInstanceOf(DirectiveRow::class, $first);
        $this->assertSame('359/1', $first->number);
        $this->assertSame('1984-03-09', $first->issuedAt);
        $this->assertSame('DG-300', $first->subjectModel);
        $this->assertSame('DG Aviation', $first->issuer);

        // The sheet itself, until a line can be linked to its own PDF.
        $this->assertStringContainsString('uebersicht', (string) $first->referenceUrl);

        // Never invented, whatever the urgency column says in prose.
        foreach ($rows as $row) {
            $this->assertNull($row->complyBefore, $row->number);
            $this->assertFalse($row->isRecurring, $row->number);
        }
    }

    #[Test]
    public function the_urgency_column_decides_bindingness_and_an_ad_overrides_it(): void
    {
        $rows = $this->fetch('dg', 'DG-300', 'Dg/uebersicht-dg-300.pdf');
        $by = [];

        foreach ($rows as $row) {
            $by[$row->number] = $row;
        }

        // "wahlweise" -- the wording the spec lists.
        $this->assertSame(Bindingness::Optional, $by['359/1']->bindingness);

        // An authority number wins outright.
        $this->assertSame(Bindingness::Mandatory, $by['DG-SS-09']->bindingness);

        // "innerhalb 30 Tagen" is not on the list, and anything unlisted is
        // binding -- being wrong towards binding leaves a line on the list.
        $this->assertSame(Bindingness::Mandatory, $by['359/7']->bindingness);
    }

    #[Test]
    public function a_landing_page_between_the_spec_and_the_pdf_is_followed(): void
    {
        // DG's sheets live behind a file manager: the spec holds the permanent
        // address of a page, and the download link on it changes with every
        // re-upload.
        $rows = $this->fetch('dg', 'DG-300', 'Dg/uebersicht-dg-300.pdf', withLandingPage: true);

        $this->assertCount(31, $rows);
    }

    #[Test]
    public function an_unreadable_entry_stops_the_import_and_names_it(): void
    {
        /*
         * Strict on purpose. A club that is told "1 Eintrag nicht erkannt" can
         * fix the manufacturer file that afternoon; a club that silently gets 63
         * of 64 rows has an aircraft with an obligation nobody knows about.
         */
        $raw = Yaml::parseFile(resource_path('directive-sources/dg.yaml'));
        $raw['overview']['ignore'] = null;
        $raw['overview']['number_pattern'] = '~^(\d{3,4}[-/]\d{1,3})(?![\w./-])~';

        $source = new ConfiguredSource(
            SourceSpec::fromArray($raw, 'dg.yaml'),
            new OverviewStubFetcher('Dg/uebersicht-dg-300.pdf'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nicht kennt/');

        $source->fetch(['model' => 'DG-300']);
    }

    #[Test]
    public function a_type_the_manufacturer_has_no_sheet_for_is_named(): void
    {
        $source = new ConfiguredSource(
            $this->spec('dg'),
            new OverviewStubFetcher('Dg/uebersicht-dg-300.pdf'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/keine Übersicht für "Ka 8"/');

        $source->fetch(['model' => 'Ka 8']);
    }

    #[Test]
    public function a_body_that_is_not_a_pdf_is_refused(): void
    {
        // A login page or a 404 body handed to pdftotext yields nothing, and
        // nothing reads as "this manufacturer published no directives".
        $source = new ConfiguredSource($this->spec('dg'), new class implements HttpFetcher
        {
            public function get(string $url, array $headers = []): string
            {
                return '<html><body>Bitte anmelden</body></html>';
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/kein PDF|kein Link/');

        $source->fetch(['model' => 'DG-300']);
    }

    /** @return list<DirectiveRow> */
    private function fetch(string $spec, string $model, string $fixture, bool $withLandingPage = false): array
    {
        $source = new ConfiguredSource(
            $this->spec($spec),
            new OverviewStubFetcher($fixture, $withLandingPage),
        );

        return $source->fetch(['model' => $model]);
    }
}

/**
 * A manufacturer's site, as far as an overview fetch sees it.
 *
 * Serves the saved sheet for anything that asks for a PDF, and -- when a test
 * wants the second hop -- the saved landing page first.
 */
final class OverviewStubFetcher implements HttpFetcher
{
    public function __construct(
        private readonly string $fixture,
        private readonly bool $withLandingPage = false,
    ) {}

    public function get(string $url, array $headers = []): string
    {
        if ($this->withLandingPage && ! str_contains($url, '/download/')) {
            return file_get_contents(base_path('tests/Fixtures/Dg/detailseite-dg-300.html'));
        }

        return file_get_contents(base_path('tests/Fixtures/'.$this->fixture));
    }
}
