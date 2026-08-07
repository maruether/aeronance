<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Http\HttpFetcher;
use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\Configured\SourceSpec;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The JSON mode -- for manufacturers who do not publish a table.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DG Aviation prompted this mode, and then failed to justify a spec: their
 * endpoint answers 404 to every anonymous request, including the exact call their
 * own JavaScript makes, and their page carries no document content at all for a
 * visitor. So no DG spec ships -- Vorgabe: "wir raten nicht. Niemals."
 *
 * The MODE is real and stays: a manufacturer with an API is an obvious case, and
 * the driver handles one. It is tested against an EXAMPLE spec under
 * tests/Fixtures/Specs, which claims to describe nobody. That distinction is the
 * point: a mechanism can be proven without asserting a fact about a manufacturer.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class JsonSourceTest extends TestCase
{
    /** A spec that DOES require credentials, for the tests about them. */
    private function gatedSpec(): SourceSpec
    {
        $raw = Yaml::parseFile(base_path('tests/Fixtures/Specs/json-beispiel.yaml'));
        $raw['auth'] = 'dg';

        return SourceSpec::fromArray($raw, 'gated.yaml');
    }

    private function spec(array $overrides = []): SourceSpec
    {
        $raw = Yaml::parseFile(base_path('tests/Fixtures/Specs/json-beispiel.yaml'));

        foreach ($overrides as $key => $value) {
            $raw[$key] = $value;
        }

        return SourceSpec::fromArray($raw, 'json-beispiel.yaml');
    }

    private function source(?string $body = null, array $specOverrides = []): ConfiguredSource
    {
        return new ConfiguredSource(
            $this->spec($specOverrides),
            new JsonStubFetcher($body ?? $this->response()),
        );
    }

    private function response(): string
    {
        return json_encode([
            'files' => [
                [
                    'title' => 'TM 300/12',
                    'description' => 'Überprüfung der Höhenruderanlenkung',
                    'created' => '2024-03-14',
                    'link' => 'https://www.dg-aviation.de/download/1/tm-300-12.pdf',
                ],
                [
                    'title' => 'TM 300/13',
                    'description' => 'Einbau eines verstärkten Beschlags',
                    'created' => '15.06.2025',
                    'link' => 'https://www.dg-aviation.de/download/2/tm-300-13.pdf',
                ],
            ],
        ]);
    }

    // ── The mechanism ───────────────────────────────────────────────────────

    #[Test]
    public function rows_come_out_of_a_json_response(): void
    {
        $rows = $this->asLoggedInCustomer(fn (): array => $this->source()->fetch([
            'model' => 'DG-300', 'type_id' => '85',
        ]));

        $this->assertCount(2, $rows);
        $this->assertSame('TM 300/12', $rows[0]->number);
        $this->assertStringContainsString('Höhenruderanlenkung', $rows[0]->title);
        $this->assertSame('DG-300', $rows[0]->subjectModel);
        $this->assertStringContainsString('tm-300-12.pdf', (string) $rows[0]->referenceUrl);
    }

    #[Test]
    public function both_date_notations_are_read(): void
    {
        // ISO from an API, German from a human-entered field -- both occur, and
        // anything else stays empty rather than being guessed.
        $rows = $this->asLoggedInCustomer(fn (): array => $this->source()->fetch([
            'model' => 'DG-300', 'type_id' => '85',
        ]));

        $this->assertSame('2024-03-14', $rows[0]->issuedAt);
        $this->assertSame('2025-06-15', $rows[1]->issuedAt);
    }

    #[Test]
    public function the_domain_rules_are_the_same_as_in_the_table_mode(): void
    {
        // The point of one driver: an unlisted wording is binding here too.
        $spec = $this->spec();

        $this->assertSame(Bindingness::Mandatory, $spec->bindingnessFor(null, ''));
        $this->assertSame(Bindingness::Mandatory, $spec->bindingnessFor(null, 'irgendwas Neues'));
        $this->assertSame(Bindingness::Optional, $spec->bindingnessFor(null, 'wahlweise'));
        $this->assertSame(Bindingness::Mandatory, $spec->bindingnessFor('EASA-AD 2020-1', 'wahlweise'));
    }

    #[Test]
    public function deadlines_and_recurrence_are_still_never_invented(): void
    {
        $rows = $this->asLoggedInCustomer(fn (): array => $this->source()->fetch([
            'model' => 'DG-300', 'type_id' => '85',
        ]));

        foreach ($rows as $row) {
            $this->assertNull($row->complyBefore);
            $this->assertFalse($row->isRecurring);
        }
    }

    #[Test]
    public function an_item_without_a_number_is_skipped(): void
    {
        $body = json_encode(['files' => [
            ['description' => 'Ohne Nummer'],
            ['title' => 'TM 300/14', 'description' => 'Mit Nummer'],
        ]]);

        $rows = $this->asLoggedInCustomer(fn (): array => $this->source($body)->fetch([
            'model' => 'DG-300', 'type_id' => '85',
        ]));

        $this->assertCount(1, $rows);
    }

    #[Test]
    public function a_response_that_is_not_json_says_what_probably_happened(): void
    {
        // A login page instead of data is the likeliest failure, and a bare parse
        // error would send somebody looking in the wrong place.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/did not answer with JSON/');

        $this->asLoggedInCustomer(fn (): array => $this->source('<html>Bitte anmelden</html>')->fetch([
            'model' => 'DG-300', 'type_id' => '85',
        ]));
    }

    #[Test]
    public function a_missing_items_path_yields_nothing_rather_than_failing(): void
    {
        $rows = $this->asLoggedInCustomer(fn (): array => $this->source('{"other":[]}')->fetch([
            'model' => 'DG-300', 'type_id' => '85',
        ]));

        $this->assertSame([], $rows);
    }

    #[Test]
    public function a_request_without_the_manufacturers_type_id_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/type id/');

        $this->asLoggedInCustomer(fn (): array => $this->source()->fetch(['model' => 'DG-300']));
    }

    // ── Credentials, which never live in the spec ───────────────────────────

    #[Test]
    public function no_shipped_spec_carries_a_credential(): void
    {
        /*
         * The property that keeps a manufacturer file shareable: a club can pass
         * its spec to the next club without passing its password, and a spec
         * committed to the repo cannot leak one. Asserted across ALL shipped
         * specs, because the rule is about the format, not about one file.
         */
        foreach (glob(resource_path('directive-sources/*.yaml')) as $file) {
            $raw = Yaml::parseFile($file);

            $this->assertArrayNotHasKey('password', $raw, basename($file));
            $this->assertArrayNotHasKey('user', $raw, basename($file));

            // `auth:` may name a profile; it must never BE a credential.
            if (isset($raw['auth'])) {
                $this->assertIsString($raw['auth'], basename($file));
                $this->assertStringNotContainsString(':', $raw['auth'], basename($file));
            }
        }
    }

    #[Test]
    public function a_source_that_needs_credentials_says_so_before_importing(): void
    {
        // Better than an empty import, which is indistinguishable from a
        // manufacturer with nothing new.
        $source = new ConfiguredSource($this->gatedSpec(), new JsonStubFetcher($this->response()));

        $this->assertFalse($source->isUsable());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DIRECTIVES_DG_USER/');

        $source->fetch(['model' => 'DG-300', 'type_id' => '85']);
    }

    #[Test]
    public function credentials_travel_as_a_header_not_in_the_url(): void
    {
        // A URL ends up in access logs, proxy logs and browser history.
        $fetcher = new RecordingFetcher($this->response());
        $source = new ConfiguredSource($this->gatedSpec(), $fetcher);

        $this->asLoggedInCustomer(fn (): array => $source->fetch(['model' => 'DG-300', 'type_id' => '85']));

        $this->assertArrayHasKey('Authorization', $fetcher->headers);
        $this->assertStringNotContainsString('geheim', $fetcher->url);
        $this->assertStringContainsString('categoryid=85', $fetcher->url);
    }

    #[Test]
    public function a_table_spec_still_needs_its_table_patterns(): void
    {
        /*
         * The modes must not soften each other's requirements. Taken from a spec
         * whose table IS its list: schleicher.yaml describes one too, but as the
         * document library behind its overview sheet, and a spec that does not
         * import a table cannot be refused for describing it incompletely.
         */
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/missing "table_pattern"/');

        $raw = Yaml::parseFile(resource_path('directive-sources/schleicher-allgemein.yaml'));
        unset($raw['page']['table_pattern']);

        SourceSpec::fromArray($raw, 'broken.yaml');
    }

    #[Test]
    public function an_unknown_type_is_refused_with_the_known_ones_named(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Known: table, json/');

        SourceSpec::fromArray($this->rawWith(['type' => 'xml']), 'broken.yaml');
    }

    /** @param array<string, mixed> $overrides */
    private function rawWith(array $overrides): array
    {
        $raw = Yaml::parseFile(base_path('tests/Fixtures/Specs/json-beispiel.yaml'));

        return array_merge($raw, $overrides);
    }

    /**
     * Runs a closure with credentials present in the environment.
     *
     * Named asLoggedInCustomer rather than withCredentials: Laravel's TestCase
     * already has a public withCredentials(), and a private override of it is a
     * fatal error. The third time this project has walked into a helper-name
     * collision with the framework.
     *
     * Set and removed around the call rather than in setUp, because one test has
     * to observe their ABSENCE.
     */
    private function asLoggedInCustomer(callable $callback): mixed
    {
        /*
         * Set in the CONFIG, not just the environment. Credentials are read
         * through config('aeronance.directive_credentials') now, because env()
         * returns null once the config is cached -- which production does on
         * every update. Setting only putenv() here would test a path that no
         * deployed installation uses.
         */
        config(['aeronance.directive_credentials' => [
            'DIRECTIVES_DG_USER' => 'verein',
            'DIRECTIVES_DG_PASSWORD' => 'geheim',
        ]]);

        try {
            return $callback();
        } finally {
            config(['aeronance.directive_credentials' => []]);
        }
    }
}

final class JsonStubFetcher implements HttpFetcher
{
    public function __construct(private readonly string $body) {}

    public function get(string $url, array $headers = []): string
    {
        return $this->body;
    }
}

final class RecordingFetcher implements HttpFetcher
{
    public string $url = '';

    /** @var array<string, string> */
    public array $headers = [];

    public function __construct(private readonly string $body) {}

    public function get(string $url, array $headers = []): string
    {
        $this->url = $url;
        $this->headers = $headers;

        return $this->body;
    }
}
