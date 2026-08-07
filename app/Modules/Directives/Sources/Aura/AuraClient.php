<?php

declare(strict_types=1);

namespace App\Modules\Directives\Sources\Aura;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talking to a Salesforce Experience Cloud portal the way its own pages do.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS. Diamond publishes no overview document -- Vorgabe: "Die bieten
 * leider keine gescheite übersicht an." Their portal serves the same content as
 * structured data instead, and better than a PDF could: 2508 documents across 20
 * type libraries, every one of them carrying an issue date. DG and Lindner
 * cannot supply a date at all.
 *
 * The portal is an empty shell. Every route returns the same 114 KB of HTML and
 * fetches its data afterwards over an Aura RPC. So this is not scraping a page,
 * it is calling the same endpoint the page calls, with the same arguments.
 *
 * NOT A DIAMOND MECHANISM. Aura is the Salesforce platform, and any manufacturer
 * on Experience Cloud answers the same way -- which is why the class name and
 * the methods come from the spec and nothing here mentions Diamond.
 *
 * NO LOGIN IS INVOLVED, and the way that became clear is worth recording. The
 * request carries a `member` parameter that looks exactly like a personal user
 * id. It is the site's GUEST id, a constant -- established not by inspecting the
 * protocol but because the brief mentions there is no account at all: what looked
 * like his session was what every visitor gets.
 *
 * THE FAILURE MODE THIS GUARDS. Called without that parameter, the API answers
 * SUCCESS with an EMPTY LIST -- never an error. An implementation that does not
 * check would report "the manufacturer has published nothing", which is the one
 * outcome this whole module exists to prevent. Hence assertNotEmpty() below.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class AuraClient
{
    /**
     * Milliseconds between calls.
     *
     * Twenty libraries times folders times files is a few dozen requests to
     * somebody else's server. It costs a few seconds and is the difference
     * between reading a portal and hammering it.
     */
    private const DELAY_MS = 250;

    private ?string $fwuid = null;

    private ?string $appVersion = null;

    private int $sequence = 0;

    public function __construct(
        private readonly string $endpoint,
        private readonly string $pageUri,
    ) {}

    /**
     * One Apex method, with its parameters.
     *
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    public function call(string $className, string $method, array $params): array
    {
        $this->bootstrap();
        $this->sequence++;

        $message = [
            'actions' => [[
                'id' => $this->sequence.';a',
                'descriptor' => 'aura://ApexActionController/ACTION$execute',
                'callingDescriptor' => 'UNKNOWN',
                'params' => [
                    'namespace' => '',
                    'classname' => $className,
                    'method' => $method,
                    'params' => (object) $params,
                    'cacheable' => false,
                    'isContinuation' => false,
                ],
            ]],
        ];

        $response = Http::asForm()
            ->withHeaders([
                'User-Agent' => 'Aeronance/0.1 (+https://github.com/maruether/aeronance)',

                // Origin and Referer, because the endpoint is the page's own and
                // a request that did not come from it has no business there.
                'Origin' => $this->origin(),
                'Referer' => $this->origin().$this->pageUri,
            ])
            ->timeout(45)
            ->post($this->endpoint.'?r='.$this->sequence.'&aura.ApexAction.execute=1', [
                'message' => json_encode($message),
                'aura.context' => json_encode($this->context()),
                'aura.pageURI' => $this->pageUri,
                'aura.token' => 'null',
            ]);

        usleep(self::DELAY_MS * 1000);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                '%s antwortete auf %s.%s mit HTTP %d.',
                $this->origin(),
                $className,
                $method,
                $response->status(),
            ));
        }

        return $this->unwrap($response->json(), $className, $method);
    }

    /**
     * The rows out of an Aura response.
     *
     * The payload nests twice -- actions[0].returnValue.returnValue -- and the
     * outer one is an object while the inner is the list. Reading the outer as
     * the list yields "not an array", which is easy to render as "0 rows"; that
     * mistake cost an hour of chasing a session that was never the problem.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException
     */
    private function unwrap(mixed $body, string $className, string $method): array
    {
        $action = $body['actions'][0] ?? null;

        if (! is_array($action)) {
            throw new RuntimeException(sprintf(
                '%s.%s lieferte keine verwertbare Antwort.',
                $className,
                $method,
            ));
        }

        if (($action['state'] ?? '') !== 'SUCCESS') {
            throw new RuntimeException(sprintf(
                '%s.%s scheiterte: %s',
                $className,
                $method,
                (string) ($action['error'][0]['message'] ?? 'kein Grund genannt'),
            ));
        }

        $value = $action['returnValue'] ?? null;

        if (is_array($value) && array_key_exists('returnValue', $value)) {
            $value = $value['returnValue'];
        }

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Refuses an empty answer where empty cannot be the truth.
     *
     * A library list with nothing in it means the request was wrong, not that
     * the manufacturer withdrew every publication -- and the API says SUCCESS
     * either way.
     *
     * @param  list<array<string, mixed>>  $rows
     *
     * @throws RuntimeException
     */
    public function assertNotEmpty(array $rows, string $what): void
    {
        if ($rows !== []) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s lieferte keine Einträge. Das Portal antwortet auch auf eine falsche '
            .'Anfrage mit "erfolgreich" und einer leeren Liste — hier ist deshalb von '
            .'einem Fehler auszugehen, nicht davon, dass der Hersteller nichts hat.',
            $what,
        ));
    }

    /**
     * fwuid and app version, read from the portal itself.
     *
     * Both change whenever Salesforce redeploys the site, so they are taken from
     * the page on every run rather than pinned in a spec -- a pinned fwuid works
     * until the day it silently does not.
     */
    private function bootstrap(): void
    {
        if ($this->fwuid !== null) {
            return;
        }

        $html = Http::withHeaders([
            'User-Agent' => 'Aeronance/0.1 (+https://github.com/maruether/aeronance)',
        ])->timeout(45)->get($this->origin().$this->pageUri)->body();

        if (preg_match('/fwuid%22%3A%22([^%]+)%22/', $html, $m) !== 1) {
            throw new RuntimeException(sprintf(
                'Die Seite %s nennt keine fwuid — vermutlich hat der Hersteller sein '
                .'Portal umgebaut.',
                $this->origin().$this->pageUri,
            ));
        }

        $this->fwuid = urldecode($m[1]);

        if (preg_match('/APPLICATION%40markup%3A%2F%2F([^%]+)%22%3A%22([^%]+)%22/', $html, $v) === 1) {
            $this->appVersion = urldecode($v[2]);
        }
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return [
            'mode' => 'PROD',
            'fwuid' => (string) $this->fwuid,
            'app' => 'siteforce:communityApp',

            // The loaded app version. Without it the endpoint answers, but with
            // an empty list -- the silent failure again.
            'loaded' => $this->appVersion !== null
                ? ['APPLICATION@markup://siteforce:communityApp' => $this->appVersion]
                : (object) [],
            'dn' => [],
            'globals' => (object) [],
            'uad' => true,
        ];
    }

    private function origin(): string
    {
        $parts = parse_url($this->endpoint);

        return sprintf('%s://%s', $parts['scheme'] ?? 'https', $parts['host'] ?? '');
    }
}
