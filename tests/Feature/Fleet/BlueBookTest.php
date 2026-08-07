<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Core\Http\HttpFetcher;
use App\Modules\Fleet\Models\AircraftType;
use App\Modules\Fleet\TypeCertificates\Lba\BlueBookCategory;
use App\Modules\Fleet\TypeCertificates\Lba\BlueBookParser;
use App\Modules\Fleet\TypeCertificates\Lba\LbaBlueBookSource;
use App\Modules\Fleet\TypeCertificates\TypeCertificateCandidate;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The LBA's Blaues Buch, against the real document.
 *
 * The fixture is 04_segel.pdf as published -- an official work of a German
 * authority (§5 UrhG), so committing it is fine, and it is the only way to test
 * the whole chain including the PDF extraction.
 *
 * That chain is where the surprises live. Rows span up to four lines, hyphenated
 * designations arrive split ("SZD" + "-48-1"), and the EASA column has at least
 * four legitimate notations plus one typo. Markup or text I wrote myself would
 * have had none of it.
 */
final class BlueBookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function source(): LbaBlueBookSource
    {
        return new LbaBlueBookSource(new BlueBookStubFetcher);
    }

    // ── The parser ──────────────────────────────────────────────────────────

    #[Test]
    public function it_reads_the_glider_volume(): void
    {
        $candidates = $this->source()->candidates(BlueBookCategory::Gliders);

        // ~156 types in the sailplane volume.
        $this->assertGreaterThan(100, count($candidates));
        $this->assertContainsOnlyInstancesOf(TypeCertificateCandidate::class, $candidates);
    }

    #[Test]
    public function the_ask_21_row_yields_both_numbers(): void
    {
        // The whole reason this source is better than searching EASA per type:
        // 339/SP   ASK 21   Schleicher   ASK 21   6 (2/90)   EASA.A.221
        $ask21 = $this->find('ASK 21');

        $this->assertSame('339/SP', $ask21->certificate);
        $this->assertSame(AircraftType::AUTHORITY_LBA, $ask21->authority);
        $this->assertSame('Schleicher', $ask21->manufacturer);
        /*
         * The EASA reference is a CERTIFICATE NUMBER and is carried as one. It
         * used to travel in dataSheetUrl, which held while a type could store a
         * single number -- see AircraftTypeCertificate.
         */
        $this->assertSame(
            [['number' => 'EASA.A.221', 'authority' => AircraftType::AUTHORITY_EASA]],
            $ask21->alsoFiledAs,
        );
        $this->assertNull($ask21->dataSheetUrl, 'Das Blaue Buch verlinkt kein Datenblatt.');
    }

    #[Test]
    public function a_split_hyphenated_designation_is_put_back_together(): void
    {
        // The extractor splits "SZD-48-1" into "SZD" and "-48-1" with a tab. The
        // column pieces are joined with nothing, which is what repairs it.
        $pik = $this->find('PIK-20D');

        $this->assertSame('PIK-20D', $pik->designation);
        $this->assertSame('330/SP', $pik->certificate);
    }

    #[Test]
    public function a_multi_line_row_still_finds_its_easa_reference(): void
    {
        // Row 338/SP wraps its manufacturer over two lines and puts the EASA
        // number on the second -- which is why the reference is read per block,
        // not per line.
        $is28 = $this->find('IS-28 B2');

        $this->assertSame('338/SP', $is28->certificate);
        $this->assertSame('EASA.A.453', $is28->alsoFiledAs[0]['number']);
    }

    #[Test]
    public function the_page_header_is_not_mistaken_for_a_type(): void
    {
        // It repeats on every page of the PDF.
        foreach ($this->source()->candidates(BlueBookCategory::Gliders) as $candidate) {
            $this->assertDoesNotMatchRegularExpression(
                '/^(Kennblatt|TCDS|Gerät|Type of)/ui',
                $candidate->designation,
            );
        }
    }

    #[Test]
    public function a_type_without_an_easa_number_simply_has_none(): void
    {
        // Amateur-built and Annex I types carry no EASA reference, and inventing
        // one would be worse than an empty field.
        $withoutEasa = array_filter(
            $this->source()->candidates(BlueBookCategory::Gliders),
            fn (TypeCertificateCandidate $c): bool => $c->dataSheetUrl === null,
        );

        $this->assertNotEmpty($withoutEasa);
    }

    // ── The EASA notations, all of them real ─────────────────────────────────

    #[Test]
    public function every_notation_the_document_uses_is_read_correctly(): void
    {
        /*
         * Four forms and one typo, all taken from the actual document. The first
         * version of this normaliser flattened everything and re-inserted a dot
         * per letter, turning EASA.SAS.A.024 into EASASAS.A.024 -- inventing
         * structure the document already had right.
         */
        $parser = new BlueBookParser;

        $cases = [
            "339\t/SP\t \tASK 21\t \tSchleicher\t \tEASA.A.221" => 'EASA.A.221',
            "330\t/SP\t \tPIK\t-20D\t \tEASA S\tAS.A.024" => 'EASA.SAS.A.024',
            "xxx\t \tH 101\t \tEASA.SAS.A.028" => 'EASA.SAS.A.028',
            "xxx\t \tFoo\t \tEASA A.038" => 'EASA.A.038',
        ];

        foreach ($cases as $block => $expected) {
            $this->assertSame($expected, $parser->easaReference($block), $block);
        }
    }

    #[Test]
    public function a_row_with_no_easa_number_returns_null(): void
    {
        $this->assertNull(
            (new BlueBookParser)->easaReference("11\t/SP\t \tZögling\t \tAmateurbau\t \tAnhang I (D)"),
        );
    }

    // ── Searching ───────────────────────────────────────────────────────────

    #[Test]
    public function a_search_survives_spelling(): void
    {
        foreach (['ASK 21', 'ask-21', 'ASK21', ' ask 21 '] as $term) {
            $hits = array_map(
                fn (TypeCertificateCandidate $c): string => $c->certificate,
                $this->source()->search($term),
            );

            $this->assertContains('339/SP', $hits, $term);
        }
    }

    #[Test]
    public function a_search_for_nothing_finds_nothing(): void
    {
        $this->assertSame([], $this->source()->search('   '));
    }

    // ── Caching, which is not optional here ─────────────────────────────────

    #[Test]
    public function a_volume_is_fetched_once_and_kept(): void
    {
        // 450 kB of PDF per keystroke of a search box would be absurd, and the
        // published edition is dated 2021.
        $fetcher = new CountingFetcher;
        $source = new LbaBlueBookSource($fetcher);

        $source->search('ASK 21');
        $source->search('ASK 23');
        $source->candidates(BlueBookCategory::Gliders);

        $this->assertSame(
            1,
            $fetcher->countFor('04_segel'),
            'The glider volume must be fetched exactly once.',
        );
    }

    #[Test]
    public function refresh_fetches_again(): void
    {
        $fetcher = new CountingFetcher;
        $source = new LbaBlueBookSource($fetcher);

        $source->candidates(BlueBookCategory::Gliders);
        $count = $source->refresh(BlueBookCategory::Gliders);

        $this->assertSame(2, $fetcher->countFor('04_segel'));
        $this->assertGreaterThan(100, $count);
    }

    #[Test]
    public function something_that_is_not_a_pdf_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/did not return a PDF/');

        (new LbaBlueBookSource(new NotAPdfFetcher))->candidates(BlueBookCategory::Gliders);
    }

    #[Test]
    public function a_volume_that_reads_as_empty_is_refused(): void
    {
        /*
         * The failure this project keeps guarding against: a search that finds
         * nothing looks identical whether the type is unlisted or the whole
         * volume failed to parse. In the second case the user types the
         * Kennblatt in by hand, believing they checked.
         *
         * Every LBA volume has entries -- that is what makes it a volume. Zero
         * is therefore always a fault, never an answer.
         */
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/keine einzige Zeile/');

        (new LbaBlueBookSource(new UnreadablePdfFetcher))->candidates(BlueBookCategory::Gliders);
    }

    #[Test]
    public function the_engine_volume_reads_completely(): void
    {
        /*
         * The volume that was declared unreadable for weeks. It was the
         * extractor, not the document: 08_1_motore.pdf aligns its columns by
         * space padding, and a parser reading text objects in content order
         * turns "4505/EN  Walter Mikron III" into "4505/ENW alter Mikron III".
         *
         * 157 is counted from the document, not chosen as "plausibly many". An
         * earlier version of the number pattern forbade a letter on the number
         * and read 151 -- six engines short, in complete silence, because a row
         * that fails the pattern is simply not a row.
         */
        $candidates = (new LbaBlueBookSource(new EngineVolumeFetcher))
            ->candidates(BlueBookCategory::Engines);

        $this->assertCount(157, $candidates);

        $numbers = array_map(fn ($c): string => $c->certificate, $candidates);
        $this->assertContains('4509A/EN', $numbers);

        $porsche = collect($candidates)->firstWhere('certificate', '4502/EN');
        $this->assertSame('Porsche 678', $porsche->designation);

        // Not "Dr. Ing. H.c. F. Porsche KG678/1" and not "Dr." -- the whole cell,
        // and nothing from the cell beside it.
        $this->assertSame('Dr. Ing. H.c. F. Porsche KG', $porsche->manufacturer);
    }

    #[Test]
    public function a_wrapped_cell_is_stitched_back_to_its_own_column(): void
    {
        /*
         * 53/SP wraps its manufacturer over three lines and 103/SP carries a
         * SECOND variant on its continuation line. Reading only the row line
         * gives "Deutsche"; appending the whole continuation gives a
         * manufacturer with a model number in it. Both were real behaviours of
         * earlier versions.
         *
         * Matching on the starting column is what makes -layout worth an
         * external binary -- it is the only extraction that still knows where a
         * piece of text sat.
         */
        $candidates = (new LbaBlueBookSource(new CountingFetcher))
            ->candidates(BlueBookCategory::Gliders);

        $by = collect($candidates)->keyBy('certificate');

        $this->assertSame(
            'Deutsche Forschungsanstalt für Segelflug e.V.',
            $by['53/SP']->manufacturer,
        );

        $this->assertSame('Luftsportgem. Wolfenb.-Salzg.', $by['103/SP']->manufacturer);

        // And the short ones are untouched -- a stitching rule that "improves"
        // rows that were already right is the rule that broke SF 34 into SF 34 B.
        $this->assertSame('Schleicher', $by['339/SP']->manufacturer);
    }

    #[Test]
    public function the_club_volumes_are_the_ones_a_gliding_club_needs(): void
    {
        // Sailplanes, powered sailplanes, and the tug. The over-2-tonne volume is
        // a large document that would only slow these clubs' searches down.
        $this->assertSame(
            [BlueBookCategory::Gliders, BlueBookCategory::PoweredSailplanes, BlueBookCategory::AeroplanesUpTo2t],
            BlueBookCategory::forClubs(),
        );
    }

    private function find(string $designation): TypeCertificateCandidate
    {
        foreach ($this->source()->candidates(BlueBookCategory::Gliders) as $candidate) {
            if ($candidate->designation === $designation) {
                return $candidate;
            }
        }

        $this->fail(sprintf('No type "%s" in the fixture.', $designation));
    }
}

