<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Http\HttpFetcher;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\Configured\SourceSpec;
use App\Modules\Directives\Sources\DirectiveRow;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * LTB Lindner -- a list of links, the fourth shape.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The brief called this one nasty, and rightly so for a reason I did not expect.
 * It is not the missing table: a WordPress PDF-manager renders one <li> per
 * document, and matching those is easy enough.
 *
 * The trap is that Lindner publishes TWO NUMBERING SCHEMES on one page --
 * their own notes (TM-G11, A-I-G01) beside Grob's originals numbered by type
 * (315-76, 315-GROB-003; 315 is the G 103's Kennblatt number). A first pattern
 * caught only the former and silently dropped nineteen real directives, which is
 * precisely the failure this whole module exists to prevent.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ListSourceTest extends TestCase
{
    private function spec(): SourceSpec
    {
        return SourceSpec::fromArray(
            Yaml::parseFile(resource_path('directive-sources/lindner-g103.yaml')),
            'lindner-g103.yaml',
        );
    }

    private function fixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/Lindner/g-103.html'));
    }

    /** @return list<DirectiveRow> */
    private function rows(): array
    {
        return (new ConfiguredSource($this->spec(), new LindnerStubFetcher))
            ->parseTypePage($this->fixture(), 'G 103');
    }

    // ── Both numbering schemes ──────────────────────────────────────────────

    #[Test]
    public function lindners_own_notes_are_read(): void
    {
        $numbers = $this->numbers();

        $this->assertContains('TM-G11', $numbers);
        $this->assertContains('TM-G01', $numbers);
        $this->assertContains('A-I-G01', $numbers);
    }

    #[Test]
    public function and_grobs_originals_beside_them(): void
    {
        // The nineteen a first pattern dropped. 315 is the G 103's Kennblatt
        // number, which is how Grob numbered its own notes.
        $numbers = $this->numbers();

        $this->assertContains('315-76', $numbers);
        $this->assertContains('315-64-2', $numbers);
        $this->assertContains('315-GROB-003', $numbers);
        $this->assertContains('315-68/1', $numbers, 'A slash in a number is still a number.');
    }

    #[Test]
    public function nothing_real_is_dropped(): void
    {
        /*
         * The guard against the failure mode itself: every <li> in the fixture
         * becomes a row EXCEPT the overview PDF. If a future pattern change starts
         * skipping documents again, this is what notices.
         */
        $items = preg_match_all('#<li class="bsk-pdfm-list-item#', $this->fixture());

        $this->assertSame($items - 1, count($this->rows()), 'Exactly one item may be skipped.');
    }

    #[Test]
    public function and_the_one_skipped_item_is_the_overview(): void
    {
        // Lindner's own summary list -- not a directive anybody assesses, and its
        // title begins with a bare "G", which is what keeps it out.
        $titles = array_map(fn (DirectiveRow $r): string => $r->title, $this->rows());

        foreach ($titles as $title) {
            $this->assertStringNotContainsString('Übersicht', $title);
        }
    }

    // ── What the mode refuses to invent ─────────────────────────────────────

    #[Test]
    public function no_date_is_taken_from_the_upload_stamp(): void
    {
        /*
         * THE reason this spec maps no date. Lindner's items carry a data-date,
         * but 83 of the 92 entries on the live page share 2021-04-05 -- the day
         * the documents were bulk-uploaded, not the day the notes were issued.
         * Taking it would write 83 wrong dates into aircraft records.
         */
        foreach ($this->rows() as $row) {
            $this->assertNull($row->issuedAt, $row->number.' must carry no invented date.');
        }

        $this->assertStringContainsString('data-date', $this->fixture(), 'The temptation is real.');
    }

    #[Test]
    public function deadlines_and_recurrence_are_never_invented_here_either(): void
    {
        foreach ($this->rows() as $row) {
            $this->assertNull($row->complyBefore, $row->number);
            $this->assertFalse($row->isRecurring, $row->number);
        }
    }

    #[Test]
    public function the_number_comes_from_the_title_not_the_filename(): void
    {
        /*
         * Run loose over the item, the pattern matches the PDF's filename first --
         * which yielded "LTA-TM-Uebersicht-30.06.2026.pdf" as a number and
         * lower-cased the rest. The number a person quotes is the one in the title.
         */
        foreach ($this->rows() as $row) {
            $this->assertStringNotContainsString('.pdf', $row->number);
            $this->assertStringStartsWith($row->number, $row->title);
        }
    }

    #[Test]
    public function every_row_keeps_its_document_link(): void
    {
        foreach ($this->rows() as $row) {
            $this->assertStringEndsWith('.pdf', (string) $row->referenceUrl, $row->number);
        }
    }

    #[Test]
    public function a_document_listed_twice_yields_one_row(): void
    {
        // Lindner links language variants of the same note; a second row would be
        // a line somebody has to assess twice.
        $numbers = $this->numbers();

        $this->assertSame(count($numbers), count(array_unique($numbers)));
    }

    // ── The spec's shape ────────────────────────────────────────────────────

    #[Test]
    public function it_is_a_list_source_locating_fields_by_pattern(): void
    {
        $spec = $this->spec();

        $this->assertTrue($spec->isList());
        $this->assertTrue($spec->locatesFieldsByPattern());
        $this->assertTrue($spec->isSinglePage());
        $this->assertFalse($spec->needsLogin());
    }

    #[Test]
    public function a_list_spec_needs_no_table_or_cell_patterns(): void
    {
        // Demanding them would force two meaningless entries into every list spec.
        $spec = $this->spec();

        $this->assertSame('', $spec->tablePattern);
        $this->assertSame('', $spec->cellPattern);
        $this->assertNotSame('', $spec->rowPattern);
    }

    #[Test]
    public function a_broken_field_pattern_is_caught_at_load(): void
    {
        // In a list spec the column values ARE patterns, so a typo has to fail
        // here rather than as "this import found nothing".
        $raw = Yaml::parseFile(resource_path('directive-sources/lindner-g103.yaml'));
        $raw['columns']['number'] = '/(unclosed/';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a valid regular expression/');

        SourceSpec::fromArray($raw, 'broken.yaml');
    }

    // ── The sibling specs ───────────────────────────────────────────────────

    #[Test]
    public function every_grob_page_yields_all_but_its_overview(): void
    {
        /*
         * The five Lindner pages together, and the assertion that matters for all
         * of them: exactly one item is skipped, and it is the overview PDF. This
         * is the guard against the failure that nearly shipped -- a pattern that
         * quietly drops real directives looks identical to a short list.
         */
        $pages = [
            'lindner-g103' => 'g-103',
            'lindner-g102' => 'g-102',
            'lindner-g103sl' => 'g-103-sl',
            'lindner-g104' => 'g-104',
            'lindner-phoebus' => 'phoebus',
        ];

        foreach ($pages as $spec => $fixture) {
            $html = file_get_contents(base_path('tests/Fixtures/Lindner/'.$fixture.'.html'));

            $rows = (new ConfiguredSource(
                SourceSpec::fromArray(
                    Yaml::parseFile(resource_path('directive-sources/'.$spec.'.yaml')),
                    $spec.'.yaml',
                ),
                new LindnerStubFetcher,
            ))->parseTypePage($html, '');

            $items = preg_match_all('#<li class="bsk-pdfm-list-item#', $html);

            $this->assertSame($items - 1, count($rows), $spec.' must skip exactly the overview.');
        }
    }

    #[Test]
    public function phoebus_needs_its_own_number_pattern(): void
    {
        /*
         * Precisely why a spec is per PAGE and not per manufacturer. Phoebus
         * titles carry a two-digit list counter before the identifier --
         * "16 252-13 Erhöhung der maximalen Flugmasse" -- and one identifier is a
         * bare "2". The Grob rule (first token, digit AND hyphen) matches none of
         * them, and reusing it would have produced an empty import that looked
         * like a manufacturer with nothing published.
         */
        $rows = (new ConfiguredSource(
            SourceSpec::fromArray(
                Yaml::parseFile(resource_path('directive-sources/lindner-phoebus.yaml')),
                'lindner-phoebus.yaml',
            ),
            new LindnerStubFetcher,
        ))->parseTypePage(
            file_get_contents(base_path('tests/Fixtures/Lindner/phoebus.html')),
            'Phoebus',
        );

        $numbers = array_map(fn (DirectiveRow $r): string => $r->number, $rows);

        $this->assertContains('252-13', $numbers);
        $this->assertContains('27-20-1', $numbers, 'Three-part numbers occur too.');
        $this->assertContains('2', $numbers, 'And one identifier is a bare digit.');

        foreach ($numbers as $number) {
            $this->assertDoesNotMatchRegularExpression(
                '/^\d{2}$/',
                $number,
                'The list counter must never be mistaken for the number.',
            );
        }
    }

    /** @return list<string> */
    private function numbers(): array
    {
        return array_map(fn (DirectiveRow $r): string => $r->number, $this->rows());
    }
}

final class LindnerStubFetcher implements HttpFetcher
{
    public function get(string $url, array $headers = []): string
    {
        return file_get_contents(base_path('tests/Fixtures/Lindner/g-103.html'));
    }
}
