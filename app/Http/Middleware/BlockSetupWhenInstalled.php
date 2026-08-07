<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Setup\InstallationState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shuts the setup wizard once the installation is done.
 *
 * An installer route left reachable is a classic way in, so this refuses
 * outright rather than redirecting.
 *
 * The marker file is the only thing consulted here. An earlier version also
 * treated "this installation looks like it is in use" as installed, as a safety
 * net for a deleted marker -- but that condition becomes true DURING setup, the
 * moment the administrator account is created, and locked the wizard one step
 * before it could be finished. The tests caught it.
 *
 * The safety net now sits where it belongs: on the individual steps that change
 * something, in RequireSetupAuthority. Someone who finds a reopened wizard on a
 * live system can look at it and can run migrations, which are idempotent --
 * and nothing else.
 */
final class BlockSetupWhenInstalled
{
    public function __construct(private readonly InstallationState $state) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->state->isInstalled()) {
            abort(404);
        }

        return $next($request);
    }
}
