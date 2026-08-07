<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Http\HttpFetcher;
use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\Configured\SourceSpec;
use App\Modules\Directives\Sources\Configured\SpecRepository;
use App\Modules\Directives\Sources\DirectiveRow;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The config-driven manufacturer adapter.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE POINT OF THIS FILE is that its expectations are the SAME as the ones the
 * hand-written Schleicher adapter had to satisfy, against the SAME saved pages.
 * If a spec plus one generic driver cannot reproduce them, the configuration
 * approach does not work and should not ship.
 *
 * 608 lines of bespoke PHP became a YAML file. What was genuinely
 * manufacturer-specific turned out to be patterns and column indices.
 *
 * WHAT CHANGED WHEN SCHLEICHER MOVED TO ITS OVERVIEW SHEET, and why these tests
 * stayed: the sheet is now where the DIRECTIVES come from -- see
 * OverviewSheetTest -- but the table on the type page is still read, and still
 * has to be. It is the document directory: the only place that says which PDF
 * belongs to which number, which is how a line of the sheet will be linked to
 * its own document. So every expectation below still holds; what it proves has
 * changed from "this is the list" to "this is the index of the files".
 *
 * They are exercised through parseTypePage() rather than fetch(), which is what
 * the linking step will call too.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ConfiguredSourceTest extends TestCase
{
    private function source(): ConfiguredSource
    {
        return new ConfiguredSource($this->spec(), new SpecStubFetcher);
    }

    private function spec(): SourceSpec
    {
        return SourceSpec::fromArray(
            Yaml::parseFile(resource_path('directive-sources/schleicher.yaml')),
            'schleicher.yaml',
        );
    }

    /** @return list<DirectiveRow> */
    private function rows(): array
    {
        return $this->source()->parseTypePage(
            file_get_contents(base_path('tests/Fixtures/Schleicher/ask-21.html')),
            'ASK 21',
        );
    }

    // ── The same expectations as the hand-written adapter ───────────────────

    #[Test]
    public function the_shipped_spec_reads_the_whole_table(): void
    {
        $this->assertGreaterThan(40, count($this->rows()));
    }

    #[Test]
    public function a_plain_tm_comes_through_with_number_date_and_subject(): void
    {
        $tm47 = $this->find('TM 47');

        $this->assertSame('2026-02-01', $tm47->issuedAt);
        $this->assertStringContainsString('Vereinfachung der Wartung', $tm47->title);
        $this->assertSame(DirectiveKind::Tm, $tm47->kind);
        $this->assertSame('ASK 21', $tm47->subjectModel);
        $this->assertStringContainsString('210_TM47_D.pdf', (string) $tm47->referenceUrl);
    }

    #[Test]
    public function optional_wording_still_makes_it_optional(): void
    {
        $this->assertSame(Bindingness::Optional, $this->find('TM 46')->bindingness);
        $this->assertSame(Bindingness::Optional, $this->find('TM 47')->bindingness);
    }

    #[Test]
    public function an_authority_number_still_wins(): void
    {
        $tm38 = $this->find('TM 38');

        $this->assertSame(Bindingness::Mandatory, $tm38->bindingness);
        $this->assertSame('EASA-AD 2016-0192', $tm38->externalReference);
    }

    #[Test]
    public function a_mixed_wording_with_a_hard_moment_still_stays_mandatory(): void
    {
        $mixed = array_values(array_filter(
            $this->rows(),
            fn (DirectiveRow $r): bool => str_contains((string) $r->summary, 'vor dem nächsten Flug')
                && str_contains((string) $r->summary, 'wahlweise'),
        ));

        $this->assertNotEmpty($mixed);

        foreach ($mixed as $row) {
            $this->assertSame(Bindingness::Mandatory, $row->bindingness, $row->number);
        }
    }

    #[Test]
    public function serial_ranges_and_prose_behave_as_before(): void
    {
        $this->assertSame('21001', $this->find('TM 26')->serialFrom);
        $this->assertSame('21205', $this->find('TM 26')->serialTo);
        $this->assertNull($this->find('TM 38')->serialFrom, 'Prose is not a range.');
    }

    #[Test]
    public function deadlines_and_recurrence_are_still_never_invented(): void
    {
        foreach ($this->rows() as $row) {
            $this->assertNull($row->complyBefore, $row->number);
            $this->assertFalse($row->isRecurring, $row->number);
        }
    }

    #[Test]
    public function the_index_still_yields_the_type_pages(): void
    {
        $types = $this->source()->types();

        $this->assertGreaterThan(30, count($types));
        $this->assertArrayHasKey('ASK 21', $types);
        $this->assertArrayNotHasKey('Propeller', $types);
    }

    #[Test]
    public function the_overview_link_is_captured(): void
    {
        // Was "die reichen und können zum hersteller verlinken". It is now the
        // address the directives themselves are read from -- the same link, one
        // step further: OverviewSheetTest reads what is behind it.
        $url = $this->source()->overviewUrl(
            file_get_contents(base_path('tests/Fixtures/Schleicher/ask-21.html')),
        );

        $this->assertStringContainsString('_TM_UE_D.pdf', (string) $url);
    }

    #[Test]
    public function the_table_still_names_the_document_behind_each_number(): void
    {
        /*
         * Why the table stays described in a spec that no longer imports it: the
         * overview sheet carries the obligations but not a single link, and the
         * table carries a link per number. Joining the two is the next step, and
         * it needs this half to keep working.
         */
        $rows = $this->rows();
        $withDocument = array_filter(
            $rows,
            static fn (DirectiveRow $r): bool => str_contains((string) $r->referenceUrl, '.pdf'),
        );

        $this->assertGreaterThan(40, count($withDocument));
        $this->assertStringContainsString('210_TM47_D.pdf', (string) $this->find('TM 47')->referenceUrl);
    }

    // ── The spec format itself ──────────────────────────────────────────────

    #[Test]
    public function a_broken_regex_is_refused_at_load_not_at_first_use(): void
    {
        // Otherwise it surfaces as "this import found nothing", which looks
        // exactly like a manufacturer who published nothing new.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a valid regular expression/');

        SourceSpec::fromArray($this->rawSpec(['index' => ['link_pattern' => '#(unclosed#']]), 'broken.yaml');
    }

    #[Test]
    public function a_table_spec_without_a_number_column_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must map a "number" column/');

        // Taken from a spec that still reads a table: schleicher.yaml reads the
        // overview sheet now, and there the columns are found by their headings
        // in the document rather than counted in advance.
        $raw = Yaml::parseFile(resource_path('directive-sources/schleicher-allgemein.yaml'));
        $raw['columns'] = ['title' => 4];   // replaced, not merged

        SourceSpec::fromArray($raw, 'broken.yaml');
    }

    #[Test]
    public function an_overview_spec_without_a_number_pattern_is_refused(): void
    {
        // The same guard for the sheet mode. What a directive number looks like
        // is the one thing the document cannot say for itself, and without it
        // every line would be refused -- in silence.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/overview\.number_pattern/');

        $raw = $this->rawSpec();
        unset($raw['overview']['number_pattern']);

        SourceSpec::fromArray($raw, 'broken.yaml');
    }

    #[Test]
    public function an_overview_spec_without_a_heading_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/"title" heading/');

        $raw = $this->rawSpec();
        unset($raw['overview']['headings']['title']);

        SourceSpec::fromArray($raw, 'broken.yaml');
    }

    #[Test]
    public function an_overview_spec_that_never_says_where_the_sheet_is_gets_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/where to find one/');

        $raw = $this->rawSpec();
        unset($raw['page']['overview_pattern']);

        SourceSpec::fromArray($raw, 'broken.yaml');
    }

    #[Test]
    public function a_spec_missing_a_required_section_says_which(): void
    {
        $raw = $this->rawSpec();
        unset($raw['index']['url']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/missing "url"/');

        SourceSpec::fromArray($raw, 'broken.yaml');
    }

    #[Test]
    public function an_empty_urgency_column_is_binding_and_an_unknown_wording_too(): void
    {
        // The asymmetry, now expressed in configuration: only listed phrases make
        // a line optional.
        $spec = $this->spec();

        $this->assertSame(Bindingness::Mandatory, $spec->bindingnessFor(null, ''));
        $this->assertSame(Bindingness::Mandatory, $spec->bindingnessFor(null, 'keine'));
        $this->assertSame(Bindingness::Mandatory, $spec->bindingnessFor(null, 'nur bei Vollmond'));
        $this->assertSame(Bindingness::Optional, $spec->bindingnessFor(null, 'wahlweise'));
    }

    // ── The repository ──────────────────────────────────────────────────────

    #[Test]
    public function the_shipped_directory_yields_the_schleicher_spec(): void
    {
        $repository = new SpecRepository(resource_path('directive-sources'), storage_path('app/nonexistent'));

        $this->assertArrayHasKey('schleicher', $repository->all());
        $this->assertSame([], $repository->problems(), 'A shipped spec must never be broken.');
    }

    #[Test]
    public function a_local_spec_wins_over_a_shipped_one(): void
    {
        /*
         * The reason the two directories exist: when a manufacturer redesigns
         * their site mid-release-cycle, a club fixes it that afternoon instead of
         * waiting -- and the fix survives the update that ships the same repair.
         */
        $local = sys_get_temp_dir().'/aeronance-specs-'.bin2hex(random_bytes(4));
        mkdir($local, 0700, true);

        $raw = Yaml::parseFile(resource_path('directive-sources/schleicher.yaml'));
        $raw['label'] = 'Vom Verein repariert';
        file_put_contents($local.'/schleicher.yaml', Yaml::dump($raw, 6));

        try {
            $repository = new SpecRepository(resource_path('directive-sources'), $local);

            $this->assertSame('Vom Verein repariert', $repository->all()['schleicher']->label);
        } finally {
            @unlink($local.'/schleicher.yaml');
            @rmdir($local);
        }
    }

    #[Test]
    public function one_broken_file_does_not_take_the_others_down(): void
    {
        $local = sys_get_temp_dir().'/aeronance-specs-'.bin2hex(random_bytes(4));
        mkdir($local, 0700, true);

        file_put_contents($local.'/kaputt.yaml', "name: kaputt\nindex:\n  url: 'x'\n");

        try {
            $repository = new SpecRepository(resource_path('directive-sources'), $local);

            $this->assertArrayHasKey('schleicher', $repository->all(), 'The working one still loads.');
            $this->assertArrayNotHasKey('kaputt', $repository->all());

            // And the skip is reported, because a silently missing source looks
            // exactly like a manufacturer with nothing new.
            $this->assertArrayHasKey('kaputt.yaml', $repository->problems());
        } finally {
            @unlink($local.'/kaputt.yaml');
            @rmdir($local);
        }
    }

    #[Test]
    public function a_missing_local_directory_is_simply_no_local_specs(): void
    {
        $repository = new SpecRepository(resource_path('directive-sources'), '/definitely/not/here');

        $this->assertNotEmpty($repository->all());
    }

    /** @param array<string, mixed> $overrides */
    private function rawSpec(array $overrides = []): array
    {
        $raw = Yaml::parseFile(resource_path('directive-sources/schleicher.yaml'));

        foreach ($overrides as $key => $value) {
            $raw[$key] = is_array($value) && isset($raw[$key]) && is_array($raw[$key])
                ? array_merge($raw[$key], $value)
                : $value;
        }

        return $raw;
    }

    private function find(string $number): DirectiveRow
    {
        foreach ($this->rows() as $row) {
            if ($row->number === $number) {
                return $row;
            }
        }

        $this->fail(sprintf('No row %s in the fixture.', $number));
    }
}

final class SpecStubFetcher implements HttpFetcher
{
    public function get(string $url, array $headers = []): string
    {
        $file = str_contains($url, 'ask-21') || str_contains($url, 'ask21')
            ? 'ask-21.html'
            : 'index.html';

        return file_get_contents(base_path('tests/Fixtures/Schleicher/'.$file));
    }
}
