<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Core\Access\CoreRoles;
use App\Core\Models\BreakGlassRecord;
use App\Models\User;
use App\Notifications\BreakGlassUsed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Emergency access -- decision E2.
 *
 * Console only, deliberately. This has no place in the interface: the ordinary
 * administrative need is covered by the admin role, and putting it behind a
 * shell means an attacker holding a hijacked web session cannot reach it at all.
 *
 * The situation this exists for is the one where things are already broken --
 * the last administrator locked out, a role setup that went wrong, an identity
 * provider that no longer answers. Two consequences follow:
 *
 *  - The record is written FIRST and independently. The notification is a
 *    courtesy and may fail; mail is quite possibly among the things that are
 *    broken. It must never prevent the access from being granted.
 *  - A missing environment detail is never fatal. There is no client address
 *    for a console command; SSH_CONNECTION gives one when the command arrived
 *    over SSH, and someone at the server console leaves none. That field stays
 *    empty rather than blocking the command.
 */
final class BreakGlassCommand extends Command
{
    protected $signature = 'aeronance:break-glass
                            {email : the account to be granted administrator access}
                            {--reason= : why this is necessary (recorded)}
                            {--hours=4 : how long the grant lasts}';

    protected $description = 'Grant emergency administrator access. Recorded and reported.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        $reason = (string) ($this->option('reason') ?: $this->ask('Why is emergency access needed? (recorded)'));

        if (trim($reason) === '') {
            $this->error('A reason is required. It is the point of the record.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error(sprintf('No account found for "%s".', $email));

            return self::FAILURE;
        }

        $hours = max(1, (int) $this->option('hours'));

        $this->warn('Emergency access grants full administrator rights and is recorded permanently.');

        if (! $this->confirm(sprintf('Grant %s administrator access for %d hours?', $email, $hours), false)) {
            $this->info('Cancelled. Nothing was changed.');

            return self::SUCCESS;
        }

        // The record comes first, before anything is granted: if the process
        // dies halfway, the attempt is on file rather than the grant being
        // invisible.
        $record = BreakGlassRecord::create([
            'target_email' => $user->email,
            'target_user_id' => $user->id,
            'shell_user' => $this->shellUser(),
            'origin_ip' => $this->sshOrigin(),
            'hostname' => gethostname() ?: null,
            'reason' => trim($reason),
            'granted_at' => now(),
            'expires_at' => now()->addHours($hours),
        ]);

        $user->assignRole(CoreRoles::ADMIN);

        if (! $user->is_active) {
            $user->update(['is_active' => true, 'deactivated_at' => null]);
        }

        $this->info(sprintf('Granted. Record #%d, valid until %s.',
            $record->id,
            $record->expires_at->timezone(config('aeronance.organisation.timezone'))->format('d.m.Y H:i'),
        ));

        $this->notifyOtherAdmins($record);

        $this->line('');
        $this->line('The grant does not lapse on its own -- withdraw the role once the');
        $this->line('cause has been dealt with:');
        $this->line(sprintf('  php artisan aeronance:break-glass-revoke %d', $record->id));

        return self::SUCCESS;
    }

    private function shellUser(): ?string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());

            if (is_array($info) && isset($info['name'])) {
                return (string) $info['name'];
            }
        }

        return getenv('USER') ?: getenv('LOGNAME') ?: null;
    }

    /**
     * Origin address, when the command arrived over SSH.
     *
     * SSH_CONNECTION holds "client-ip client-port server-ip server-port". At the
     * server console it is absent, and that is a normal outcome, not an error.
     */
    private function sshOrigin(): ?string
    {
        $connection = getenv('SSH_CONNECTION') ?: getenv('SSH_CLIENT');

        if ($connection === false || $connection === '') {
            return null;
        }

        $ip = strtok($connection, ' ');

        return $ip === false ? null : $ip;
    }

    /**
     * Best effort, and explicitly allowed to fail: this runs when things are
     * already broken, and mail may be one of them.
     */
    private function notifyOtherAdmins(BreakGlassRecord $record): void
    {
        try {
            $admins = User::query()
                ->role(CoreRoles::ADMIN)
                ->where('id', '!=', $record->target_user_id)
                ->where('is_active', true)
                ->get();

            if ($admins->isEmpty()) {
                $this->comment('No other administrators to notify.');

                return;
            }

            Notification::send($admins, new BreakGlassUsed($record));
            $this->comment(sprintf('%d administrator(s) notified.', $admins->count()));
        } catch (Throwable $e) {
            // Deliberately swallowed: the record is written, the access is
            // granted, and a failing mail server must not undo either.
            $this->warn('Could not notify the other administrators: '.$e->getMessage());
            $this->warn('The record was written regardless.');
        }
    }
}
