<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Core\Access\CoreRoles;
use App\Core\Models\BreakGlassRecord;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Withdraws an emergency grant again.
 *
 * The record is not deleted -- it is marked as withdrawn. What happened stays
 * on file; only the access ends.
 */
final class BreakGlassRevokeCommand extends Command
{
    protected $signature = 'aeronance:break-glass-revoke {id : the record to withdraw}';

    protected $description = 'Withdraw an emergency access grant';

    public function handle(): int
    {
        $record = BreakGlassRecord::find($this->argument('id'));

        if ($record === null) {
            $this->error('No such record.');

            return self::FAILURE;
        }

        if ($record->revoked_at !== null) {
            $this->info(sprintf('Already withdrawn on %s.', $record->revoked_at->format('d.m.Y H:i')));

            return self::SUCCESS;
        }

        $record->update(['revoked_at' => now()]);

        $user = User::find($record->target_user_id);

        if ($user !== null) {
            $user->removeRole(CoreRoles::ADMIN);
            $this->info(sprintf('Administrator role withdrawn from %s.', $user->email));
        } else {
            $this->warn('The account no longer exists; the record was marked as withdrawn.');
        }

        return self::SUCCESS;
    }
}
