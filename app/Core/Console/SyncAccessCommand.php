<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Core\Access\AccessSetup;
use Illuminate\Console\Command;

/**
 * Brings roles and permissions into line with what this installation ships.
 *
 * Run after every update and after every module change. Safe to run repeatedly:
 * it only ever adds.
 */
final class SyncAccessCommand extends Command
{
    protected $signature = 'aeronance:sync-access';

    protected $description = 'Create any missing roles and permissions (additive, safe to repeat)';

    public function handle(AccessSetup $setup): int
    {
        $result = $setup->run();

        if ($result['permissions'] === [] && $result['roles'] === []) {
            $this->info('Nothing to do -- roles and permissions are up to date.');

            return self::SUCCESS;
        }

        foreach ($result['roles'] as $role) {
            $this->line("  role created:       {$role}");
        }

        foreach ($result['permissions'] as $permission) {
            $this->line("  permission created: {$permission}");
        }

        return self::SUCCESS;
    }
}
