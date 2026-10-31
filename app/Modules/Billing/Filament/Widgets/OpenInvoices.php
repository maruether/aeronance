<?php

declare(strict_types=1);

namespace App\Modules\Billing\Filament\Widgets;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Permissions;
use App\Modules\Billing\Support\Money;
use Filament\Widgets\Widget;

/**
 * Offene Rechnungen auf der Startseite -- wie viele, wie viel, wie viele
 * davon überfällig. Erscheint nur, wenn es welche gibt: Eine Zahl, die immer
 * dasteht, liest nach einer Woche niemand mehr.
 */
final class OpenInvoices extends Widget
{
    protected string $view = 'billing.filament.widgets.open-invoices';

    protected static ?int $sort = -15;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        if (! (auth()->user()?->can(Permissions::VIEW) ?? false)) {
            return false;
        }

        return Invoice::query()->open()->exists();
    }

    protected function getViewData(): array
    {
        $open = Invoice::query()->open()->get();

        return [
            'count' => $open->count(),
            'overdue' => $open->filter(static fn (Invoice $i): bool => $i->isOverdue())->count(),
            'outstanding' => Money::format($open->reduce(
                static fn (string $sum, Invoice $i): string => Money::add($sum, $i->outstandingAmount()),
                '0.00',
            )),
        ];
    }
}
