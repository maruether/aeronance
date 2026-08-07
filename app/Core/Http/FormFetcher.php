<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * A fetcher that can also answer a form.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * A SEPARATE INTERFACE, not a second method on HttpFetcher, and deliberately so.
 * Thirteen test doubles implement HttpFetcher to hand a parser its saved page;
 * adding a required method there would have broken every one of them to serve a
 * single manufacturer. A double that never posts should not have to say how it
 * would.
 *
 * WHY IT EXISTS AT ALL. C.E.A.P.R. serves Robin's whole BL/LS list as JSON, open
 * and without a login -- but only to a POST. The same path answers HTTP 404 to
 * every GET, with query string or without. Reading it was therefore not a matter
 * of finding the right URL: the driver could not ask the question at all.
 * ─────────────────────────────────────────────────────────────────────────────
 */
interface FormFetcher
{
    /**
     * @param  array<string, string>  $form
     * @param  array<string, string>  $headers
     */
    public function post(string $url, array $form, array $headers = []): string;
}
