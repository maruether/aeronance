<?php

declare(strict_types=1);

namespace App\Modules\Fleet\TypeCertificates;

use App\Core\Http\HttpFetcher;
use App\Modules\Fleet\Models\AircraftType;

/**
 * EASA's type certificate library.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Verified before writing a line of it: the library search answers over plain
 * HTTP without credentials, a type page carries its certificate number in the
 * title ("EASA.A.221 — Schleicher ASK 21"), and the document link answers
 * `application/pdf`. That is what makes "automatic" an honest word here.
 *
 * The network seam is the core's. It used to be the directives module's, and the
 * note that stood here called that "a dependency the wrong way round" and left
 * it -- which it was: the fleet is a base module declaring no requirement at
 * all, so an installation with directives switched off had a fleet that could
 * not resolve its own fetcher. HttpFetcher lives in App\Core\Http now, which
 * both modules borrow from and neither owns.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class EasaSource implements TypeCertificateSource
{
    private const SEARCH_URL = 'https://www.easa.europa.eu/en/document-library/type-certificates?search=';

    private const BASE = 'https://www.easa.europa.eu';

    public function __construct(private readonly HttpFetcher $fetcher) {}

    public function authority(): string
    {
        return AircraftType::AUTHORITY_EASA;
    }

    public function label(): string
    {
        return __('fleet.type.source.easa');
    }

    /** @return list<TypeCertificateCandidate> */
    public function search(string $designation, CertificateSubject $subject = CertificateSubject::Aircraft): array
    {
        /*
         * EASA certifies engines and propellers too, but this adapter reads the
         * aircraft library and its slugs. Answering a component search from it
         * would return aircraft whose names happen to match -- worse than an
         * honest nothing, because the Blaues Buch beside it does have the answer.
         */
        if ($subject === CertificateSubject::Component) {
            return [];
        }

        $term = trim($designation);

        if ($term === '') {
            return [];
        }

        $html = $this->fetcher->get(self::SEARCH_URL.rawurlencode($term));

        $found = [];

        /*
         * Result links look like
         *   /en/document-library/type-certificates/<category>/easaa221-schleicher-ask
         * and the slug carries the certificate number. The library also renders
         * "popular links" -- Airbus, Boeing -- on every page; those are filtered
         * by the ?tcds-popular-links marker they carry.
         */
        preg_match_all(
            '#href="(/(?:en/)?document-library/type-certificates/[^"?]*?/(easa[a-z]*\d+[^"?/]*))"#i',
            $html,
            $matches,
            PREG_SET_ORDER,
        );

        $seen = [];

        foreach ($matches as $m) {
            $path = $m[1];
            $slug = $m[2];

            $certificate = $this->certificateFromSlug($slug);

            if ($certificate === null || isset($seen[$certificate])) {
                continue;
            }

            $seen[$certificate] = true;

            $found[] = new TypeCertificateCandidate(
                designation: $this->designationFromSlug($slug, $certificate),
                certificate: $certificate,
                authority: $this->authority(),
                dataSheetUrl: null,
                pageUrl: self::BASE.$path,
            );
        }

        return $found;
    }

    /**
     * The details behind one candidate.
     *
     * Split from the search deliberately: a search over a common word returns
     * many hits, and fetching a page for each would be dozens of requests to
     * somebody else's server for rows nobody will pick. This is called once, for
     * the one the person chose.
     */
    public function resolve(TypeCertificateCandidate $candidate): TypeCertificateCandidate
    {
        if ($candidate->pageUrl === null) {
            return $candidate;
        }

        $html = $this->fetcher->get($candidate->pageUrl);

        return new TypeCertificateCandidate(
            designation: $this->titleDesignation($html) ?? $candidate->designation,
            certificate: $candidate->certificate,
            authority: $candidate->authority,
            manufacturer: $candidate->manufacturer,
            dataSheetUrl: $this->documentUrl($html),
            pageUrl: $candidate->pageUrl,
        );
    }

    /**
     * "easaa221-schleicher-ask" -> "EASA.A.221".
     *
     * The slug flattens the dots the authority writes, so they are put back:
     * a number stored as "easaa221" is not the number anybody reads off a
     * document.
     */
    private function certificateFromSlug(string $slug): ?string
    {
        if (preg_match('/^easa([a-z]*)(\d+)/i', $slug, $m) !== 1) {
            return null;
        }

        $letters = strtoupper($m[1]);
        $number = $m[2];

        // easaa221 -> A.221 ; easaima120 -> IM.A.120 ; easae121 -> E.121
        $parts = $letters === '' ? [] : ($letters === 'IMA' ? ['IM', 'A'] : [$letters]);

        return 'EASA.'.($parts === [] ? '' : implode('.', $parts).'.').$number;
    }

    private function designationFromSlug(string $slug, string $certificate): string
    {
        $rest = preg_replace('/^easa[a-z]*\d+-?/i', '', $slug) ?? '';
        $rest = str_replace('-', ' ', $rest);

        return $rest === '' ? $certificate : ucwords($rest);
    }

    /**
     * The title is where the authority states both, joined by an em dash.
     */
    private function titleDesignation(string $html): ?string
    {
        if (preg_match('#<title>(.*?)</title>#is', $html, $m) !== 1) {
            return null;
        }

        $title = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = preg_replace('/\s*\|\s*EASA\s*$/u', '', $title) ?? $title;

        if (preg_match('/^EASA\.[A-Z0-9.]+\s*[—–-]\s*(.+)$/u', $title, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * The document link.
     *
     * EASA serves documents from /en/downloads/<id>/en rather than as a .pdf
     * path, which is why looking for an extension finds nothing -- the first
     * attempt at this did exactly that and came back empty.
     */
    private function documentUrl(string $html): ?string
    {
        if (preg_match('#href="(/(?:en/)?downloads/\d+/[a-z]{2})"#i', $html, $m) === 1) {
            return self::BASE.$m[1];
        }

        return null;
    }
}
