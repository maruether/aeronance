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
 * REMOS, against the saved page.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * A BARE LINK LIST, and the page gives nothing more: number and subject share
 * one string, and there is no date, no urgency and no model anywhere. What can
 * be read is read; what is not there is left empty rather than invented.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class RemosTest extends TestCase
{
    #[Test]
    public function both_classes_of_document_are_read(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * THE POINT OF THIS SOURCE. REMOS publishes Service Bulletins AND
         * Service Directives on one page -- 13 and 6 -- and at REMOS the
         * Directive is the sharper of the two.
         *
         * A pattern written for SB- alone read thirteen rows and reported
         * nothing missing. The list looked complete and was missing precisely
         * the binding documents. That is the failure this module exists to
         * prevent, so it is asserted by count and by both prefixes.
         * ─────────────────────────────────────────────────────────────────────
         */
        $numbers = array_map(static fn (DirectiveRow $r): string => $r->number, $this->rows());

        $this->assertCount(19, $numbers);
        $this->assertContains('SB-013', $numbers);
        $this->assertContains('SD-001', $numbers);
        $this->assertContains('SD-006', $numbers);
    }

    #[Test]
    public function the_number_and_the_subject_are_told_apart(): void
    {
        $first = $this->rows()[0];

        $this->assertSame('SB-013', $first->number);
        $this->assertSame('aircraft-battery', $first->title);
    }

    #[Test]
    public function no_date_is_invented_from_the_upload_path(): void
    {
        /*
         * The URLs carry "/2017/03/", which is when WordPress received the file
         * -- fifteen of nineteen share that month. Reading it as the date of
         * issue would put a manufacturer's stamp on a migration artefact.
         */
        $dated = array_filter($this->rows(), static fn (DirectiveRow $r): bool => $r->issuedAt !== null);

        $this->assertSame([], $dated);
    }

    /** @return list<DirectiveRow> */
    private function rows(): array
    {
        $spec = SourceSpec::fromArray(
            Yaml::parseFile(resource_path('directive-sources/remos.yaml')),
            'remos.yaml',
        );

        return (new ConfiguredSource($spec, new SavedRemosPage))->fetch();
    }
}

/** The page as saved from remos.com. */
final class SavedRemosPage implements HttpFetcher
{
    public function get(string $url, array $headers = []): string
    {
        return (string) file_get_contents(__DIR__.'/../../Fixtures/Remos/service-bulletins.html');
    }
}
