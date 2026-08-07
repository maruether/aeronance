<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Core\Models\Activity;
use App\Core\Models\BreakGlassRecord;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Housekeeping for the two logs -- decision E3 and F29.
 *
 * Everything else in this system is either stock or evidence and is never
 * cleaned up automatically. That is why each data class is switched on
 * individually in config/aeronance.php rather than a single retention period
 * with a list of exceptions: a misconfiguration then cannot reach the stock
 * movements, because there is no setting that would let it.
 *
 * Three jobs, all off by default:
 *
 *  - the activity log, three years, matching the record-keeping period;
 *  - break-glass records, five years, deliberately outliving the activity log
 *    since a privileged access is what one most wants to reconstruct;
 *  - pseudonymising former members, four weeks after they leave.
 *
 * The pseudonymisation deliberately leaves certificate content alone. A name in
 * a release is not an incidental personal datum but the content of the
 * certificate itself, and the duty to keep records takes precedence over the
 * right to erasure. See decision E3a.
 */
final class RetentionCommand extends Command
{
    protected $signature = 'aeronance:retention {--dry-run : report what would happen and change nothing}';

    protected $description = 'Apply the configured retention rules to the logs';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->comment('Dry run -- nothing will be changed.');
        }

        $this->applyActivityLogRetention($dryRun);
        $this->applyBreakGlassRetention($dryRun);
        $this->pseudonymiseFormerMembers($dryRun);

        return self::SUCCESS;
    }

    private function applyActivityLogRetention(bool $dryRun): void
    {
        $config = config('aeronance.retention.activity_log');

        if (! ($config['enabled'] ?? false)) {
            $this->line('  activity log:      disabled');

            return;
        }

        $cutoff = now()->subDays((int) $config['days']);
        $query = Activity::query()->where('created_at', '<', $cutoff);
        $count = $query->count();

        if ($count === 0) {
            $this->line('  activity log:      nothing older than '.$cutoff->format('d.m.Y'));

            return;
        }

        if (! $dryRun) {
            // The model refuses ordinary deletion on purpose; this is the one
            // named way past it, and the job records that it ran.
            $query->each(fn (Activity $entry) => $entry->forceRetentionDelete());

            activity('core')
                ->withProperties(['removed' => $count, 'older_than' => $cutoff->toDateString()])
                ->log('retention.activity_log');
        }

        $this->line(sprintf('  activity log:      %d entries older than %s%s',
            $count, $cutoff->format('d.m.Y'), $dryRun ? ' (would be removed)' : ' removed'));
    }

    private function applyBreakGlassRetention(bool $dryRun): void
    {
        $config = config('aeronance.retention.break_glass_log');

        if (! ($config['enabled'] ?? false)) {
            $this->line('  break-glass log:   disabled');

            return;
        }

        $cutoff = now()->subDays((int) $config['days']);
        $query = BreakGlassRecord::query()->where('granted_at', '<', $cutoff);
        $count = $query->count();

        if (! $dryRun && $count > 0) {
            $query->delete();

            activity('core')
                ->withProperties(['removed' => $count, 'older_than' => $cutoff->toDateString()])
                ->log('retention.break_glass');
        }

        $this->line(sprintf('  break-glass log:   %d records older than %s%s',
            $count, $cutoff->format('d.m.Y'), $dryRun ? ' (would be removed)' : ' removed'));
    }

    /**
     * Replaces the personal data of members who left, in the account and in the
     * activity log.
     *
     * What it does NOT touch: anything that is certificate content. Those
     * columns hold copies precisely so that this job cannot reach them -- there
     * is no foreign key to follow, and that is the mechanism rather than an
     * oversight.
     */
    private function pseudonymiseFormerMembers(bool $dryRun): void
    {
        $config = config('aeronance.retention.pseudonymise_former_members');

        if (! ($config['enabled'] ?? false)) {
            $this->line('  former members:    disabled');

            return;
        }

        $cutoff = now()->subDays((int) $config['days']);

        $candidates = User::query()
            ->where('is_active', false)
            ->whereNotNull('deactivated_at')
            ->where('deactivated_at', '<', $cutoff)
            ->where('email', 'not like', 'ehemalig-%')
            ->get();

        if ($candidates->isEmpty()) {
            $this->line('  former members:    none due');

            return;
        }

        foreach ($candidates as $user) {
            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($user): void {
                $placeholder = 'ehemaliges Mitglied #'.$user->id;

                $user->forceFill([
                    'name' => $placeholder,
                    'email' => 'ehemalig-'.$user->id.'@invalid.local',
                ])->saveQuietly();

                // The trail keeps its entries; only the name in them goes.
                Activity::query()
                    ->where('causer_type', $user::class)
                    ->where('causer_id', $user->id)
                    ->update(['causer_id' => null]);
            });

            activity('core')
                ->withProperties(['user_id' => $user->id])
                ->log('retention.pseudonymised');
        }

        $this->line(sprintf('  former members:    %d pseudonymised%s',
            $candidates->count(), $dryRun ? ' (would be)' : ''));
    }
}
