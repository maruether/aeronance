<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Access\CoreRoles;
use App\Core\Setup\InstallationState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the setup steps that change something.
 *
 * Two situations have to be told apart, and the marker file alone cannot do it:
 *
 *  - A genuinely fresh installation. Nobody exists yet, so nobody can
 *    authenticate, and the steps must be open -- otherwise no one could ever
 *    install the thing.
 *  - A live installation whose marker has gone missing, through a botched
 *    deployment or a cleared storage directory. Here the wizard must not hand a
 *    stranger the remaining steps.
 *
 * The distinguishing question is whether an administrator exists. Once one
 * does, continuing the setup requires being that administrator -- which the
 * wizard arranges by logging the account in the moment it is created, so the
 * normal flow is unaffected.
 */
final class RequireSetupAuthority
{
    public function __construct(private readonly InstallationState $state) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->state->hasAdministrator()) {
            return $next($request);
        }

        $user = $request->user();

        if ($user !== null && $user->hasRole(CoreRoles::ADMIN)) {
            return $next($request);
        }

        abort(404);
    }
}
