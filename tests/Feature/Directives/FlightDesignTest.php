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
 * Flight Design, against the saved page.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE BIGGEST SOURCE IN THIS MODULE: 304 documents in 17 tables.
 *
 * It was measured and deliberately NOT built once before. The page is a nested
 * accordion of tabs, and which aircraft a table belongs to is stated nowhere
 * near it -- the tabs are named after the certification basis, not the model.
 * Splitting 304 rows across 17 types out of the nesting would have needed a DOM
 * parser, and a directive on the wrong aircraft is worse than no directive.
 *
 * The answer was in the documents themselves: Flight Design builds its numbers
 * as class-basis-model-serial ("SB-ASTM-CTLS-03"), so every row carries its own
 * model whatever table it sits in. The nesting is not needed at all.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class FlightDesignTest extends TestCase
{
    #[Test]
    public function all_seventeen_tables_are_read(): void
    {
        $rows = $this->rows();

        $this->assertCount(304, $rows);
    }

    #[Test]
    public function the_repeated_table_head_is_not_a_directive(): void
    {
        /*
         * Each of the 17 tables repeats "Download | Date | Subject | …", and
         * that head has six cells just like a data row -- min_cells cannot tell
         * them apart. Read as directives it put 51 rows called "Download" on the
         * list.
         */
        $numbers = array_map(static fn (DirectiveRow $r): string => $r->number, $this->rows());

        $this->assertNotContains('Download', $numbers);
    }

    #[Test]
    public function every_class_of_document_is_present(): void
    {
        /*
         * Safety Alerts, Service Bulletins, Service Notifications and Service
         * Letters are mixed through the same tables. Unlike Aviat -- where the
         * letters sit in a table of their own and are left out -- the class is
         * part of every number here, so it is in front of whoever assesses the
         * row. Filtering would make them silently absent instead.
         */
        $classes = [];

        foreach ($this->rows() as $row) {
            $classes[explode('-', $row->number)[0]] = true;
        }

        $this->assertArrayHasKey('SA', $classes);
        $this->assertArrayHasKey('SB', $classes);
        $this->assertArrayHasKey('SN', $classes);
        $this->assertArrayHasKey('SL', $classes);
    }

    #[Test]
    public function the_date_is_read_day_first_because_the_sheet_proves_it(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * "26-10-2023" is a valid date read either way, and this module leaves
         * such a field EMPTY rather than guess -- that is what C.E.A.P.R. does.
         *
         * Here the sheet answers the question itself: of its 304 dates, 230 have
         * a first number above twelve and not one has a second number above
         * twelve. Day first, proven by the data rather than inferred from where
         * the manufacturer sits. Hence date_order in the spec.
         * ─────────────────────────────────────────────────────────────────────
         */
        $by = [];

        foreach ($this->rows() as $row) {
            $by[$row->number] = $row;
        }

        $this->assertSame('2023-10-26', $by['SA-ASTM-CT-02']->issuedAt);

        foreach ($this->rows() as $row) {
            $this->assertNotNull($row->issuedAt, $row->number.' ohne Datum.');
        }
    }

    #[Test]
    public function every_row_points_at_its_own_document(): void
    {
        foreach ($this->rows() as $row) {
            $this->assertStringEndsWith('.pdf', (string) $row->referenceUrl, $row->number);
        }
    }

    /** @return list<DirectiveRow> */
    private function rows(): array
    {
        $spec = SourceSpec::fromArray(
            Yaml::parseFile(resource_path('directive-sources/flightdesign.yaml')),
            'flightdesign.yaml',
        );

        return (new ConfiguredSource($spec, new SavedFlightDesignPage))->fetch();
    }
}

/** The page as saved from flightdesign.com. */
final class SavedFlightDesignPage implements HttpFetcher
{
    public function get(string $url, array $headers = []): string
    {
        return (string) file_get_contents(__DIR__.'/../../Fixtures/FlightDesign/service-documents.html');
    }
}
