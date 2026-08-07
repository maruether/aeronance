<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Documents\PdfLayoutText;
use App\Core\Http\HttpFetcher;
use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\Configured\OverviewSheet;
use App\Modules\Directives\Sources\Configured\SourceSpec;
use App\Modules\Directives\Sources\DirectiveRow;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Streifeneder's "LTA- und TM-Übersicht" -- die Glasflügel-Muster.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ELEVEN REAL SHEETS, and the counts below were not estimated. Each was checked
 * the only way a count can be checked: everything standing in the sheet's number
 * column was pulled out and compared with what the reader made of it. The
 * difference is on every sheet exactly the per-page heading block
 * (Musterbezeichnung, "TM-Nr.:", "Ausgabe") -- not one directive.
 *
 *   H 301 Libelle      51   Std. Libelle 201  50   Std. Libelle 203  26
 *   Club Libelle 205   35   Hornet 206        35   Mosquito 303      31
 *   Glasflügel 304     20   Glasflügel 304/17 15   Kestrel 401       39
 *   Glasflügel 604     20   BS 1 (501)        18
 *
 * ALL ELEVEN ARE READ, and four of them only since the reader stopped measuring
 * column agreement in characters. On a sheet of four pages or more the heading
 * "TM-Nr.:" sits three to five characters right of the numbers beneath it, which
 * a tolerance of two called a sheet it did not understand. The question is not
 * how far apart the two positions are but whether another column lies between
 * them -- on all four, none does.
 *
 * WHAT THIS SHEET DOES NOT HAVE is asserted just as hard as what it does. There
 * is no issue date anywhere on it: the "Datum" column belongs to the inspector,
 * who writes in it when he carried the measure out. Every row therefore arrives
 * with issuedAt null, and that is the document's answer, not a gap in the
 * reading.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class StreifenederTest extends TestCase
{
    /** Every sheet, with the length counted by hand out of its number column. */
    private const READ = [
        'bs1' => 18,
        'clublibelle205' => 35,
        'glasfluegel304' => 20,
        'glasfluegel30417' => 15,
        'h301' => 51,
        'kestrel401' => 39,
        'hornet206' => 35,
        'kestrel604' => 20,
        'libelle201' => 50,
        'libelle203' => 26,
        'mosquito303' => 31,
    ];

    // ── Read whole, or not at all ───────────────────────────────────────────

    #[Test]
    public function every_readable_sheet_comes_back_at_its_counted_length(): void
    {
        foreach (self::READ as $sheet => $length) {
            $this->assertCount($length, $this->rows($sheet), $sheet);
        }
    }

    #[Test]
    public function no_entry_is_left_unrecognised_on_any_sheet(): void
    {
        /*
         * The completeness check, and the half that matters more than the count:
         * the Übersicht is the document an inspector signs, so an entry the
         * number pattern refused is a line of the signed sheet that would be
         * missing from the club's list.
         */
        foreach (array_keys(self::READ) as $sheet) {
            $reader = $this->sheet();
            $reader->rows($this->text($sheet));

            $this->assertSame([], $reader->skipped(), $sheet);
        }
    }

    #[Test]
    public function a_row_keeps_the_authority_number_that_stands_beside_it(): void
    {
        /*
         * THE REASON THE READER GREW A CENTRED-NUMBER MODE, and the worst thing
         * it used to produce. Streifeneder sets the TM number in the middle of
         * its row's height, so a row's first line stands ABOVE its own number:
         *
         *     LTA 2012-105                 Überprüfung/Austausch der HR-
         *     EASA AD 2011-   201-40       Stoßstange (Werknr. 169 und
         *     213                          Std. Libelle 203 Werknr. 1 und 2)
         *
         * Read forwards, "LTA 2012-105" landed on 201-39 -- an airworthiness
         * directive recorded against a technical note it has nothing to do with.
         * Nothing reported it: the row existed and looked entirely plausible.
         */
        $this->assertSame(
            'LTA 2012-105 EASA AD 2011-213',
            $this->find('libelle201', '201-40')['authority_number'],
        );

        // And the row above keeps none, rather than the one it used to borrow.
        $this->assertNull($this->find('libelle201', '201-39')['authority_number']);
    }

    #[Test]
    public function a_row_keeps_the_title_line_that_stands_above_its_number(): void
    {
        // 205-1's subject is typeset a line above its number, and three of this
        // sheet's rows share that subject -- which used to be enough for the
        // page-head detector to take it for furniture and eat it.
        $this->assertSame(
            'HR-Antriebsbeschlag betroffen Werknr. 1-19',
            preg_replace('/\s+/', ' ', (string) $this->find('clublibelle205', '205-1')['title']),
        );
    }

    #[Test]
    public function a_deadline_spanning_two_lines_stays_on_one_row(): void
    {
        /*
         * The English 304/17 sheet writes one deadline as "until" over
         * "31.05.1989". Judged line by line, the word went to one directive and
         * its date to the next -- half a deadline on each of two rows, which is
         * worse than either having none. A cell is assigned whole.
         */
        $this->assertSame('until 30.04.1988', $this->find('glasfluegel30417', '304-3')['compliance']);
        $this->assertStringStartsWith('until 31.05.1989', (string) $this->find('glasfluegel30417', '304-4')['compliance']);
    }

    // ── What the sheet gives, field by field ────────────────────────────────

    #[Test]
    public function no_row_carries_an_issue_date_because_the_sheet_has_none(): void
    {
        // The "Datum" column is the inspector's. Reading it as the TM's issue
        // date would turn somebody's entry on a filled-in sheet into a fact
        // about the directive -- and Streifeneder publishes one such sheet
        // (see the Mosquito, below).
        foreach (array_keys(self::READ) as $sheet) {
            foreach ($this->rows($sheet) as $row) {
                $this->assertNull($row['issued_at'], $sheet.' '.$row['number']);
            }
        }
    }

    #[Test]
    public function the_authority_number_comes_across_where_the_sheet_has_one(): void
    {
        $this->assertSame('75-168/2', $this->find('hornet206', '206-1')['authority_number']);
        $this->assertSame('84-11', $this->find('hornet206', '206-11')['authority_number']);
        $this->assertSame('96-134', $this->find('bs1', '501-7')['authority_number']);
        $this->assertSame('93-001', $this->find('kestrel604', '604-5')['authority_number']);

        // A row without one keeps the field empty rather than borrowing.
        $this->assertNull($this->find('hornet206', '206-22')['authority_number']);
    }

    #[Test]
    public function the_interval_column_comes_across_verbatim(): void
    {
        // The one column that makes this sheet worth reading: the documents
        // themselves say nothing about when or how often.
        $this->assertSame('bei jeder JNP', $this->find('kestrel604', '604-5')['compliance']);
        $this->assertSame('jährlich', $this->find('mosquito303', '303-18')['compliance']);
        $this->assertSame('jede JNP', $this->find('bs1', '1-2005')['compliance']);
        $this->assertSame('until 30.04.1988', $this->find('glasfluegel30417', '304-3')['compliance']);
    }

    // ── The forms a number takes here ───────────────────────────────────────

    #[Test]
    public function the_general_glasfluegel_notes_stand_on_every_type_sheet(): void
    {
        /*
         * Unlike DG, who give theirs a sheet of their own, Streifeneder repeats
         * the general notes at the foot of every type sheet -- with their own
         * interval. They are part of this type's list, so they are read as such.
         */
        foreach (['bs1', 'hornet206', 'kestrel604', 'libelle201', 'libelle203', 'mosquito303'] as $sheet) {
            foreach (['1-2005', '4-2013', '5-2018', '7-2020'] as $number) {
                $this->assertSame($number, $this->find($sheet, $number)['number'], $sheet);
            }
        }
    }

    #[Test]
    public function the_ad_with_no_tm_number_of_its_own_keeps_the_word_the_sheet_uses(): void
    {
        /*
         * "Allgemein-TM" is not a number -- it is what stands in the number
         * column where the only identifier is EASA AD 2018-0143-E. Taking it
         * verbatim beats the alternatives: dropping the line loses a binding AD,
         * and falling back to the authority cell would name the directive
         * "EASA".
         */
        $row = $this->find('hornet206', 'Allgemein-TM');

        // The whole cell. "EASA AD" stands on the line above the number, and
        // the reader used to lose it -- see the sheet's centred numbers.
        $this->assertSame('EASA AD 2018-0143-E', $row['authority_number']);
        $this->assertStringContainsString('Schwerpunktkupplung', $row['title']);

        // The English sheet writes the same thing in English.
        $this->assertSame('General-TN', $this->find('glasfluegel30417', 'General-TN')['number']);
    }

    #[Test]
    public function the_two_country_variants_stay_two_directives(): void
    {
        // "Abweichung in USA zuzulassender Werknr." and the Dutch one are
        // different notes. Without the suffix both would be 303-1, and the
        // second would vanish as a duplicate of the first.
        $numbers = array_column($this->rows('mosquito303'), 'number');

        $this->assertContains('303-1 US', $numbers);
        $this->assertContains('303-1 NL', $numbers);
    }

    #[Test]
    public function the_oldest_notes_keep_their_own_notation(): void
    {
        // The Standard Libelle starts with 1/68 and 1/69, years before
        // Glasflügel numbered by type.
        $numbers = array_column($this->rows('libelle201'), 'number');

        $this->assertSame('1/68', $numbers[0]);
        $this->assertSame('1/69', $numbers[1]);

        // And the sheet itself skips 201-17 and 201-18 -- nothing to read there,
        // and nothing invented to fill the hole.
        $this->assertNotContains('201-17', $numbers);
        $this->assertNotContains('201-18', $numbers);
    }

    #[Test]
    public function a_type_sheet_carries_another_types_notes_where_the_sheet_says_so(): void
    {
        // The Standard Libelle 203 sheet lists 203-1 … 203-3 and, in between,
        // the 201 notes that apply to it. Both belong to this type's list.
        $numbers = array_column($this->rows('libelle203'), 'number');

        $this->assertContains('203-1', $numbers);
        $this->assertContains('201-6', $numbers);
        $this->assertContains('201-30/2', $numbers);
    }

    // ── The driver around the sheet ─────────────────────────────────────────

    #[Test]
    public function the_driver_walks_from_the_model_name_to_the_sheet(): void
    {
        // Two hops, both from the spec: the model names the type's page, and the
        // page carries the address of the PDF -- which changes with every new
        // issue, because Streifeneder dates his file names.
        $rows = $this->fetch('Hornet');

        $this->assertCount(35, $rows);

        $first = $rows[0];
        $this->assertInstanceOf(DirectiveRow::class, $first);
        $this->assertSame('206-1', $first->number);
        $this->assertSame('Hornet', $first->subjectModel);
        $this->assertSame(DirectiveKind::Tm, $first->kind);
        $this->assertStringContainsString('Uebersicht', (string) $first->referenceUrl);

        foreach ($rows as $row) {
            $this->assertNull($row->issuedAt, $row->number);
            $this->assertNull($row->complyBefore, $row->number);
            $this->assertFalse($row->isRecurring, $row->number);
        }
    }

    #[Test]
    public function every_line_arrives_binding_because_this_sheet_has_no_urgency_column(): void
    {
        /*
         * Streifeneder's "Intervall" column holds deadlines and repetitions and
         * nothing else -- counted over all eleven sheets, it never once says
         * "wahlweise". His optionality lives in the Gegenstand, and reading it
         * out of free text would be a judgement about how binding an
         * airworthiness note is.
         *
         * So everything comes in binding. That is the direction in which being
         * wrong is harmless: an optional measure stays on the list until
         * somebody assesses it.
         */
        foreach ($this->fetch('Hornet') as $row) {
            $this->assertSame(Bindingness::Mandatory, $row->bindingness, $row->number);
        }
    }

    #[Test]
    public function the_index_lists_the_types_and_nothing_else(): void
    {
        $types = $this->source()->types();

        $this->assertCount(12, $types);
        $this->assertArrayHasKey('Club Libelle (205)', $types);
        $this->assertArrayHasKey('Mosquito/Mosquito B (303)', $types);

        // The same row of buttons carries the general notes and the EASA
        // Kennblatt as a PDF. Neither is a type.
        $this->assertArrayNotHasKey('Allgemeine technische Mitteilungen Glasflügel', $types);

        foreach ($types as $model => $url) {
            $this->assertStringEndsNotWith('.pdf', $url, $model);
        }
    }

    #[Test]
    public function the_sheet_is_found_on_a_type_page_however_the_file_is_spelled(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            'LTA-TM-Uebersicht_206_Hornet',
            (string) $source->overviewUrl($this->page('musterseite-hornet-206.html')),
        );

        // The same link, with the Ü lost entirely -- Streifeneder spells this
        // file name four different ways across the eleven sheets.
        $this->assertStringContainsString(
            'LTA-TM-bersicht303Mosquito',
            (string) $source->overviewUrl($this->page('musterseite-mosquito-303.html')),
        );
    }

    // ── Failing loudly ──────────────────────────────────────────────────────

    #[Test]
    public function a_type_page_without_a_sheet_stops_the_import(): void
    {
        /*
         * The Falcon page is empty -- the type is listed, the sheet is not
         * there. An empty result would read as "for this type there is nothing
         * to comply with", which is the one answer this must never give
         * silently.
         */
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/keine Übersicht|kein Link/');

        $this->fetch('Falcon');
    }

    #[Test]
    public function a_body_that_is_not_a_sheet_is_refused(): void
    {
        $source = new ConfiguredSource($this->spec(), new class implements HttpFetcher
        {
            public function get(string $url, array $headers = []): string
            {
                return '<html><body>Wartungsarbeiten</body></html>';
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/kein PDF|kein Link/');

        $source->fetch(['model' => 'Hornet']);
    }

    #[Test]
    public function a_document_that_is_not_an_overview_is_refused(): void
    {
        // No header, no rows -- and an empty list is never the answer.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Kopfzeile|keine einzige/');

        $this->sheet()->rows("Sehr geehrte Damen und Herren,\n\nanbei die Rechnung.\n");
    }

    // ── Plumbing ────────────────────────────────────────────────────────────

    private function spec(): SourceSpec
    {
        return SourceSpec::fromArray(
            Yaml::parseFile(resource_path('directive-sources/streifeneder.yaml')),
            'streifeneder.yaml',
        );
    }

    private function sheet(): OverviewSheet
    {
        return $this->spec()->overviewSheet();
    }

    private function source(): ConfiguredSource
    {
        return new ConfiguredSource($this->spec(), new StreifenederStubFetcher);
    }

    /** @return list<DirectiveRow> */
    private function fetch(string $model): array
    {
        return $this->source()->fetch(['model' => $model]);
    }

    private function text(string $sheet): string
    {
        return (new PdfLayoutText)->fromFile(
            base_path('tests/Fixtures/Streifeneder/'.$sheet.'.pdf'),
        );
    }

    private function page(string $file): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/Streifeneder/'.$file));
    }

    /**
     * @return list<array{number: string, issued_at: ?string, authority_number: ?string,
     *                    subject: ?string, title: string, summary: string, compliance: ?string}>
     */
    private function rows(string $sheet): array
    {
        return $this->sheet()->rows($this->text($sheet));
    }

    /** @return array<string, mixed> */
    private function find(string $sheet, string $number): array
    {
        foreach ($this->rows($sheet) as $row) {
            if ($row['number'] === $number) {
                return $row;
            }
        }

        $this->fail(sprintf('Keine Zeile %s auf dem Blatt %s.', $number, $sheet));
    }
}

/**
 * Streifeneder's site, as far as an overview fetch sees it.
 *
 * Three kinds of address, because the spec walks three: the TM index, a type
 * page, and the sheet itself.
 */
final class StreifenederStubFetcher implements HttpFetcher
{
    public function get(string $url, array $headers = []): string
    {
        $file = match (true) {
            str_ends_with($url, '.pdf') => 'hornet206.pdf',
            str_contains($url, 'falcon') => 'musterseite-falcon.html',
            str_contains($url, 'mosquito') => 'musterseite-mosquito-303.html',
            str_contains($url, '/tm/') => 'index.html',
            default => 'musterseite-hornet-206.html',
        };

        return (string) file_get_contents(base_path('tests/Fixtures/Streifeneder/'.$file));
    }
}
