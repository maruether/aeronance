<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * How an adapter reaches the network.
 *
 * A seam of its own, for one reason: an adapter that calls Http directly cannot
 * be tested without either hitting somebody's site on every run or mocking the
 * framework. With this, a test hands over saved pages and the parser is
 * exercised against the real markup -- which is the part that breaks.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * IN THE CORE, AND THAT IS THE POINT. It was born in the directives module,
 * where the first adapter needed it, and the fleet then borrowed it for the type
 * certificate authorities -- a base module reaching into an optional one. Switch
 * directives off and the fleet cannot resolve its own fetcher.
 *
 * Reaching for the network is not a directives concern any more than a database
 * connection is. It belongs where anything may depend on it and it depends on
 * nothing: here, beside CertificateChainResolver, which it already used.
 * ─────────────────────────────────────────────────────────────────────────────
 */
interface HttpFetcher
{
    /**
     * @param  array<string, string>  $headers  e.g. an Authorization header
     */
    public function get(string $url, array $headers = []): string;
}
