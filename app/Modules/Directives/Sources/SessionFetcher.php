<?php

declare(strict_types=1);

namespace App\Modules\Directives\Sources;

use App\Core\Http\CertificateChainResolver;
use App\Core\Http\FormFetcher;
use App\Core\Http\HttpFetcher;
use App\Core\Http\HttpNotFound;
use App\Modules\Directives\Sources\Configured\SourceSpec;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A fetcher that logs in first and keeps the session.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The third way a manufacturer can gate its list, after "open" and "basic auth":
 * a form login that sets a session cookie. Schempp-Hirth runs TYPO3's felogin,
 * which additionally requires the form's own hidden fields (__referrer[...] and
 * __trustedProperties) -- so the login cannot be a fixed POST body. The form has
 * to be READ first, then answered.
 *
 * CREDENTIALS NEVER TOUCH A COMMAND LINE OR A URL. They are read from the
 * environment through the spec and posted as form fields. That is not a
 * theoretical concern: an earlier attempt at this login went through a shell
 * `eval`, which split the password on its special characters and printed the
 * fragments as "command not found". The password had to be rotated. Nothing here
 * builds a shell command, and nothing logs a request body.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SessionFetcher implements FormFetcher, HttpFetcher
{
    /**
     * A login redirect chain is short; anything longer is a loop.
     */
    private const MAX_REDIRECTS = 5;

    /** @var array<string, string> */
    private array $cookies = [];

    private bool $loggedIn = false;

    /** Resolved once per instance; null until the first request needs it. */
    private ?string $resolvedBundle = null;

    private bool $bundleResolved = false;

    public function __construct(
        private readonly SourceSpec $spec,
        /**
         * Completes a server's certificate chain when the server ships an
         * incomplete one -- see CertificateChainResolver. Optional so a test can
         * construct a fetcher without reaching for the network; in production the
         * container always supplies it.
         */
        private readonly ?CertificateChainResolver $chainResolver = null,
    ) {}

    /**
     * @param  array<string, string>  $headers
     */
    public function get(string $url, array $headers = []): string
    {
        $this->ensureLoggedIn();

        $response = $this->request('GET', $url, [], $headers);

        return $response->body();
    }

    /**
     * Answer a form behind the session -- C.E.A.P.R. is why.
     *
     * Its list only exists as an answer to a POST (see FormFetcher), and with
     * the login being optional the same source now runs through THIS fetcher.
     * Without this method a gated-or-optional POST source was a contradiction:
     * the spec demanded a form answer, the fetcher could only GET.
     *
     * @param  array<string, string>  $form
     * @param  array<string, string>  $headers
     */
    public function post(string $url, array $form, array $headers = []): string
    {
        $this->ensureLoggedIn();

        /*
         * The SAME request shape as GuzzleFetcher::post(), deliberately.
         *
         * C.E.A.P.R.'s anonymous list was measured through GuzzleFetcher --
         * JSON-first Accept plus X-Requested-With, because these endpoints are
         * a site's own AJAX routes and some answer a plain POST with the
         * surrounding HTML page instead of the data. Moving the source onto
         * this fetcher must not change what travels on the wire; review found
         * exactly that regression. Only the ENDPOINT posts get these headers:
         * the login POST below imitates a browser form and stays untouched.
         */
        $response = $this->request('POST', $url, $form, $headers + [
            'Accept' => 'application/json, text/html',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        return $response->body();
    }

    private function ensureLoggedIn(): void
    {
        if ($this->loggedIn || $this->spec->loginUrl === null) {
            return;
        }

        [$user, $password] = $this->spec->credentials();

        if (blank($user) && blank($password) && $this->spec->loginOptional) {
            /*
             * No credentials, none required: the session stays anonymous. NOT
             * latched as "logged in", deliberately -- a queue worker keeps this
             * instance alive across jobs, and a club that enters its
             * subscription mid-week must not stay anonymous until somebody
             * restarts the worker. The blank-check costs one credentials
             * lookup per fetch, which is what correctness costs here.
             */
            return;
        }

        if (blank($user) || blank($password)) {
            // Half a login -- a stored username without its password, or the
            // other way round -- is a mistake worth naming even where the
            // login itself is optional: somebody typed it expecting it to work.
            throw new RuntimeException(sprintf(
                '%s needs a login. Set DIRECTIVES_%s_USER and DIRECTIVES_%s_PASSWORD in .env.',
                $this->spec->label,
                strtoupper((string) $this->spec->authProfile),
                strtoupper((string) $this->spec->authProfile),
            ));
        }

        // Read the form before answering it: TYPO3 (and most CMS logins) carry
        // per-request hidden fields that a fixed POST body cannot know.
        $page = $this->request('GET', $this->spec->loginUrl)->body();

        [$action, $fields] = $this->readLoginForm($page);

        $fields[$this->spec->loginUserField] = $user;
        $fields[$this->spec->loginPasswordField] = $password;

        foreach ($this->spec->loginExtra as $key => $value) {
            $fields[$key] = $value;
        }

        $response = $this->request('POST', $this->absolute($action), $fields);

        if ($this->spec->loginSuccessPattern !== null
            && preg_match($this->spec->loginSuccessPattern, $response->body()) !== 1) {
            throw new RuntimeException(sprintf(
                'The login at %s did not succeed. The credentials may be wrong, or the '
                .'form may have changed.',
                $this->spec->label,
            ));
        }

        $this->loggedIn = true;
    }

    /**
     * The form's action and its hidden fields.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function readLoginForm(string $html): array
    {
        if (preg_match($this->spec->loginFormPattern, $html, $m) !== 1) {
            throw new RuntimeException(sprintf(
                'No login form found at %s -- the page may have changed.',
                $this->spec->label,
            ));
        }

        $action = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $fields = [];

        preg_match_all('#<input[^>]*>#i', $m[2] ?? '', $inputs);

        foreach ($inputs[0] as $input) {
            if (preg_match('#name="([^"]+)"#i', $input, $n) !== 1) {
                continue;
            }

            $name = html_entity_decode($n[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // The credential fields are filled from the environment, never from
            // whatever the page happens to prefill.
            if (in_array($name, [$this->spec->loginUserField, $this->spec->loginPasswordField], true)) {
                continue;
            }

            $value = preg_match('#value="([^"]*)"#i', $input, $v) === 1
                ? html_entity_decode($v[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')
                : '';

            $fields[$name] = $value;
        }

        return [$action, $fields];
    }

    /**
     * What to trust for this host: a bundle path, or true for the strict default.
     *
     * A ca_bundle named in the spec is a manual override and wins -- for the rare
     * server whose chain even AIA cannot complete. Otherwise the resolver is
     * asked once, and answers null when the host's chain is already complete (the
     * normal case), in which case the strict default stands.
     */
    private function verifyOption(): string|bool
    {
        if ($this->spec->caBundle !== null) {
            return $this->spec->caBundle;
        }

        if ($this->chainResolver === null) {
            return true;
        }

        if (! $this->bundleResolved) {
            $this->bundleResolved = true;
            $this->resolvedBundle = $this->chainResolver->bundleFor(
                (string) ($this->spec->loginUrl ?? $this->spec->pageUrl),
            );
        }

        return $this->resolvedBundle ?? true;
    }

    /**
     * @param  array<string, string>  $form
     * @param  array<string, string>  $headers
     */
    private function request(string $method, string $url, array $form = [], array $headers = [], int $depth = 0): Response
    {
        $request = Http::withHeaders($headers + [
            'User-Agent' => 'Aeronance/0.1 (+https://github.com/maruether/aeronance)',
            'Accept' => 'text/html, application/json',
        ])
            /*
             * Cookies by hand AND redirects by hand -- the two go together.
             *
             * ─────────────────────────────────────────────────────────────────
             * A successful form login answers with a redirect (Post-Redirect-Get)
             * and sets the session cookie IN THAT REDIRECT. Guzzle's own redirect
             * follower would then fetch the destination -- but with cookies=>false
             * it drops the cookie it was just handed, so the destination comes
             * back logged OUT. Schempp-Hirth does exactly this: POST 303 sets
             * fe_typo_user, and the follow-up without it lands back on the login.
             *
             * So we turn Guzzle's follower off and do it ourselves below, carrying
             * the cookies we collect at each hop. Manual cookies were already a
             * decision here; automatic redirects quietly undid them.
             * ─────────────────────────────────────────────────────────────────
             */
            ->withOptions(['cookies' => false, 'allow_redirects' => false])
            ->timeout(40)
            ->connectTimeout(10);

        if ($this->cookies !== []) {
            $request = $request->withHeaders(['Cookie' => $this->cookieHeader()]);
        }

        /*
         * The certificate chain, where a manufacturer ships an incomplete one.
         *
         * Schempp-Hirth sends only its server certificate and omits the RapidSSL
         * intermediate; strict clients then cannot verify it. This used to need a
         * file dropped in by hand. Now the driver completes the chain itself --
         * fetching the missing intermediate the way a browser does and verifying
         * it reaches a trusted root -- so a fresh install needs no manual step.
         * See verifyOption() and CertificateChainResolver. verify is NEVER turned
         * off; it is completed.
         */
        $verify = $this->verifyOption();

        if ($verify !== true) {
            $request = $request->withOptions(['verify' => $verify]);
        }

        $response = $method === 'POST'
            ? $request->asForm()->post($url, $form)
            : $request->get($url);

        // Before following anything: the session cookie lives in this response.
        $this->rememberCookies($response);

        if ($response->redirect() && $depth < self::MAX_REDIRECTS) {
            $location = (string) $response->header('Location');

            if ($location !== '') {
                $target = $this->absolute($location);

                /*
                 * A login session does not follow a CHANGE OF HOST. The next
                 * request would carry the session cookie -- and, on the way to
                 * plain http, carry it readable. The Location header is the
                 * server's word, not the spec's; a portal that suddenly points
                 * somewhere else deserves a loud stop, not a quiet leak.
                 */
                if (! $this->sameOrigin($url, $target)) {
                    throw new RuntimeException(sprintf(
                        '%s redirected to %s -- another host (or a downgrade to http). '
                        .'A login session does not follow that.',
                        $url,
                        $target,
                    ));
                }

                // 303 is "see other" -- a GET, always. 301/302 after a login POST
                // are treated the same, which is what browsers do.
                return $this->request('GET', $target, [], $headers, $depth + 1);
            }
        }

        if ($response->failed()) {
            /*
             * An HTTP 403 on the login POST is not a wrong password -- a wrong
             * password comes back 200 with the form again. It is the
             * manufacturer's own web-application firewall refusing the request,
             * which Schempp-Hirth's does when the password contains certain
             * character sequences. Said plainly so a club does not spend an
             * evening re-checking credentials that are perfectly correct.
             */
            if ($response->status() === 403) {
                throw new RuntimeException(sprintf(
                    'Die Firewall von %s hat die Anmeldung mit HTTP 403 blockiert -- das '
                    .'ist KEIN falsches Passwort (das käme als normale Seite zurück). Ihr '
                    .'Schutzsystem lehnt bestimmte Zeichenfolgen im Passwort ab. Das ist '
                    .'ein Fehler auf deren Seite; ein anderes Passwort dort umgeht ihn.',
                    $this->spec->label,
                ));
            }

            $message = sprintf('%s answered HTTP %d.', $url, $response->status());

            // 404 is told apart from every other failure, exactly as in
            // GuzzleFetcher: a paged list ends with one, and a caller that
            // walks pages needs to hear the difference -- see HttpNotFound.
            throw $response->status() === 404
                ? new HttpNotFound($message)
                : new RuntimeException($message);
        }

        return $response;
    }

    /**
     * Same scheme, same host -- what a session cookie is allowed to travel to.
     */
    private function sameOrigin(string $from, string $to): bool
    {
        $a = parse_url($from);
        $b = parse_url($to);

        return ($a['scheme'] ?? '') === ($b['scheme'] ?? '')
            && ($a['host'] ?? '') === ($b['host'] ?? '');
    }

    private function rememberCookies(Response $response): void
    {
        foreach ($response->headers()['Set-Cookie'] ?? [] as $header) {
            if (preg_match('/^([^=]+)=([^;]*)/', $header, $m) === 1) {
                $this->cookies[trim($m[1])] = $m[2];
            }
        }
    }

    private function cookieHeader(): string
    {
        $parts = [];

        foreach ($this->cookies as $name => $value) {
            $parts[] = $name.'='.$value;
        }

        return implode('; ', $parts);
    }

    private function absolute(string $url): string
    {
        if ($url === '') {
            // A form with an empty action posts back to the page it came from.
            // C.E.A.P.R.'s login does exactly that; scheme://host alone would
            // send the credentials to the site root instead.
            return (string) $this->spec->loginUrl;
        }

        if (str_starts_with($url, 'http')) {
            return $url;
        }

        $base = parse_url((string) $this->spec->loginUrl);

        return sprintf('%s://%s%s', $base['scheme'] ?? 'https', $base['host'] ?? '', $url);
    }
}
