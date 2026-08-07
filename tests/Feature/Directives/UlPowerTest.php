<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Http\HttpFetcher;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\Configured\SourceSpec;
use App\Modules\Directives\Sources\DirectiveRow;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * UL Power Aero Engines, against the saved page.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * AN ENGINE MAKER, which is why it is here despite sitting mostly under
 * microlights: a powerplant directive belongs to the powerplant, and it reaches
 * the aircraft through the component -- the same path as Rotax, Limbach and
 * SOLO.
 *
 * The page is the cleanest this module has met. Every bulletin is its own
 * anchor with the number, the subject and the date each in a div of its own, so
 * nothing has to be recovered from running text.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class UlPowerTest extends TestCase
{
    #[Test]
    public function every_bulletin_on_the_page_is_read(): void
    {
        $rows = $this->rows();

        // Counted against the page: eight numbered 001-008 and fifteen numbered
        // by year, 2016-01 through 2026-001.
        $this->assertCount(23, $rows);

        $numbers = array_map(static fn (DirectiveRow $r): string => $r->number, $rows);

        $this->assertSame('001', $numbers[0]);
        $this->assertContains('2026-001', $numbers);
    }

    #[Test]
    public function a_bulletin_keeps_the_manufacturers_own_wording(): void
    {
        $first = $this->rows()[0];

        /*
         * The page writes "001: Propeller flange bolt locking". The number is
         * taken from in front of the colon and the subject from behind it --
         * NOT from the file name, which is also "001.pdf". A file name is where
         * the manufacturer keeps the document, not a designation anybody
         * quotes: rearrange the folder and the same directive arrives under a
         * new number, sitting beside the old one.
         */
        $this->assertSame('001', $first->number);
        $this->assertSame('Propeller flange bolt locking', $first->title);
        $this->assertSame(DirectiveKind::Sb, $first->kind);
        $this->assertSame(SubjectKind::Engine, $first->subjectKind);
    }

    #[Test]
    public function every_row_carries_its_date(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * ALL 23, and the reason it is asserted for all of them: UL Power writes
         * "Friday 24 September, 2010" -- weekday, then a COMMA between month and
         * year. The reader accepted "23 March 2006" and "June 10, 2026" but not
         * this third shape, so every date came back null while everything else
         * looked perfectly healthy. A source that quietly has no dates is one
         * whose deadlines cannot be computed.
         * ─────────────────────────────────────────────────────────────────────
         */
        $without = array_filter($this->rows(), static fn (DirectiveRow $r): bool => $r->issuedAt === null);

        $this->assertSame([], $without, 'Jede Anweisung trägt ihr Ausgabedatum.');
        $this->assertSame('2010-09-24', $this->rows()[0]->issuedAt);
    }

    #[Test]
    public function the_document_is_the_manufacturers_pdf(): void
    {
        $this->assertStringEndsWith('001.pdf', (string) $this->rows()[0]->referenceUrl);
    }

    /** @return list<DirectiveRow> */
    private function rows(): array
    {
        $spec = SourceSpec::fromArray(
            Yaml::parseFile(resource_path('directive-sources/ulpower.yaml')),
            'ulpower.yaml',
        );

        return (new ConfiguredSource($spec, new SavedUlPowerPage))->fetch();
    }
}

/** The page as saved from ulpower.com. */
final class SavedUlPowerPage implements HttpFetcher
{
    public function get(string $url, array $headers = []): string
    {
        return (string) file_get_contents(__DIR__.'/../../Fixtures/UlPower/service-bulletins.html');
    }
}
