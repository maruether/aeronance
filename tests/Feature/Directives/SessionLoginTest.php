<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Modules\Directives\Sources\Configured\SourceSpec;
use App\Modules\Directives\Sources\SessionFetcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Logging in, and the two things that made it fail against a real server.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The table parsing has its own test; this one is about getting PAST the login,
 * which turned out to hide two traps that only a live server revealed.
 *
 * A successful form login answers with a redirect and sets the session cookie
 * IN that redirect (Post-Redirect-Get). This fetcher manages cookies by hand,
 * and Guzzle's own redirect follower drops a hand-managed cookie -- so following
 * automatically fetched the destination logged OUT, every time. The fetcher
 * follows redirects itself now, carrying the cookie.
 *
 * And an HTTP 403 on the login POST is not a wrong password. A wrong password
 * comes back 200 with the form again; the 403 is the manufacturer's firewall
 * refusing the request over the password's contents. The two must not read the
 * same to somebody staring at correct credentials.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SessionLoginTest extends TestCase
{
    private const LOGIN = 'https://example.test/login';

    protected function setUp(): void
    {
        parent::setUp();

        // Credentials for the "test" auth profile -- fake values, no network.
        // Through the config, because that is where the resolver reads them: a
        // cached config makes env() return null, so putenv() alone would exercise
        // a path production never takes.
        config(['aeronance.directive_credentials' => [
            'DIRECTIVES_TEST_USER' => 'probe',
            'DIRECTIVES_TEST_PASSWORD' => 'secret',
        ]]);
    }

    protected function tearDown(): void
    {
        config(['aeronance.directive_credentials' => []]);

        parent::tearDown();
    }

    private const TARGET = 'https://example.test/list';

    #[Test]
    public function the_session_cookie_survives_the_login_redirect(): void
    {
        /*
         * The exact sequence a real Post-Redirect-Get login produces:
         *   GET  login   -> the form
         *   POST login   -> 303, Set-Cookie: session, Location: /account
         *   GET  account -> only logged in IF the cookie came along
         *   GET  list    -> the data, same condition
         *
         * If the cookie is dropped at the redirect, /account and /list come back
         * as the login form and there is no "Logout" anywhere -- which is what
         * happened before the fix.
         */
        Http::fake([
            self::LOGIN => Http::sequence()
                ->push($this->loginForm())
                ->push('', 303, ['Location' => 'https://example.test/account', 'Set-Cookie' => 'session=abc123; path=/']),
            'https://example.test/account' => Http::response($this->loggedInPage()),
            self::TARGET => Http::response($this->loggedInPage()),
        ]);

        $body = (new SessionFetcher($this->spec()))->get(self::TARGET);

        $this->assertStringContainsString('Logout', $body);

        // The cookie set by the 303 must appear on the requests that FOLLOW it.
        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://example.test/account'
            && str_contains($r->header('Cookie')[0] ?? '', 'session=abc123'));
        Http::assertSent(fn (Request $r): bool => $r->url() === self::TARGET
            && str_contains($r->header('Cookie')[0] ?? '', 'session=abc123'));
    }

    #[Test]
    public function a_firewall_403_is_not_reported_as_a_wrong_password(): void
    {
        // The manufacturer's WAF refusing the POST over the password's contents.
        // The message has to make clear the credentials are not the problem, or
        // a club re-checks a password that is perfectly correct.
        Http::fake([
            self::LOGIN => Http::sequence()
                ->push($this->loginForm())
                ->push('Forbidden', 403),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Firewall.*403|403.*Firewall/s');

        (new SessionFetcher($this->spec()))->get(self::TARGET);
    }

    #[Test]
    public function a_wrong_password_is_reported_as_such_not_as_a_firewall(): void
    {
        // A wrong password comes back 200 with the form -- no "Logout". The
        // success pattern is what catches it, and it must NOT be mistaken for the
        // firewall case above.
        Http::fake([
            self::LOGIN => Http::sequence()
                ->push($this->loginForm())
                ->push($this->loginForm(), 200),   // the form again: login refused
            self::TARGET => Http::response($this->loginForm()),
        ]);

        try {
            (new SessionFetcher($this->spec()))->get(self::TARGET);
            $this->fail('A refused login must throw.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('Firewall', $e->getMessage());
            $this->assertMatchesRegularExpression('/did not succeed|credentials/i', $e->getMessage());
        }
    }

    #[Test]
    public function a_redirect_loop_does_not_hang(): void
    {
        // A server that redirects to itself forever must stop, not spin. The
        // login here always answers 303 to the same place.
        Http::fake([
            self::LOGIN => Http::sequence()
                ->push($this->loginForm())
                ->push('', 303, ['Location' => self::LOGIN])
                ->push('', 303, ['Location' => self::LOGIN])
                ->push('', 303, ['Location' => self::LOGIN])
                ->push('', 303, ['Location' => self::LOGIN])
                ->push('', 303, ['Location' => self::LOGIN])
                ->push('', 303, ['Location' => self::LOGIN])
                ->push('', 303, ['Location' => self::LOGIN]),
        ]);

        // It must return control -- whether it then finds no "Logout" (throws) or
        // stops at the cap, it may not loop. The assertion is simply that we get
        // here at all with an exception rather than a hang.
        $this->expectException(RuntimeException::class);

        (new SessionFetcher($this->spec()))->get(self::TARGET);
    }

    private function spec(): SourceSpec
    {
        return SourceSpec::fromArray([
            'name' => 'test-login',
            'label' => 'Test (Login)',
            'type' => 'table',
            'auth' => 'test',
            'login' => [
                'url' => self::LOGIN,
                'form_pattern' => '#<form[^>]*action="([^"]*)"[^>]*>(.*?)</form>#is',
                'user_field' => 'user',
                'password_field' => 'pass',
                'extra' => ['logintype' => 'login'],
                'success_pattern' => '/Logout/i',
            ],
            'page' => [
                'url' => self::TARGET,
                'table_pattern' => '#<table.*?</table>#is',
                'row_pattern' => '#<tr[^>]*>(.*?)</tr>#is',
                'cell_pattern' => '#<t[dh][^>]*>(.*?)</t[dh]>#is',
            ],
            'columns' => ['number' => 0, 'title' => 1],
        ], 'test-login.yaml');
    }

    private function loginForm(): string
    {
        return '<html><body><form action="'.self::LOGIN.'" method="post">'
            .'<input type="hidden" name="__trustedProperties" value="{}">'
            .'<input type="text" name="user"><input type="password" name="pass">'
            .'</form></body></html>';
    }

    private function loggedInPage(): string
    {
        return '<html><body><a href="/logout">Logout</a>'
            .'<table><tr><td>TM 1-01</td><td>Beschlag prüfen</td></tr></table>'
            .'</body></html>';
    }
}
