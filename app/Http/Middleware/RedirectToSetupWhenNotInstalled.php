<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Setup\InstallationState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a fresh installation to the wizard instead of a login form nobody can
 * use yet.
 */
final class RedirectToSetupWhenNotInstalled
{
    public function __construct(private readonly InstallationState $state) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->state->isInstalled() && ! $this->state->looksInUse()) {
            return redirect()->route('setup.index');
        }

        return $next($request);
    }
}
