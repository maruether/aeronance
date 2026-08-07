<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Http\HttpFetcher;
use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\Configured\SourceSpec;
use App\Modules\Directives\Sources\DirectiveRow;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * BRISTELL / BRM Aero, against the saved page.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The B23 holds an EASA type certificate (EASA.A.642), so this is a certified
 * type and not a microlight -- which is why it is built now rather than left in
 * the UL queue.
 *
 * The richest table this module reads: the manufacturer states the bindingness
 * of every bulletin outright instead of hiding it in a deadline.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class BristellTest extends TestCase
{
    #[Test]
    public function both_tables_are_read_and_not_only_the_first(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * THE ONE THAT NEARLY GOT AWAY. Bristell prints two tables -- the B23 in
         * the first, every other type in the second -- and the second holds 11
         * of the 38 bulletins, four of them safety alerts.
         *
         * The driver read the first table only. Twenty-seven rows came back, the
         * completeness report was empty, and nothing said a whole table had been
         * skipped: a list that looks finished and is missing a third of itself.
         * ─────────────────────────────────────────────────────────────────────
         */
        $numbers = array_map(static fn (DirectiveRow $r): string => $r->number, $this->rows());

        $this->assertCount(38, $numbers);

        // One from each table, so a regression cannot pass by losing either.
        $this->assertContains('B23-SBM-0-0-0-ALL-0005-2026', $numbers);
        $this->assertContains('ALL-SA-3-0-5-0001-2017', $numbers);
    }

    #[Test]
    public function the_manufacturers_own_classification_is_carried_over(): void
    {
        $by = [];

        foreach ($this->rows() as $row) {
            $by[$row->number] = $row;
        }

        $this->assertSame(Bindingness::Mandatory, $by['B23-SBM-0-0-0-ALL-0005-2026']->bindingness);
        $this->assertSame(Bindingness::Recommended, $by['ADxC-73-SB-034']->bindingness);
        $this->assertSame(Bindingness::Optional, $by['ADxC-73-SB-036']->bindingness);
    }

    #[Test]
    public function could_become_ad_stays_binding(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * DELIBERATE, AND THE ONE JUDGEMENT IN THIS SPEC. Bristell's own label
         * says the authority may turn this bulletin into an Airworthiness
         * Directive at any time.
         *
         * Filed as optional it would drop out of view -- precisely the line that
         * is binding tomorrow. The module's asymmetry decides it: read too
         * binding, a line stays on the list; read too optional, it disappears
         * from it.
         * ─────────────────────────────────────────────────────────────────────
         */
        $by = [];

        foreach ($this->rows() as $row) {
            $by[$row->number] = $row;
        }

        $this->assertSame(Bindingness::Mandatory, $by['ADxC-73-SB-041_A']->bindingness);
        $this->assertSame(Bindingness::Mandatory, $by['ADxC-73-SB-037_B']->bindingness);
    }

    #[Test]
    public function every_row_carries_a_date_and_a_document(): void
    {
        foreach ($this->rows() as $row) {
            $this->assertNotNull($row->issuedAt, $row->number.' ohne Datum.');
            $this->assertStringEndsWith('.pdf', (string) $row->referenceUrl, $row->number.' ohne Dokument.');
        }
    }

    /** @return list<DirectiveRow> */
    private function rows(): array
    {
        $spec = SourceSpec::fromArray(
            Yaml::parseFile(resource_path('directive-sources/bristell.yaml')),
            'bristell.yaml',
        );

        return (new ConfiguredSource($spec, new SavedBristellPage))->fetch();
    }
}

/** The page as saved from bristell.com. */
final class SavedBristellPage implements HttpFetcher
{
    public function get(string $url, array $headers = []): string
    {
        return (string) file_get_contents(__DIR__.'/../../Fixtures/Bristell/service-bulletins.html');
    }
}
