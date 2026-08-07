<?php

declare(strict_types=1);

namespace App\Core\Http;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The real one.
 *
 * A timeout and a user agent, and nothing clever. A manufacturer's site is
 * somebody else's server: the request identifies itself, asks once, and gives up
 * rather than retrying in a loop.
 */
final class GuzzleFetcher implements FormFetcher, HttpFetcher
{
    /** Resolved bundle path per host; false where the host needs none. */
    private array $bundles = [];

    /**
     * The chain resolver, optional so a test can build this without one.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * AN INCOMPLETE CHAIN IS NOT A LOGIN PROBLEM -- which is what this used to
     * assume. The repair sat on SessionFetcher alone, so it reached exactly the
     * gated manufacturers and nobody else, and the note in schempp-hirth.yaml
     * ("the driver completes the chain by itself now") read as a property of the
     * driver when it was a property of one fetcher.
     *
     * C.E.A.P.R. is the case that showed it: the same missing RapidSSL
     * intermediate as Schempp-Hirth, but no login -- so the plain fetcher met it
     * with the strict default and died on cURL 60, for a source that is
     * otherwise completely open. A club would have read that as "Robin is
     * unreachable" rather than "their server omits an intermediate".
     *
     * verify is NEVER turned off here either. It is completed, and only with an
     * intermediate that reaches a root the system already trusts.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function __construct(private readonly ?CertificateChainResolver $chainResolver = null) {}

    /**
     * @param  array<string, string>  $headers
     */
    public function get(string $url, array $headers = []): string
    {
        $request = Http::withHeaders($headers + [
            'User-Agent' => 'Aeronance/0.1 (+https://github.com/maruether/aeronance)',

            /*
             * HTML FIRST, and this is not a formality.
             *
             * ─────────────────────────────────────────────────────────────────
             * The header used to read "text/html, application/json" -- equal
             * preference -- and a content-negotiating server is free to pick
             * either. Scheibe picks JSON: their type pages came back as 363 kB
             * of ESCAPED markup (`<table class=\"table\">`) instead of 84 kB of
             * HTML, with ZERO ordinary `href="` in them.
             *
             * Every pattern in every spec is written against HTML, so the effect
             * was a page that arrives, parses to nothing, and reports that the
             * manufacturer published nothing. It went unnoticed because the
             * fixtures were saved with a browser and the tests therefore saw the
             * HTML the driver never got.
             *
             * The weights are the ones a browser sends. JSON sources are
             * unaffected: an API answers JSON whatever is asked for, and the
             * catch-all at the end leaves them the room to.
             * ─────────────────────────────────────────────────────────────────
             */
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ])
            ->timeout(30)
            ->connectTimeout(10);

        $bundle = $this->bundleFor($url);

        if ($bundle !== null) {
            $request = $request->withOptions(['verify' => $bundle]);
        }

        $response = $request->get($url);

        if ($response->failed()) {
            $message = sprintf('Could not fetch %s: HTTP %d.', $url, $response->status());

            // 404 is told apart from every other failure because a paged list
            // ends with one -- see HttpNotFound.
            throw $response->status() === 404
                ? new HttpNotFound($message)
                : new RuntimeException($message);
        }

        return $response->body();
    }

    /**
     * The same request, as a form POST.
     *
     * Shares the chain repair and the failure handling with get(), because the
     * manufacturer that needs the one tends to need the other: C.E.A.P.R. is
     * both an incomplete chain and a POST-only endpoint.
     *
     * @param  array<string, string>  $form
     * @param  array<string, string>  $headers
     */
    public function post(string $url, array $form, array $headers = []): string
    {
        $request = Http::withHeaders($headers + [
            'User-Agent' => 'Aeronance/0.1 (+https://github.com/maruether/aeronance)',
            'Accept' => 'application/json, text/html',

            /*
             * Said out loud, because the endpoint is a site's own AJAX route.
             * Some of them answer a plain POST with the surrounding HTML page
             * instead of the data -- which decodes to nothing and reads as "this
             * manufacturer published nothing".
             */
            'X-Requested-With' => 'XMLHttpRequest',
        ])
            ->timeout(30)
            ->connectTimeout(10);

        $bundle = $this->bundleFor($url);

        if ($bundle !== null) {
            $request = $request->withOptions(['verify' => $bundle]);
        }

        $response = $request->asForm()->post($url, $form);

        if ($response->failed()) {
            $message = sprintf('Could not post to %s: HTTP %d.', $url, $response->status());

            throw $response->status() === 404
                ? new HttpNotFound($message)
                : new RuntimeException($message);
        }

        return $response->body();
    }

    /**
     * A bundle completing this host's chain, remembered for the run.
     *
     * Per host rather than per URL: a manufacturer's certificate is the same on
     * every page of the site, and an overview source asks for a great many of
     * them. The resolver caches on disk for thirty days as well, so a healthy
     * host costs one probe a month and this costs one lookup a run.
     */
    private function bundleFor(string $url): ?string
    {
        if ($this->chainResolver === null) {
            return null;
        }

        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');

        if ($host === '') {
            return null;
        }

        if (! array_key_exists($host, $this->bundles)) {
            $this->bundles[$host] = $this->chainResolver->bundleFor($url) ?? false;
        }

        return $this->bundles[$host] === false ? null : $this->bundles[$host];
    }
}
