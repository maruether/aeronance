<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\Configured\SourceSpec;
use App\Modules\Directives\Sources\DirectiveRow;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Three more ordinary tables -- and the two ways an ordinary table lies.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Hartzell, Aviat and the Aeroclubul României need no new driver capability at
 * all. What they need is the same care as the rest: both Hartzell and the
 * Aeroclubul put a row in their table that is not a directive, and each does it
 * differently.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ComponentAndTypeClubTest extends TestCase
{
    #[Test]
    public function hartzell_reads_and_leaves_its_own_index_out(): void
    {
        /*
         * The first row of the bulletin table is the INDEX of bulletins, with a
         * date and a document like any other row. Filed as a directive it
         * becomes an open point nobody can carry out.
         */
        $rows = $this->rows('hartzell', 'Hartzell/service-bulletins.html');

        $this->assertCount(73, $rows);
        $this->assertSame('HC-ASB-61-413', $rows[0]->number);
        $this->assertSame('2026-07-08', $rows[0]->issuedAt);
        $this->assertSame(SubjectKind::Propeller, $rows[0]->subjectKind);

        $index = array_filter(
            $rows,
            static fn (DirectiveRow $r): bool => str_contains(strtolower($r->number), 'index'),
        );

        $this->assertSame([], $index);
    }

    #[Test]
    public function aviat_reads_the_bulletins_and_not_the_letters_below_them(): void
    {
        /*
         * Two tables share the page: the bulletins, and underneath them a second
         * one of Service Letters. A greedy table pattern swallows both, and a
         * Service Letter filed as a bulletin claims an obligation the
         * manufacturer did not state.
         */
        $rows = $this->rows('aviat-husky', 'Aviat/husky-service-bulletins.html');

        $this->assertCount(37, $rows);

        $letters = array_filter(
            $rows,
            static fn (DirectiveRow $r): bool => str_contains(strtolower($r->number), 'letter'),
        );

        $this->assertSame([], $letters);
    }

    #[Test]
    public function aviat_takes_no_date_because_there_is_none(): void
    {
        // Bulletin# | Subject | Model | Serial # -- no date column exists.
        $rows = $this->rows('aviat-husky', 'Aviat/husky-service-bulletins.html');

        $dated = array_filter($rows, static fn (DirectiveRow $r): bool => filled($r->issuedAt));

        $this->assertSame([], $dated);
    }

    #[Test]
    public function the_aeroclubul_drops_a_header_that_looks_like_a_row(): void
    {
        /*
         * The header carries six cells exactly like a data row, so min_cells
         * lets it through -- and its "number" is the word SB. Without
         * page.ignore the list holds a directive called "SB" whose subject is
         * "Description".
         */
        $rows = $this->rows('ica-is29d2', 'Ica/is-29-d2.html');

        $this->assertCount(9, $rows);
        $this->assertSame('SB-IS-29D2-EO-01', $rows[0]->number);
        $this->assertSame('1978-07-20', $rows[0]->issuedAt);

        $headers = array_filter($rows, static fn (DirectiveRow $r): bool => $r->number === 'SB');
        $this->assertSame([], $headers);
    }

    /** @return list<DirectiveRow> */
    private function rows(string $spec, string $fixture): array
    {
        return (new ConfiguredSource(
            SourceSpec::fromArray(
                Yaml::parseFile(resource_path("directive-sources/{$spec}.yaml")),
                "{$spec}.yaml",
            ),
            new SavedPage(__DIR__.'/../../Fixtures/'.$fixture),
        ))->fetch();
    }

    #[Test]
    public function hoffmann_propellers_come_in_through_scheibe(): void
    {
        /*
         * Hoffmann's own list is unreachable: their page answers HTTP 200 with
         * zero tables and zero PDF links, and 1.8 MB of shipped JS carries
         * neither the search host nor a key. Scheibe republishes the propeller
         * notes that concern its own types -- with issue date, authority number
         * and a compliance deadline, so more than a bare document list.
         *
         * NOT Hoffmann's complete list, and the spec says so. A partial list
         * mistaken for a whole one is worse than one whose limit is known.
         */
        $rows = $this->rows('hoffmann-propeller', 'HoffmannPropeller/scheibe-hoffmann.html');

        $this->assertCount(2, $rows);
        $this->assertSame('TM SB 4C', $rows[0]->number);
        $this->assertSame('1984-02-20', $rows[0]->issuedAt);
        $this->assertSame(SubjectKind::Propeller, $rows[0]->subjectKind);

        // The number cell carries the date behind a comma; the comma is not
        // part of the number and would make it unstable.
        foreach ($rows as $row) {
            $this->assertStringEndsNotWith(',', $row->number);
        }
    }

    #[Test]
    public function lange_keeps_each_note_with_the_type_it_belongs_to(): void
    {
        /*
         * Lange lists every type in ONE table and separates them with a row of
         * two cells: "E1-Antares (Antares 20E)", its notes, then "Antares 23T".
         * Those rows fall through min_cells -- rightly, they are not directives
         * -- and used to take the only statement of type membership with them:
         * 19 notes of the 20E and three of the 23E arrived indistinguishable.
         */
        $rows = $this->rows('lange', 'Lange/service-bulletins.html');

        $this->assertCount(22, $rows);

        $byModel = [];
        foreach ($rows as $row) {
            $byModel[$row->subjectModel ?? '(ohne)'] = ($byModel[$row->subjectModel ?? '(ohne)'] ?? 0) + 1;
        }

        $this->assertSame(19, $byModel['E1-Antares (Antares 20E)'] ?? 0);
        $this->assertSame(3, $byModel['Antares 23E'] ?? 0);
        $this->assertArrayNotHasKey('(ohne)', $byModel);
    }

    #[Test]
    public function american_champion_reads_a_page_with_no_table_in_it(): void
    {
        /*
         * A Wix repeater: the raw HTML has no <table> at all, only a sequence of
         * elements -- number, subject, link. The component classes (comp-...)
         * change with every rebuild of the site and are useless as an anchor;
         * the ORDER is the content itself.
         *
         * Two number forms: "C-135" from the Champion era beside the bare 400
         * series, which is how the manufacturer shows it still carries the old
         * line.
         */
        $rows = $this->rows('american-champion', 'AmericanChampion/service-letters.html');

        $this->assertCount(45, $rows);

        $numbers = array_map(static fn ($r): string => $r->number, $rows);
        $this->assertContains('C-135', $numbers);
        $this->assertContains('406', $numbers);

        // No date column exists on that page -- the field stays empty rather
        // than borrowing something that looks like one.
        $dated = array_filter($rows, static fn ($r): bool => filled($r->issuedAt));
        $this->assertSame([], $dated);
    }
}
