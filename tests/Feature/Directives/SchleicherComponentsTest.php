<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Http\HttpFetcher;
use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\Configured\SourceSpec;
use App\Modules\Directives\Sources\DirectiveRow;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Schleicher's engine and propeller pages.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * These three specs exist separately from schleicher.yaml for one reason, and
 * the last test in this file is that reason.
 *
 * /tm-lta-wa/tm-triebwerke/ looks exactly like the page a person would point a
 * spec at. It is a hub: a list of links and not one table row. Aimed there, the
 * driver gets HTTP 200, finds no table, and returns nothing -- a run that looks
 * perfectly healthy while reporting that Schleicher has published no engine
 * directives at all.
 *
 * Counting rows would not catch it either, which is why each spec is asserted
 * against a number taken from the real page.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SchleicherComponentsTest extends TestCase
{
    #[Test]
    public function the_solo_engine_page_reads(): void
    {
        $rows = $this->rows('schleicher-solo', 'solo');

        $this->assertCount(9, $rows);
        $this->assertSame('TM 4603-15', $rows[0]->number);
        $this->assertSame('Verwendung einer neuen Bezinpumpe', $rows[0]->title);
        $this->assertSame('2014-08-12', $rows[0]->issuedAt);
        $this->assertSame(SubjectKind::Engine, $rows[0]->subjectKind);
    }

    #[Test]
    public function the_rotax_page_reads_and_keeps_the_authority_numbers(): void
    {
        $rows = $this->rows('schleicher-rotax', 'rotax');

        $this->assertCount(13, $rows);

        // Rotax is the one manufacturer here whose table carries LBA numbers in
        // the AD column -- 94-206 and friends. They are the reference an auditor
        // asks by, so losing them would be losing the useful half of the row.
        $withAuthority = array_filter($rows, fn (DirectiveRow $r): bool => filled($r->externalReference));
        $this->assertNotEmpty($withAuthority);
    }

    #[Test]
    public function the_propeller_page_reads(): void
    {
        $rows = $this->rows('schleicher-propeller', 'propeller');

        $this->assertCount(3, $rows);
        $this->assertSame(SubjectKind::Propeller, $rows[0]->subjectKind);

        // Bare numbers -- 1, 2, 3. Schleicher numbers its own propeller notes
        // from one, so "TM 1" here and "TM 1" on an aircraft page are different
        // documents. They stay apart because the source name is part of the key.
        $this->assertSame(['TM 3', 'TM 2', 'TM 1'], array_map(
            fn (DirectiveRow $r): string => $r->number,
            $rows,
        ));
    }

    #[Test]
    public function the_same_number_twice_is_a_real_case(): void
    {
        $numbers = array_map(
            fn (DirectiveRow $r): string => trim($r->number),
            $this->rows('schleicher-rotax', 'rotax'),
        );

        /*
         * TM SB-2ST-000 appears twice on Rotax's page -- once for the 275
         * series, once for the 505 -- with different dates. Both are real
         * directives.
         *
         * Asserted here so the importer's collision reporting is never
         * dismissed as defensive code for a case that does not occur. It occurs
         * on the second manufacturer page this project ever imported.
         */
        $this->assertCount(13, $numbers);
        $this->assertCount(12, array_unique($numbers));
        $this->assertSame(2, count(array_keys($numbers, 'TM SB-2ST-000', true)));
    }

    #[Test]
    public function the_hub_page_yields_nothing_which_is_why_it_is_not_the_url(): void
    {
        /*
         * The trap, made explicit. This is what a spec pointed at
         * /tm-lta-wa/tm-triebwerke/ would produce: no error, no warning, an
         * empty list. Indistinguishable from a manufacturer with nothing new.
         *
         * The test does not assert that this is FINE -- it pins down that the
         * page is empty, so that anybody who later "simplifies" the three specs
         * into one pointed at the hub sees the two dozen directives disappear
         * here rather than in a club's overview.
         */
        $spec = $this->spec('schleicher-solo');
        $source = new ConfiguredSource($spec, new ComponentPageFetcher('triebwerke-hub'));

        $this->assertSame([], $source->fetch());

        // And the leaf page, through the identical code path, is not empty.
        $this->assertNotSame([], (new ConfiguredSource($spec, new ComponentPageFetcher('solo')))->fetch());
    }

    #[Test]
    public function the_general_notes_are_read_at_all(): void
    {
        /*
         * These were missing entirely, and nothing said so.
         *
         * schleicher.yaml walks the type index and filters it with
         * model_filter -- right for "ASK 21" and "ASW 19", and it quietly
         * excluded the one entry on that index which is not a type:
         * /tm-lta-wa/tm-flugzeuge/allgemeine-tm/. Eighteen notes, none of them
         * imported. The filter was doing its job; nobody had asked what it left
         * out.
         */
        $rows = $this->rows('schleicher-allgemein', 'allgemein');

        $this->assertCount(18, $rows);

        /*
         * And being on the general sheet does not by itself make one an offer.
         * Vorgabe: "Wir überprüfen jetzt einfach die generals darauf ob sie
         * mandatory sind. wenn ja -> Normale mandatory tm."
         *
         * Seventeen of these eighteen are binding -- among them the EASA SIB
         * Schleicher passes on -- and filing those as approved data the operator
         * MAY apply would keep every one of them off the aircraft's open points
         * until somebody happened to record it. Exactly one is an offer.
         */
        // 17 der 18 sind verbindlich -- unter anderem die EASA-SIB, die
        // Schleicher weiterreicht. Sie stehen in derselben Liste wie alles
        // andere; die Verbindlichkeit sagt, was sie verlangen.
        $binding = array_filter(
            $rows,
            static fn ($row): bool => $row->bindingness === Bindingness::Mandatory,
        );

        $this->assertCount(17, $binding);
    }

    /** @return list<DirectiveRow> */
    private function rows(string $spec, string $fixture): array
    {
        return (new ConfiguredSource($this->spec($spec), new ComponentPageFetcher($fixture)))->fetch();
    }

    private function spec(string $name): SourceSpec
    {
        return SourceSpec::fromArray(
            Yaml::parseFile(resource_path("directive-sources/{$name}.yaml")),
            "{$name}.yaml",
        );
    }
}

/**
 * The saved page, whatever is asked for.
 *
 * The specs are single-page, so there is exactly one request per fetch and no
 * routing to do.
 */
final class ComponentPageFetcher implements HttpFetcher
{
    public function __construct(private string $fixture) {}

    public function get(string $url, array $headers = []): string
    {
        return (string) file_get_contents(
            __DIR__."/../../Fixtures/Schleicher/{$this->fixture}.html",
        );
    }
}
