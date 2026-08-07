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
 * Aerospool WT9 Dynamic, against the saved page.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Three tables, three classes of document -- Service Bulletins, Information
 * Bulletins, Recommendations -- and a fourth table on the page that is the
 * cookie notice. The fourth is not excluded by name but falls out on shape: its
 * rows have three cells where a publication row has six.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class AerospoolTest extends TestCase
{
    #[Test]
    public function all_three_publication_tables_are_read(): void
    {
        $numbers = array_map(static fn (DirectiveRow $r): string => $r->number, $this->rows());

        // 53 rows in the three tables, less the three index documents.
        $this->assertCount(50, $numbers);

        $this->assertContains('ZB WT9 01A/2003', $numbers, 'Service Bulletin');
        $this->assertContains('IBWT901/2013', $numbers, 'Information Bulletin');
        $this->assertContains('DVWT9_10B', $numbers, 'Recommendation');
    }

    #[Test]
    public function the_cookie_table_is_not_a_publication(): void
    {
        /*
         * The page ends with the GDPR plugin's cookie table. Read as
         * publications it would put "cookielawinfo-checkbox-analytics" on a
         * club's airworthiness list.
         */
        $numbers = array_map(static fn (DirectiveRow $r): string => $r->number, $this->rows());

        foreach ($numbers as $number) {
            $this->assertStringNotContainsStringIgnoringCase('cookie', $number);
        }
    }

    #[Test]
    public function the_manufacturers_own_index_is_not_a_directive(): void
    {
        // "List of Bulletins" is the table of contents above the table, one per
        // class. Filed as directives, three of the fifty entries would be
        // instructions to read the list one is already reading.
        $titles = array_map(static fn (DirectiveRow $r): string => $r->title, $this->rows());

        $this->assertNotContains('List of Bulletins', $titles);
        $this->assertNotContains('List of Reccomendations', $titles);
    }

    #[Test]
    public function a_bulletin_kept_as_a_zip_still_points_at_its_document(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * Aerospool keeps part of its bulletins as ZIP archives. A pattern for
         * .pdf alone found nothing on those rows, so they fell back to the page
         * address -- a reference that looks like evidence and is not.
         * ─────────────────────────────────────────────────────────────────────
         */
        $by = [];

        foreach ($this->rows() as $row) {
            $by[$row->number] = $row;
        }

        $this->assertStringEndsWith('ZBWT9_02A.zip', (string) $by['ZB WT9 02A/2006']->referenceUrl);
        $this->assertStringEndsWith('ZBWT9_01A.pdf', (string) $by['ZB WT9 01A/2003']->referenceUrl);
    }

    #[Test]
    public function the_slovak_way_of_writing_a_date_is_read(): void
    {
        // "24. 9. 2003" with spaces, "25.1.2007" without -- both on one sheet.
        $by = [];

        foreach ($this->rows() as $row) {
            $by[$row->number] = $row;
        }

        $this->assertSame('2003-09-24', $by['ZB WT9 01A/2003']->issuedAt);
        $this->assertSame('2007-01-25', $by['ZB WT9 03A/2007']->issuedAt);
    }

    /** @return list<DirectiveRow> */
    private function rows(): array
    {
        $spec = SourceSpec::fromArray(
            Yaml::parseFile(resource_path('directive-sources/aerospool.yaml')),
            'aerospool.yaml',
        );

        return (new ConfiguredSource($spec, new SavedAerospoolPage))->fetch();
    }
}

/** The page as saved from aerospool.sk. */
final class SavedAerospoolPage implements HttpFetcher
{
    public function get(string $url, array $headers = []): string
    {
        return (string) file_get_contents(__DIR__.'/../../Fixtures/Aerospool/wt9-publications.html');
    }
}
