<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Http\HttpFetcher;
use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\Configured\SourceSpec;
use App\Modules\Directives\Sources\DirectiveRow;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * NEUFORM Propeller, against the saved downloads page.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE THINNEST SOURCE IN THIS MODULE. Neuform has no bulletin page: the notes
 * sit under "Downloads" between operating manuals and a questionnaire, and the
 * link text is the file name. No title, no date, no urgency anywhere.
 *
 * Built anyway, because the difference between "four notes, here they are" and
 * "of Neuform we know nothing" is the whole point of this module. What the page
 * does not say stays empty.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class NeuformTest extends TestCase
{
    #[Test]
    public function the_four_notes_are_found_between_the_manuals(): void
    {
        $numbers = array_map(static fn (DirectiveRow $r): string => $r->number, $this->rows());

        $this->assertCount(4, $numbers);
        $this->assertSame(['tm-17-01', 'tm-10-01', 'TM-08-01', 'AA-08-01'], $numbers);
    }

    #[Test]
    public function the_manuals_are_not_directives(): void
    {
        /*
         * Four operating manuals and a technical questionnaire sit in the same
         * list. None carries a TM or AA mark, which is what keeps them out --
         * not their position on the page, which the manufacturer may change.
         */
        $numbers = array_map(static fn (DirectiveRow $r): string => $r->number, $this->rows());

        foreach ($numbers as $number) {
            $this->assertStringNotContainsStringIgnoringCase('handbuch', $number);
            $this->assertStringNotContainsStringIgnoringCase('manual', $number);
            $this->assertStringNotContainsStringIgnoringCase('questionnaire', $number);
        }
    }

    #[Test]
    public function the_list_of_approved_shops_is_not_a_fifth_note(): void
    {
        /*
         * "Autorisierte_Betriebe-TM-08-01.pdf" names the shops allowed to work
         * to TM-08-01. It is an ANNEX to that note, and filed as a directive it
         * would stand on the list as a fifth item nobody can carry out.
         */
        $urls = array_map(static fn (DirectiveRow $r): string => (string) $r->referenceUrl, $this->rows());

        foreach ($urls as $url) {
            $this->assertStringNotContainsStringIgnoringCase('Autorisierte', $url);
        }
    }

    #[Test]
    public function nothing_the_page_does_not_say_is_filled_in(): void
    {
        foreach ($this->rows() as $row) {
            $this->assertNull($row->issuedAt, $row->number.': die Seite nennt kein Datum.');
            $this->assertSame(SubjectKind::Propeller, $row->subjectKind);
            $this->assertStringEndsWith('.pdf', (string) $row->referenceUrl);
        }
    }

    /** @return list<DirectiveRow> */
    private function rows(): array
    {
        $spec = SourceSpec::fromArray(
            Yaml::parseFile(resource_path('directive-sources/neuform.yaml')),
            'neuform.yaml',
        );

        return (new ConfiguredSource($spec, new SavedNeuformPage))->fetch();
    }
}

/** The page as saved from neuform-propeller.de. */
final class SavedNeuformPage implements HttpFetcher
{
    public function get(string $url, array $headers = []): string
    {
        return (string) file_get_contents(__DIR__.'/../../Fixtures/Neuform/downloads.html');
    }
}
