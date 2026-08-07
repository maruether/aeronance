<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Widgets;

use App\Modules\Warehouse\Models\PurchaseOrder;
use App\Modules\Warehouse\Permissions;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * „Diese Lieferungen sind überfällig."
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE RÜCKFALLEBENE DER ERINNERUNG, und deshalb gibt es sie doppelt.
 *
 * Gewollt ist erinnert werden — das ist der ganze Zweck des Bestellteils:
 * „ich bin gerade erst mit einem Lieferanten auf die nase gefallen der sich
 * nicht gemeldet hatte."
 *
 * Eine Erinnerung, die nur per Mail kommt, hängt an einem Mailserver. Der ist
 * bei einer frischen Installation gar nicht eingerichtet, sein Zugang kann
 * ablaufen, ein Postfach volllaufen. Dann fällt genau das aus, wofür das
 * Ganze gebaut wurde — und zwar still.
 *
 * Die Startseite braucht keinen Mailserver.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class OverdueOrders extends Widget
{
    protected string $view = 'warehouse.filament.widgets.overdue-orders';

    /** Über allem anderen: Wer hier steht, wartet auf etwas. */
    protected static ?int $sort = -20;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        if (! (auth()->user()?->can(Permissions::ORDERS_MANAGE) ?? false)) {
            return false;
        }

        return PurchaseOrder::query()->overdue()->exists();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var Collection<int, PurchaseOrder> $ueberfaellig */
        $ueberfaellig = PurchaseOrder::query()
            ->overdue()
            ->with(['supplier', 'lines'])
            ->orderBy('expected_at')
            ->get();

        return ['orders' => $ueberfaellig];
    }
}
