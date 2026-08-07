<?php

declare(strict_types=1);

namespace App\Modules\Fleet\TypeCertificates\Lba;

use App\Core\Documents\PdfLayoutText;
use App\Core\Http\HttpFetcher;
use App\Modules\Fleet\Models\AircraftType;
use App\Modules\Fleet\TypeCertificates\CertificateSubject;
use App\Modules\Fleet\TypeCertificates\TypeCertificateCandidate;
use App\Modules\Fleet\TypeCertificates\TypeCertificateSource;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * The LBA's "Blaues Buch" -- every aircraft registrable in Germany.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The brief pointed at it, and it is the better source of the two: one document
 * lists the German Kennblatt number AND the EASA reference side by side, where
 * searching EASA's library gets only the second, one type at a time.
 *
 *   339/SP   ASK 21   Schleicher   ASK 21   6 (2/90)   EASA.A.221
 *
 * CACHED HARD, and for a reason beyond speed: each volume is ~450 kB of PDF, and
 * the edition currently published is dated 2021. Fetching and parsing that per
 * keystroke of a search box would be absurd — so a volume is fetched once and the
 * parsed candidates are kept for a month. `refresh()` exists for the day a new
 * edition appears.
 *
 * IT DOES NOT LINK A DATA SHEET. The Blaues Buch is a list, not a set of sheets.
 * What it gives is the pair of numbers, and the EASA reference is what leads to
 * the actual document -- which the EASA source already knows how to fetch. The two
 * adapters are complementary rather than redundant, and the searchable list shows
 * both.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class LbaBlueBookSource implements TypeCertificateSource
{
    /** Long, because the document is dated 2021 and changes by edition. */
    private const CACHE_DAYS = 30;

    public function __construct(
        private readonly HttpFetcher $fetcher,
        private readonly BlueBookParser $parser = new BlueBookParser,
        private readonly PdfLayoutText $text = new PdfLayoutText,
    ) {}

    public function authority(): string
    {
        return AircraftType::AUTHORITY_LBA;
    }

    public function label(): string
    {
        return __('fleet.type.source.lba');
    }

    /**
     * @return list<TypeCertificateCandidate>
     */
    public function search(string $designation, CertificateSubject $subject = CertificateSubject::Aircraft): array
    {
        $needle = $this->normalise($designation);

        if ($needle === '') {
            return [];
        }

        $found = [];

        $volumes = $subject === CertificateSubject::Component
            ? BlueBookCategory::components()
            : BlueBookCategory::forClubs();

        foreach ($volumes as $category) {
            foreach ($this->candidates($category) as $candidate) {
                if (str_contains($this->normalise($candidate->designation), $needle)) {
                    $found[] = $candidate;
                }
            }
        }

        return $found;
    }

    /**
     * Everything one volume lists.
     *
     * @return list<TypeCertificateCandidate>
     */
    public function candidates(BlueBookCategory $category): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = Cache::remember(
            $this->cacheKey($category),
            now()->addDays(self::CACHE_DAYS),
            fn (): array => array_map(
                fn (TypeCertificateCandidate $c): array => [
                    'designation' => $c->designation,
                    'certificate' => $c->certificate,
                    'manufacturer' => $c->manufacturer,
                    'easa' => $c->dataSheetUrl,
                ],
                $this->fetchAndParse($category),
            ),
        );

        return array_map(
            fn (array $row): TypeCertificateCandidate => new TypeCertificateCandidate(
                designation: (string) $row['designation'],
                certificate: (string) $row['certificate'],
                authority: $this->authority(),
                manufacturer: $row['manufacturer'] !== null ? (string) $row['manufacturer'] : null,

                /*
                 * NO SHEET TO LINK. The Blaues Buch is a list, and its own page
                 * is the best address it has. The EASA reference beside the
                 * Kennblatt is a CERTIFICATE NUMBER and is carried as one now --
                 * it used to be squeezed in here, which worked only while a type
                 * could hold a single number.
                 */
                dataSheetUrl: null,
                pageUrl: $category->url(),

                /*
                 * Both numbers of the same aircraft, which is what makes this
                 * source the better of the two: adopting it puts the type on
                 * file under the German number AND the European one, so the
                 * gazette matches whichever it happens to quote.
                 */
                alsoFiledAs: $row['easa'] !== null && (string) $row['easa'] !== ''
                    ? [['number' => (string) $row['easa'], 'authority' => AircraftType::AUTHORITY_EASA]]
                    : [],
            ),
            $rows,
        );
    }

    /**
     * Fetch a volume again, whatever the cache says.
     *
     * For the day a new edition appears -- which is the only time it matters, and
     * nothing here can detect it: the PDF carries an issue date inside its text,
     * not in a header anybody can check cheaply.
     */
    public function refresh(BlueBookCategory $category): int
    {
        Cache::forget($this->cacheKey($category));

        return count($this->candidates($category));
    }

    /**
     * @return list<TypeCertificateCandidate>
     */
    private function fetchAndParse(BlueBookCategory $category): array
    {
        $pdf = $this->fetcher->get($category->url());

        if ($pdf === '' || ! str_starts_with($pdf, '%PDF')) {
            throw new RuntimeException(sprintf(
                'The LBA did not return a PDF for %s.',
                $category->label(),
            ));
        }

        $temp = tempnam(sys_get_temp_dir(), 'bb');

        if ($temp === false) {
            throw new RuntimeException('Could not create a temporary file for the Blaues Buch.');
        }

        try {
            file_put_contents($temp, $pdf);

            $text = $this->text->fromFile($temp);
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }

        $candidates = $this->withEasaReferences($text);

        /*
         * A volume that parses to nothing is a failure, not an empty volume.
         *
         * ─────────────────────────────────────────────────────────────────────
         * Every volume the LBA publishes has entries -- that is what makes it a
         * volume. So zero rows never means "nothing registered"; it means the
         * PDF changed shape, or the text extraction produced something the
         * parser cannot read. Both have happened: the engine and propeller
         * volumes come out of the PDF layer as zero rows today, because their
         * columns are aligned by spacing that the pure-PHP extractor
         * concatenates away.
         *
         * Returning [] there would surface in the search as "kein Treffer" --
         * indistinguishable from a type that genuinely is not listed, and the
         * user would go and type the Kennblatt in by hand while believing they
         * had checked. Refusing loudly is the only honest answer.
         * ─────────────────────────────────────────────────────────────────────
         */
        if ($candidates === []) {
            throw new RuntimeException(sprintf(
                'Aus dem Band "%s" liess sich keine einzige Zeile lesen. Der Band '
                .'ist nicht leer -- entweder hat das LBA das Format geaendert, oder '
                .'die Textextraktion liefert die Spalten nicht mehr getrennt.',
                $category->label(),
            ));
        }

        return $candidates;
    }

    /**
     * Candidates, each carrying the EASA reference from its own row block.
     *
     * The reference has to be read per block rather than per line, because a
     * multi-line row scatters its columns -- the parser's own reason for working
     * in blocks.
     *
     * @return list<TypeCertificateCandidate>
     */
    private function withEasaReferences(string $text): array
    {
        $candidates = $this->parser->parse($text);
        $blocks = $this->blocks($text);

        return array_values(array_map(
            function (TypeCertificateCandidate $candidate) use ($blocks): TypeCertificateCandidate {
                $block = $blocks[$candidate->certificate] ?? '';

                return new TypeCertificateCandidate(
                    designation: $candidate->designation,
                    certificate: $candidate->certificate,
                    authority: $candidate->authority,
                    manufacturer: $candidate->manufacturer,
                    dataSheetUrl: $this->parser->easaReference($block),
                    pageUrl: $candidate->pageUrl,
                );
            },
            $candidates,
        ));
    }

    /**
     * The raw text of each row, keyed by its Kennblatt number.
     *
     * @return array<string, string>
     */
    private function blocks(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?: [];

        $blocks = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('#^\s*(\d{1,5})\s*\t?\s*(/[A-Z]{1,4})\b#u', $line, $m) === 1) {
                $current = $m[1].$m[2];
                $blocks[$current] = $line;

                continue;
            }

            if ($current !== null) {
                $blocks[$current] .= "\n".$line;
            }
        }

        return $blocks;
    }

    private function cacheKey(BlueBookCategory $category): string
    {
        return 'fleet.blue_book.'.$category->value;
    }

    /** Comparison that survives spelling: "ASK-21", "ask 21", "ASK21". */
    private function normalise(string $value): string
    {
        return mb_strtolower(preg_replace('/[^A-Za-z0-9]/u', '', $value) ?? $value);
    }
}
