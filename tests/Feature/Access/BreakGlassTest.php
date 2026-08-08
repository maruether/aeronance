<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Core\Access\AccessSetup;
use App\Core\Access\CoreRoles;
use App\Core\Models\BreakGlassRecord;
use App\Models\User;
use App\Notifications\BreakGlassUsed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Decision E2: emergency access exists, runs through the console only, and
 * leaves a record that cannot be tidied away.
 */
final class BreakGlassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();
    }

    #[Test]
    public function it_grants_the_administrator_role_and_writes_a_record(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'vorstand@example.org']);

        $this->artisan('aeronance:break-glass', [
            'email' => 'vorstand@example.org',
            '--reason' => 'Alle Admins ausgesperrt nach fehlgeschlagenem Rollenumbau',
            '--hours' => 2,
        ])
            ->expectsConfirmation('Grant vorstand@example.org administrator access for 2 hours?', 'yes')
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->hasRole(CoreRoles::ADMIN));

        $record = BreakGlassRecord::sole();
        $this->assertSame('vorstand@example.org', $record->target_email);
        $this->assertStringContainsString('ausgesperrt', $record->reason);
        $this->assertNotNull($record->granted_at);
        $this->assertNotNull($record->expires_at);
        $this->assertTrue($record->isActive());
    }

    #[Test]
    public function it_records_who_ran_it(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'vorstand@example.org']);

        $this->artisan('aeronance:break-glass', [
            'email' => 'vorstand@example.org',
            '--reason' => 'Test',
        ])->expectsConfirmation(
            'Grant vorstand@example.org administrator access for 4 hours?', 'yes'
        )->assertSuccessful();

        $record = BreakGlassRecord::sole();

        // The shell user and hostname are always available; the origin address
        // only over SSH, and its absence is a normal outcome.
        $this->assertNotNull($record->shell_user);
        $this->assertNotNull($record->hostname);
    }

    #[Test]
    public function it_refuses_without_a_reason(): void
    {
        User::factory()->create(['email' => 'vorstand@example.org']);

        $this->artisan('aeronance:break-glass', [
            'email' => 'vorstand@example.org',
            '--reason' => '   ',
        ])->assertFailed();

        $this->assertSame(0, BreakGlassRecord::count());
    }

    #[Test]
    public function declining_the_confirmation_changes_nothing(): void
    {
        $user = User::factory()->create(['email' => 'vorstand@example.org']);

        $this->artisan('aeronance:break-glass', [
            'email' => 'vorstand@example.org',
            '--reason' => 'Versehen',
        ])->expectsConfirmation(
            'Grant vorstand@example.org administrator access for 4 hours?', 'no'
        )->assertSuccessful();

        $this->assertFalse($user->fresh()->hasRole(CoreRoles::ADMIN));
        $this->assertSame(0, BreakGlassRecord::count());
    }

    #[Test]
    public function it_notifies_the_other_administrators(): void
    {
        Notification::fake();

        $other = User::factory()->create(['email' => 'admin@example.org']);
        $other->assignRole(CoreRoles::ADMIN);

        User::factory()->create(['email' => 'vorstand@example.org']);

        $this->artisan('aeronance:break-glass', [
            'email' => 'vorstand@example.org',
            '--reason' => 'Test',
        ])->expectsConfirmation(
            'Grant vorstand@example.org administrator access for 4 hours?', 'yes'
        )->assertSuccessful();

        Notification::assertSentTo($other, BreakGlassUsed::class);
    }

    #[Test]
    public function a_failing_mail_server_does_not_prevent_the_access(): void
    {
        // The situation break-glass exists for is one where things are already
        // broken -- quite possibly mail among them. The record and the grant
        // must survive that.
        $other = User::factory()->create(['email' => 'admin@example.org']);
        $other->assignRole(CoreRoles::ADMIN);

        $user = User::factory()->create(['email' => 'vorstand@example.org']);

        Mail::shouldReceive('mailer')->andThrow(new \RuntimeException('SMTP down'));

        $this->artisan('aeronance:break-glass', [
            'email' => 'vorstand@example.org',
            '--reason' => 'Mailserver ebenfalls defekt',
        ])->expectsConfirmation(
            'Grant vorstand@example.org administrator access for 4 hours?', 'yes'
        )->assertSuccessful();

        $this->assertTrue($user->fresh()->hasRole(CoreRoles::ADMIN));
        $this->assertSame(1, BreakGlassRecord::count());
    }

    #[Test]
    public function it_reactivates_a_deactivated_account(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'vorstand@example.org',
            'is_active' => false,
        ]);

        $this->artisan('aeronance:break-glass', [
            'email' => 'vorstand@example.org',
            '--reason' => 'Konto versehentlich deaktiviert',
        ])->expectsConfirmation(
            'Grant vorstand@example.org administrator access for 4 hours?', 'yes'
        )->assertSuccessful();

        $this->assertTrue($user->fresh()->is_active);
    }

    #[Test]
    public function the_grant_lapses_by_itself_once_the_hours_are_up(): void
    {
        // --hours war eine Zeit lang nur eine Notiz im Datensatz: Der Zugang
        // blieb bestehen, bis jemand von Hand widerrief. Eine Frist, die
        // nicht ablaeuft, ist ein Versprechen, das keiner haelt.
        Notification::fake();
        $user = User::factory()->create(['email' => 'vorstand@example.org']);

        $this->artisan('aeronance:break-glass', [
            'email' => 'vorstand@example.org',
            '--reason' => 'Test',
            '--hours' => 2,
        ])->expectsConfirmation(
            'Grant vorstand@example.org administrator access for 2 hours?', 'yes'
        )->assertSuccessful();

        // Noch in der Frist: Der Lauf tut nichts.
        $this->artisan('aeronance:break-glass-expire')->assertSuccessful();
        $this->assertTrue($user->fresh()->hasRole(CoreRoles::ADMIN));

        $this->travel(3)->hours();

        $this->artisan('aeronance:break-glass-expire')->assertSuccessful();

        $this->assertFalse($user->fresh()->hasRole(CoreRoles::ADMIN));

        $record = BreakGlassRecord::sole();
        $this->assertNotNull($record->revoked_at, 'Der Datensatz ist als beendet markiert.');
        $this->assertSame(1, BreakGlassRecord::count(), 'Der Datensatz selbst bleibt.');
    }

    #[Test]
    public function an_overlapping_grant_keeps_the_role_alive(): void
    {
        // Zwei Gewaehrungen fuer dasselbe Konto: Die ablaufende darf der
        // laengeren nicht den Boden wegziehen.
        Notification::fake();
        $user = User::factory()->create(['email' => 'vorstand@example.org']);

        $this->artisan('aeronance:break-glass', [
            'email' => 'vorstand@example.org', '--reason' => 'Erster Grund', '--hours' => 1,
        ])->expectsConfirmation(
            'Grant vorstand@example.org administrator access for 1 hours?', 'yes'
        )->assertSuccessful();

        $this->artisan('aeronance:break-glass', [
            'email' => 'vorstand@example.org', '--reason' => 'Zweiter Grund', '--hours' => 8,
        ])->expectsConfirmation(
            'Grant vorstand@example.org administrator access for 8 hours?', 'yes'
        )->assertSuccessful();

        $this->travel(2)->hours();

        $this->artisan('aeronance:break-glass-expire')->assertSuccessful();

        $this->assertTrue(
            $user->fresh()->hasRole(CoreRoles::ADMIN),
            'Die laengere Gewaehrung traegt den Zugang weiter.',
        );
        $this->assertSame(1, BreakGlassRecord::query()->whereNotNull('revoked_at')->count());
    }

    #[Test]
    public function revoking_ends_the_access_but_keeps_the_record(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'vorstand@example.org']);

        $this->artisan('aeronance:break-glass', [
            'email' => 'vorstand@example.org',
            '--reason' => 'Test',
        ])->expectsConfirmation(
            'Grant vorstand@example.org administrator access for 4 hours?', 'yes'
        )->assertSuccessful();

        $record = BreakGlassRecord::sole();

        $this->artisan('aeronance:break-glass-revoke', ['id' => $record->id])->assertSuccessful();

        $this->assertFalse($user->fresh()->hasRole(CoreRoles::ADMIN));

        // What happened stays on file -- only the access ends.
        $this->assertSame(1, BreakGlassRecord::count());
        $this->assertNotNull($record->fresh()->revoked_at);
        $this->assertFalse($record->fresh()->isActive());
    }

    #[Test]
    public function it_refuses_an_unknown_account(): void
    {
        $this->artisan('aeronance:break-glass', [
            'email' => 'niemand@example.org',
            '--reason' => 'Test',
        ])->assertFailed();

        $this->assertSame(0, BreakGlassRecord::count());
    }
}
