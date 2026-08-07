<?php

declare(strict_types=1);

namespace App\Modules\Directives\Sources;

use App\Modules\Directives\Models\SourceCredential;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Where a gated source's login actually comes from.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Two places may hold it, and the order between them is a decision:
 *
 *   1. The ENVIRONMENT (.env, a Docker secret, a systemd credential). If it is
 *      set there it wins. An operator who pins a value outside the application
 *      means it: it survives redeploys, it is managed by whatever manages the
 *      server, and a Docker installation must not depend on somebody opening the
 *      panel first.
 *
 *   2. Otherwise the DATABASE, where a person typed it into the panel. This is
 *      the path for a club whose committee has no shell on the server -- which
 *      is most of them, and the reason the credentials table exists at all.
 *
 * WHY THE APPLICATION DOES NOT WRITE THE .env ITSELF, which is the obvious
 * shortcut and was considered:
 *
 *   - It would need write access to its own configuration. That turns any
 *     file-write flaw into "attacker rewrites APP_KEY and the database
 *     credentials", because they live in the same file. The web user having no
 *     write access to .env is a property worth keeping.
 *   - It does not survive `php artisan config:cache`, which deploy/update.sh
 *     runs on every update: with a cached config, env() returns null and the
 *     credentials vanish -- verified, not assumed.
 *   - Docker and the LXC channel mount configuration read-only or inject it as
 *     secrets; writing at runtime breaks both.
 *
 * So the environment stays read-only and authoritative, and anything a user
 * types goes to the database, encrypted.
 *
 * ENVIRONMENT VALUES ARE READ THROUGH config(), NOT env(). Calling env() outside
 * a config file returns null as soon as the config is cached -- the exact
 * failure above. config/aeronance.php captures the DIRECTIVES_* variables at
 * cache time, which is the only way a value from .env survives into a cached
 * production install.
 *
 * The database read is guarded: before the migration has run -- during setup, or
 * in a test that never needed this table -- the query throws, and a missing
 * table must degrade to "no stored credentials" rather than take a source down.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SourceCredentials
{
    /**
     * The username and password for a profile, or nulls if it has neither.
     *
     * @return array{0: ?string, 1: ?string} user, password
     */
    public function for(?string $profile): array
    {
        if ($profile === null || $profile === '') {
            return [null, null];
        }

        [$envUser, $envPassword] = $this->fromEnvironment($profile);

        // The environment wins only when it supplies BOTH halves. A half-set
        // profile is a misconfiguration rather than an override, and falling
        // through to the database is the kinder reading of it.
        if (filled($envUser) && filled($envPassword)) {
            return [$envUser, $envPassword];
        }

        $stored = $this->fromDatabase($profile);

        if ($stored !== null) {
            return $stored;
        }

        // Neither place had both -- hand back whatever the environment did have,
        // so a "credentials missing" message can still say what is present.
        return [$envUser, $envPassword];
    }

    /** Whether a profile has a usable login from either place. */
    public function has(?string $profile): bool
    {
        [$user, $password] = $this->for($profile);

        return filled($user) && filled($password);
    }

    /**
     * Whether the environment is what is actually in force for this profile.
     *
     * The panel uses this to say "vorgegeben durch die Umgebung" instead of
     * letting somebody maintain a database value that will never be read.
     */
    public function isFromEnvironment(?string $profile): bool
    {
        if ($profile === null || $profile === '') {
            return false;
        }

        [$user, $password] = $this->fromEnvironment($profile);

        return filled($user) && filled($password);
    }

    /**
     * Stores what a person typed in the panel.
     *
     * An empty password means "leave the stored one alone" -- the panel never
     * shows a password back, so an untouched field arrives empty and must not
     * wipe a working login.
     */
    public function store(string $profile, string $username, ?string $password, ?int $userId = null): void
    {
        $existing = SourceCredential::query()->where('profile', $profile)->first();

        if ($password === null || $password === '') {
            if ($existing === null) {
                return;
            }

            $existing->update(['username' => $username, 'updated_by' => $userId]);

            return;
        }

        SourceCredential::query()->updateOrCreate(
            ['profile' => $profile],
            ['username' => $username, 'password' => $password, 'updated_by' => $userId],
        );
    }

    /** Removes a stored login. The environment, if it has one, is untouched. */
    public function forget(string $profile): void
    {
        SourceCredential::query()->where('profile', $profile)->delete();
    }

    /**
     * The username on file, for showing in the panel. Never the password.
     */
    public function username(?string $profile): ?string
    {
        [$user] = $this->for($profile);

        return $user;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function fromEnvironment(string $profile): array
    {
        $prefix = 'DIRECTIVES_'.strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $profile) ?? '');

        /** @var array<string, string> $fromConfig */
        $fromConfig = config('aeronance.directive_credentials', []);

        return [
            $fromConfig[$prefix.'_USER'] ?? null,
            $fromConfig[$prefix.'_PASSWORD'] ?? null,
        ];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function fromDatabase(string $profile): ?array
    {
        try {
            $row = SourceCredential::query()->where('profile', $profile)->first();
        } catch (QueryException|Throwable) {
            // No table yet (pre-setup), or the database is unreachable. Either
            // way there are no stored credentials -- a source without a login
            // reports itself unusable, it does not crash the request.
            return null;
        }

        if ($row === null || ! filled($row->username) || ! filled($row->password)) {
            return null;
        }

        return [$row->username, $row->password];
    }
}
