<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Console;

use App\Core\Modules\ModuleManager;
use App\Modules\Warehouse\Actions\ExpireStock;
use App\Modules\Warehouse\Models\StockLot;
use Illuminate\Console\Command;

/**
 * Marks stock unserviceable once its shelf life has run out.
 *
 * Meant for the daily scheduler. Safe to run by hand and safe to run twice: a
 * lot already unserviceable is not picked up again.
 */
final class ExpireStockCommand extends Command
{
    protected $signature = 'aeronance:expire-stock {--dry-run : report what would happen and change nothing}';

    protected $description = 'Mark lots whose shelf life has run out as unserviceable';

    public function handle(ExpireStock $action, ModuleManager $modules): int
    {
        // Module boundaries hold for scheduled work too -- a disabled module
        // stops its jobs rather than quietly carrying on in the background.
        if (! $modules->isEnabled('warehouse')) {
            $this->comment('The warehouse module is not enabled -- nothing to do.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->comment('Dry run -- nothing will be changed.');
        }

        $changed = $action->run(dryRun: $dryRun);

        if ($changed === []) {
            $this->info('Nothing has expired.');

            return self::SUCCESS;
        }

        $this->table(
            ['Lot', 'Part', 'Expired'],
            array_map(fn (StockLot $lot): array => [
                $lot->lot_number,
                $lot->partType?->name ?? '?',
                $lot->expires_at?->format('d.m.Y') ?? '',
            ], $changed),
        );

        $this->info(sprintf('%d lot(s) marked unserviceable.', count($changed)));

        return self::SUCCESS;
    }
}
