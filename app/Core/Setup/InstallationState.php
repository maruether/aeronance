<?php

declare(strict_types=1);

namespace App\Core\Setup;

use App\Core\Access\CoreRoles;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Whether this installation has been set up, and how far.
 *
 * The marker is a file rather than a database row, deliberately: the wizard has
 * to work when the database is not reachable yet, which is the very state it
 * exists for. Its presence is the lock -- once written, the setup routes are
 * gone for good.
 *
 * The marker is checked on the file system every time and not cached: a cached
 * "not installed yet" would leave the wizard reachable after installation,
 * which is exactly the open door the guardrails warn about.
 */
final class InstallationState
{
    private const MARKER = 'installed';

    public function isInstalled(): bool
    {
        return file_exists($this->markerPath());
    }

    /**
     * Locks the wizard for good.
     */
    public function markInstalled(): void
    {
        $directory = dirname($this->markerPath());

        if (! is_dir($directory)) {
            mkdir($directory, 0o750, recursive: true);
        }

        file_put_contents($this->markerPath(), implode("\n", [
            'Aeronance installiert am '.now()->toIso8601String(),
            '',
            'Diese Datei verriegelt den Setup-Assistenten. Wird sie gelöscht, ist der',
            'Assistent wieder erreichbar und kann ein weiteres Administratorkonto',
            'anlegen -- das ist ein vollwertiger Zugang zum System. Nur entfernen,',
            'wenn genau das beabsichtigt ist.',
            '',
        ]));
    }

    public function markerPath(): string
    {
        return storage_path(self::MARKER);
    }

    /**
     * Whether the database can be reached at all.
     */
    public function canReachDatabase(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function isMigrated(): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable('users')
                && DB::getSchemaBuilder()->hasTable('modules');
        } catch (Throwable) {
            return false;
        }
    }

    public function hasAdministrator(): bool
    {
        try {
            return User::query()->role(CoreRoles::ADMIN)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether the database credentials come from the environment already.
     *
     * In the Docker and LXC channels they are handed in, and the wizard skips
     * that step instead of asking for something the operator has already given.
     */
    public function databaseIsPreconfigured(): bool
    {
        return env('DB_DATABASE') !== null
            && env('DB_USERNAME') !== null
            && $this->canReachDatabase();
    }

    /**
     * A safety net, not a substitute for the marker.
     *
     * If someone deletes the marker on a system that is plainly in use, the
     * wizard must not simply reopen -- so an installation that already has an
     * administrator counts as installed regardless.
     */
    public function looksInUse(): bool
    {
        return $this->isMigrated() && $this->hasAdministrator();
    }
}
