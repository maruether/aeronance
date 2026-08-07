<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Modules\Directives\Models\SourceCredential;
use App\Modules\Directives\Sources\SourceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * A club's login for a gated manufacturer.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Two promises are made about these credentials, and a promise about a secret is
 * worth exactly as much as the test under it:
 *
 *   - they are ENCRYPTED at rest, so the nightly database backup does not hand
 *     somebody Schempp-Hirth's password in the clear;
 *   - the audit trail records THAT they changed and never WHAT to.
 *
 * The third thing tested here is the precedence. The environment wins, so a
 * Docker installation is self-sufficient and never depends on somebody opening
 * the panel -- and a club with no shell can still get going through the panel.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SourceCredentialsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach (['DIRECTIVES_PROBE_USER', 'DIRECTIVES_PROBE_PASSWORD'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        config(['aeronance.directive_credentials' => []]);

        parent::tearDown();
    }

    #[Test]
    public function the_password_is_encrypted_in_the_table(): void
    {
        app(SourceCredentials::class)->store('probe', 'hans', 'geheim123');

        $raw = DB::table('directive_credentials')->where('profile', 'probe')->first();

        // The literal check, because this is the one that matters: a dump of this
        // table must not contain the password. Both columns, since a username can
        // be an email address worth as little to leak as a password.
        $this->assertStringNotContainsString('geheim123', (string) $raw->password);
        $this->assertStringNotContainsString('hans', (string) $raw->username);

        // And it still comes back out, because encrypted is not hashed -- the
        // password has to be sendable to the manufacturer.
        $this->assertSame(['hans', 'geheim123'], app(SourceCredentials::class)->for('probe'));
    }

    #[Test]
    public function a_change_is_audited_without_the_secret(): void
    {
        $credentials = app(SourceCredentials::class);
        $credentials->store('probe', 'hans', 'erstes-passwort');
        $credentials->store('probe', 'hans', 'zweites-passwort');

        $entries = Activity::query()->where('log_name', 'directive_credentials')->get();

        /*
         * Two entries -- and the second is the reason logOnlyDirty() is NOT used
         * here. A password change touches only the encrypted column, so "only
         * dirty" found no logged attribute dirty and wrote nothing at all. A
         * credential change that leaves no trace is precisely the one an auditor
         * asks about.
         */
        $this->assertCount(2, $entries);

        foreach ($entries as $entry) {
            $json = json_encode($entry->properties);

            $this->assertStringNotContainsString('erstes-passwort', (string) $json);
            $this->assertStringNotContainsString('zweites-passwort', (string) $json);
        }
    }

    #[Test]
    public function the_environment_wins_over_the_database(): void
    {
        // Both places set, differently. An operator who pinned a value outside
        // the application means it: it survives redeploys and is managed by
        // whatever manages the server.
        app(SourceCredentials::class)->store('probe', 'db-user', 'db-passwort');

        config(['aeronance.directive_credentials' => [
            'DIRECTIVES_PROBE_USER' => 'env-user',
            'DIRECTIVES_PROBE_PASSWORD' => 'env-passwort',
        ]]);

        $this->assertSame(['env-user', 'env-passwort'], app(SourceCredentials::class)->for('probe'));
        $this->assertTrue(app(SourceCredentials::class)->isFromEnvironment('probe'));
    }

    #[Test]
    public function a_half_set_environment_falls_through_rather_than_locking_out(): void
    {
        // A username with no password is a misconfiguration, not an override.
        // Treating it as one would leave a club unable to log in and unable to
        // see why -- the panel would report the environment as authoritative.
        app(SourceCredentials::class)->store('probe', 'db-user', 'db-passwort');

        config(['aeronance.directive_credentials' => [
            'DIRECTIVES_PROBE_USER' => 'env-user',
        ]]);

        $this->assertSame(['db-user', 'db-passwort'], app(SourceCredentials::class)->for('probe'));
        $this->assertFalse(app(SourceCredentials::class)->isFromEnvironment('probe'));
    }

    #[Test]
    public function an_empty_password_keeps_the_stored_one(): void
    {
        /*
         * The panel never shows a password back, so an untouched field arrives
         * empty. If that wiped the stored password, every edit of the username
         * would silently break the login -- and the next weekly fetch would
         * report "Zugangsdaten fehlen" for a source that worked yesterday.
         */
        $credentials = app(SourceCredentials::class);
        $credentials->store('probe', 'hans', 'geheim123');

        $credentials->store('probe', 'hans-neu', null);

        $this->assertSame(['hans-neu', 'geheim123'], $credentials->for('probe'));
    }

    #[Test]
    public function a_missing_table_reports_no_credentials_rather_than_failing(): void
    {
        // Before the migration has run -- during setup -- reading credentials
        // must degrade to "none stored". A source without a login says so; it
        // does not take the request down with it.
        DB::statement('DROP TABLE directive_credentials');

        $this->assertSame([null, null], app(SourceCredentials::class)->for('probe'));
        $this->assertFalse(app(SourceCredentials::class)->has('probe'));
    }

    #[Test]
    public function forgetting_removes_the_row(): void
    {
        $credentials = app(SourceCredentials::class);
        $credentials->store('probe', 'hans', 'geheim123');

        $credentials->forget('probe');

        $this->assertFalse($credentials->has('probe'));
        $this->assertSame(0, SourceCredential::query()->where('profile', 'probe')->count());
    }
}
