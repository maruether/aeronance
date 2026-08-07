<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Http\HttpFetcher;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\Configured\SourceSpec;
use App\Modules\Directives\Sources\UnknownType;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The FAA's airworthiness directives, through the Federal Register.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The first AUTHORITY source in this module. Until now an AD only ever arrived
 * second hand, where a manufacturer happened to cite one in its own column.
 *
 * Not through drs.faa.gov: that is an Angular application, and every route
 * answers HTTP 200 with the same empty shell. A driver pointed at it reports
 * zero rows -- which reads as "the FAA has issued nothing".
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class FaaAdTest extends TestCase
{
    #[Test]
    public function it_reads_the_directives_for_a_model_it_knows(): void
    {
        $fetcher = new RecordingFeed(__DIR__.'/../../Fixtures/FaaAd/piper.json');

        $rows = (new ConfiguredSource($this->spec(), $fetcher))->fetch(['model' => 'PA-18']);

        $this->assertCount(48, $rows);
        $this->assertSame(DirectiveKind::Ad, $rows[0]->kind);
        $this->assertSame('2024-05-22', $rows[0]->issuedAt);
        $this->assertStringStartsWith('https://www.govinfo.gov/', (string) $rows[0]->referenceUrl);
    }

    #[Test]
    public function the_model_chooses_the_search_term(): void
    {
        /*
         * The JSON mode otherwise takes its type id from the caller, which works
         * where a manufacturer hands out ids. An authority does not: it is asked
         * with a search term, and the term that finds a PA-18's directives is
         * the one naming Piper.
         */
        $fetcher = new RecordingFeed(__DIR__.'/../../Fixtures/FaaAd/piper.json');

        (new ConfiguredSource($this->spec(), $fetcher))->fetch(['model' => 'PA-25']);

        $this->assertStringContainsString(
            urlencode('"Airworthiness Directives; Piper Aircraft"'),
            $fetcher->lastUrl,
        );
    }

    #[Test]
    public function the_term_is_quoted_because_an_unquoted_one_finds_too_much(): void
    {
        /*
         * Without the quotes the Federal Register searches the words separately
         * across the full text. Measured against the live API for the PA-18:
         * 178 hits unquoted, 48 quoted -- the difference being rules that merely
         * contain "airworthiness", "directives" and "Piper" somewhere.
         */
        $terms = $this->spec()->endpointTerms;

        foreach ($terms as $model => $term) {
            $this->assertStringStartsWith('"', $term, "Der Suchbegriff für {$model} braucht Anführungszeichen.");
            $this->assertStringEndsWith('"', $term);
        }
    }

    #[Test]
    public function an_unknown_model_is_refused_rather_than_asked_without_a_filter(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * The dangerous alternative would be to ask without a term: thousands of
         * foreign directives would arrive as UNASSESSED lines, and unassessed
         * blocks the release (§3). Refusing by name is the safe half of that
         * choice -- somebody adds a line to the spec, which is a minute's work,
         * instead of a club finding an aircraft it cannot sign off.
         * ─────────────────────────────────────────────────────────────────────
         */
        $this->expectException(RuntimeException::class);

        (new ConfiguredSource($this->spec(), new RecordingFeed(__DIR__.'/../../Fixtures/FaaAd/piper.json')))
            ->fetch(['model' => 'ASK 21']);
    }

    #[Test]
    public function only_final_rules_are_asked_for(): void
    {
        /*
         * A proposed rule is not an instruction. Carried as an open point it
         * would block a release while nothing yet applies.
         */
        $query = $this->spec()->endpointQuery;

        $this->assertSame('RULE', $query['conditions[type][]'] ?? null);
    }

    #[Test]
    public function the_easa_search_refuses_a_model_it_has_no_term_for(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * The same rule as the Federal Register, and the same reason: asking
         * EASA's tool without a keyword returns EVERY airworthiness directive
         * there is. Thousands of foreign ADs arriving as UNASSESSED lines would
         * block the release of an aircraft that none of them concern.
         *
         * Refusing by name is the safe half: somebody adds a line to the spec,
         * which is a minute's work.
         * ─────────────────────────────────────────────────────────────────────
         */
        $spec = SourceSpec::fromArray(
            Yaml::parseFile(resource_path('directive-sources/easa-ad.yaml')),
            'easa-ad.yaml',
        );

        $this->expectException(UnknownType::class);

        (new ConfiguredSource(
            $spec,
            new RecordingFeed(__DIR__.'/../../Fixtures/Easa/ad-suche-schempp.html'),
        ))->fetch(['model' => 'Piper Cub']);
    }

    #[Test]
    public function the_easa_term_for_a_robin_is_not_the_word_robin(): void
    {
        /*
         * "Robin" finds Robinson helicopters. Probed against the live tool: the
         * DR400 came back with "Main Rotor Drive - Clutch Shaft Forward Yoke",
         * which is an R44 directive. A club would have been handed helicopter
         * ADs for its towplane.
         */
        $spec = SourceSpec::fromArray(
            Yaml::parseFile(resource_path('directive-sources/easa-ad.yaml')),
            'easa-ad.yaml',
        );

        $this->assertSame('CEAPR', $spec->termFor('DR400'));
        $this->assertNotSame('Robin', $spec->termFor('DR400'));
    }

    private function spec(): SourceSpec
    {
        return SourceSpec::fromArray(
            Yaml::parseFile(resource_path('directive-sources/faa-ad.yaml')),
            'faa-ad.yaml',
        );
    }
}

/** The saved feed, remembering what it was asked for. */
final class RecordingFeed implements HttpFetcher
{
    public string $lastUrl = '';

    public function __construct(private string $path) {}

    public function get(string $url, array $headers = []): string
    {
        $this->lastUrl = $url;

        return (string) file_get_contents($this->path);
    }
}
