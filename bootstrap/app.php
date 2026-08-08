<?php

declare(strict_types=1);

use App\Core\Http\SecurityHeaders;
use Filament\Facades\Filament;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * There is no route called "login" -- the panel brings its own, and
         * routes outside it (the certificate delivery, for one) would otherwise
         * answer an unauthenticated request with a 500 instead of a login
         * prompt. A test found it; in production it would have been an error
         * page where a form belonged.
         */
        $middleware->redirectGuestsTo(fn (): string => Filament::getLoginUrl());

        /*
         * Behind a reverse proxy the truth about a request lives in the
         * X-Forwarded-* headers, and Laravel ignores them unless the proxy is
         * TRUSTED. Untrusted, three things quietly go wrong: request()->ip()
         * logs the proxy's address into the sign-in audit (every attacker
         * shares one IP), the login rate limiter throttles that one shared IP
         * for the whole club, and isSecure() stays false so the HSTS header is
         * never sent.
         *
         * TRUSTED_PROXIES stood in the Docker template for a while with
         * NOTHING reading it -- a setting that looks like a promise. Now this
         * reads it: "*" trusts whatever talks to us (only sane when the
         * container port is not itself reachable from outside), anything else
         * is a comma-separated list of addresses or CIDR ranges.
         */
        $proxies = env('TRUSTED_PROXIES');

        if (is_string($proxies) && trim($proxies) !== '') {
            $middleware->trustProxies(at: $proxies === '*'
                ? '*'
                : array_map(trim(...), explode(',', $proxies)));
        }

        /*
         * Die HTTP-Härtung, für JEDE Antwort -- siehe SecurityHeaders. Vorher
         * setzte sie ein einzelner Controller, galt also für einen von Dutzenden
         * Endpunkten.
         */
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