/**
 * The glider volume for every category -- the fixture is one volume, and the
 * tests only ask about gliders.
 */
final class BlueBookStubFetcher implements HttpFetcher
{
    public function get(string $url, array $headers = []): string
    {
        return file_get_contents(base_path('tests/Fixtures/Lba/blaues-buch-segel.pdf'));
    }
}

final class CountingFetcher implements HttpFetcher
{
    /** @var array<string, int> */
    private array $calls = [];

    public function get(string $url, array $headers = []): string
    {
        foreach (['04_segel', '05_motorsegel', '01_lfz_2t', '02_lfz_ue_2t'] as $key) {
            if (str_contains($url, $key)) {
                $this->calls[$key] = ($this->calls[$key] ?? 0) + 1;
            }
        }

        return file_get_contents(base_path('tests/Fixtures/Lba/blaues-buch-segel.pdf'));
    }

    public function countFor(string $key): int
    {
        return $this->calls[$key] ?? 0;
    }
}

/**
 * The LBA's engine volume, saved.
 *
 * A component volume in the tests at all because "cannot be read" was asserted
 * about this exact file, in a comment, for weeks.
 */
final class EngineVolumeFetcher implements HttpFetcher
{
    public function get(string $url, array $headers = []): string
    {
        return (string) file_get_contents(__DIR__.'/../../Fixtures/Lba/blaues-buch-motoren.pdf');
    }
}

final class NotAPdfFetcher implements HttpFetcher
{
    public function get(string $url, array $headers = []): string
    {
        return '<html><body>Wartungsarbeiten</body></html>';
    }
}

/**
 * A valid PDF that is not a Blaues Buch.
 *
 * What a volume looks like after the LBA reorganises it, or when a maintenance
 * page is served with a PDF content type: the file opens, the parser finds no
 * row, and without the guard the search reports "kein Treffer" for every type
 * in Germany.
 *
 * A file rather than a string, because the extractor is a real binary now and
 * hand-rolled bytes have to survive it.
 */
final class UnreadablePdfFetcher implements HttpFetcher
{
    public function get(string $url, array $headers = []): string
    {
        return (string) file_get_contents(__DIR__.'/../../Fixtures/Lba/ohne-tabelle.pdf');
    }
}
