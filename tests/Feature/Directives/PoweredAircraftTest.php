<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Http\FormFetcher;
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
 * The powered-aircraft manufacturers, against their real pages.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Every count here was taken from the manufacturer's own page and checked by
 * hand before it was written down. That is the point of the file: a source that
 * quietly returns fewer rows after a site redesign looks exactly like a
 * manufacturer who published nothing new, and only a number that was once
 * correct can tell the difference.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class PoweredAircraftTest extends TestCase
{
    #[Test]
    public function ceapr_reads_the_whole_robin_list(): void
    {
        $rows = $this->rows('ceapr', new SavedFile(__DIR__.'/../../Fixtures/Ceapr/ls-bs-liste.json'));

        $this->assertCount(286, $rows);
        $this->assertSame('SB 090702', $rows[0]->number);
        $this->assertSame(SubjectKind::AircraftModel, $rows[0]->subjectKind);
    }

    #[Test]
    public function ceapr_gives_every_line_a_reachable_document(): void
    {
        /*
         * C.E.A.P.R. hands out the file name and nothing else. Stored as it
         * stands that is a dead reference in all 286 records -- and dead in the
         * quiet way, because the field is filled and looks right until somebody
         * clicks it.
         */
        $rows = $this->rows('ceapr', new SavedFile(__DIR__.'/../../Fixtures/Ceapr/ls-bs-liste.json'));

        $relative = array_filter(
            $rows,
            static fn (DirectiveRow $r): bool => $r->referenceUrl === null
                || ! str_starts_with($r->referenceUrl, 'https://'),
        );

        $this->assertSame([], $relative, 'Jede Zeile braucht eine absolute Dokumentadresse.');
    }

    #[Test]
    public function ceapr_reads_the_bindingness_out_of_the_designation(): void
    {
        /*
         * There is no urgency column at all; the manufacturer writes it into the
         * document's own name, in two spellings ("SB 119 - Mandatory" and
         * "SB 220701R1 MANDATORY"). Counted over the whole list: 102 mandatory,
         * 48 recommended, 7 optional, 129 unmarked -- and unmarked stays binding.
         */
        $rows = $this->rows('ceapr', new SavedFile(__DIR__.'/../../Fixtures/Ceapr/ls-bs-liste.json'));

        $this->assertSame(48, $this->howMany($rows, Bindingness::Recommended));
        $this->assertSame(7, $this->howMany($rows, Bindingness::Optional));
        $this->assertSame(231, $this->howMany($rows, Bindingness::Mandatory));
    }

    #[Test]
    public function ceapr_keeps_the_marker_out_of_the_number(): void
    {
        /*
         * Left in place the number is UNSTABLE: the day C.E.A.P.R. restages a
         * note the string changes, and the import files a second directive
         * instead of updating the first -- with the assessments split between
         * them.
         */
        $rows = $this->rows('ceapr', new SavedFile(__DIR__.'/../../Fixtures/Ceapr/ls-bs-liste.json'));

        $leftover = array_filter(
            $rows,
            static fn (DirectiveRow $r): bool => preg_match('/(mandatory|recommended|optional)/i', $r->number) === 1,
        );

        $this->assertSame([], $leftover);
    }

    #[Test]
    public function ceapr_maps_no_date_at_all(): void
    {
        /*
         * The column is filled on 284 of 286 lines and still unusable: the same
         * wording carries both conventions ("Issue 0 dtd 24/02/2026" is day
         * first, "Issue 1 dtd 10/27/2003" is month first), and on the majority
         * both numbers are <= 12, where the swap would not even show. A wrong
         * issue date is worse than none, because it looks trustworthy.
         */
        $rows = $this->rows('ceapr', new SavedFile(__DIR__.'/../../Fixtures/Ceapr/ls-bs-liste.json'));

        $dated = array_filter($rows, static fn (DirectiveRow $r): bool => filled($r->issuedAt));

        $this->assertSame([], $dated, 'C.E.A.P.R. darf kein Ausgabedatum liefern.');
    }

    #[Test]
    public function zlin_reads_both_language_editions(): void
    {
        /*
         * Neither edition contains the other -- 85 numbers exist only in Czech,
         * 54 only in English -- so a language filter would lose directives in
         * both directions, silently. Same call as DG (docs/LTA-TM.md §13).
         */
        $rows = $this->rows('zlin', new SavedFile(__DIR__.'/../../Fixtures/Zlin/bulletins.html'));

        $this->assertCount(577, $rows);
        $this->assertSame('TM Z143L-31a-Rev.2', $rows[0]->number);
        $this->assertSame('2024-11-25', $rows[0]->issuedAt);
    }

    #[Test]
    public function zlin_links_are_absolute(): void
    {
        // All 577 rows link to "/download/bulletin/<name>.pdf".
        $rows = $this->rows('zlin', new SavedFile(__DIR__.'/../../Fixtures/Zlin/bulletins.html'));

        foreach ($rows as $row) {
            $this->assertStringStartsWith('https://www.zlinaircraft.eu/', (string) $row->referenceUrl);
        }
    }

    #[Test]
    public function zlin_knows_nothing_that_is_not_binding(): void
    {
        /*
         * The type column has exactly two values over all 577 rows, "Závazné"
         * and "Závazné s vlivem na způsobilost". Both are binding; Zlin has no
         * optional category, so inventing phrases for one would be a claim the
         * manufacturer never made.
         */
        $rows = $this->rows('zlin', new SavedFile(__DIR__.'/../../Fixtures/Zlin/bulletins.html'));

        $this->assertSame(577, $this->howMany($rows, Bindingness::Mandatory));
    }

    #[Test]
    public function stemme_reads_and_takes_no_date(): void
    {
        /*
         * The first column looks like a date and is not: "30,12,16" is the list
         * of affected VARIANTS. Reading it as one produced 136 plausible wrong
         * dates. All 379 date divs on the page are empty, so the field stays
         * empty -- the same call as Lindner.
         */
        $rows = $this->rows('stemme', new SavedFile(__DIR__.'/../../Fixtures/Stemme/service.html'));

        $this->assertCount(379, $rows);

        $dated = array_filter($rows, static fn (DirectiveRow $r): bool => filled($r->issuedAt));
        $this->assertSame([], $dated);
    }

    #[Test]
    public function stemme_strips_the_repeated_column_heading(): void
    {
        /*
         * The table is responsive and repeats its heading inside every cell for
         * the narrow layout. Without page.cell_strip the number reads
         * "Date/File SB_914_042" -- a designation Stemme never wrote and that
         * matches no document.
         */
        $rows = $this->rows('stemme', new SavedFile(__DIR__.'/../../Fixtures/Stemme/service.html'));

        $this->assertSame('SB_914_042', $rows[0]->number);

        $withLabel = array_filter(
            $rows,
            static fn (DirectiveRow $r): bool => str_contains($r->number, 'Date/File'),
        );

        $this->assertSame([], $withLabel);
    }

    /**
     * @param  list<DirectiveRow>  $rows
     */
    private function howMany(array $rows, Bindingness $kind): int
    {
        return count(array_filter($rows, static fn (DirectiveRow $r): bool => $r->bindingness === $kind));
    }

    /** @return list<DirectiveRow> */
    private function rows(string $spec, HttpFetcher $fetcher): array
    {
        return (new ConfiguredSource($this->spec($spec), $fetcher))->fetch();
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
 * One saved response, however it is asked for.
 *
 * Answers a POST as well, because C.E.A.P.R.'s endpoint refuses every GET with
 * a 404 -- the double has to be able to do what the real source demands.
 */
final class SavedFile implements FormFetcher, HttpFetcher
{
    public function __construct(private string $path) {}

    public function get(string $url, array $headers = []): string
    {
        return (string) file_get_contents($this->path);
    }

    public function post(string $url, array $form, array $headers = []): string
    {
        return (string) file_get_contents($this->path);
    }
}
