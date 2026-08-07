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
