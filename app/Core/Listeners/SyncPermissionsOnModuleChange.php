<?php

declare(strict_types=1);

namespace App\Core\Listeners;

use App\Core\Access\AccessSetup;
use App\Core\Modules\Events\ModuleEnabled;
use Illuminate\Support\Facades\Artisan;

/**
 * A module that has just been switched on brings permissions nobody has yet.
 *
 * Creating them here means an administrator can hand them out straight away,
 * instead of the role editor showing an empty list until someone remembers to
 * run a command.
 *
 * It goes through AccessSetup rather than the registry directly, so a module's
 * declared default roles are honoured. That only ever touches permissions that
 * have just come into being -- nobody could have had an opinion about a
 * permission that did not exist a moment ago -- and existing assignments are left
 * exactly as the club left them.
 *
 * Filament's component cache is cleared as well: it holds the resources of the
 * panel as it was built, and a module that has just appeared is not in it.
 */
final readonly class SyncPermissionsOnModuleChange
{
    public function __construct(private AccessSetup $access) {}

    public function handle(ModuleEnabled $event): void
    {
        $result = $this->access->run();

        /*
         * Logged when a role actually gained something.
         *
         * Widening what a role may do is worth a line in the record even when it
         * is the intended behaviour -- somebody looking at the audit trail in two
         * years should be able to see where a permission came from without
         * reading the release notes.
         */
        foreach ($result['granted'] as $role => $permissions) {
            activity('core')
                ->withProperties(['role' => $role, 'permissions' => $permissions])
                ->log(sprintf(
                    'Modul %s aktiviert: Rolle "%s" hat %d neue Berechtigung(en) erhalten.',
                    $event->module,
                    $role,
                    count($permissions),
                ));
        }

        if (app()->runningInConsole()) {
            Artisan::call('filament:clear-cached-components');
        }
    }
}
