<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Widgets;

use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * „Was steht an" -- auf der Startseite, wo es hingehört.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Feldtest: "diese ‚Was steht an' ist übrigens nix für ne seite im lager
 * sondern für das dashboard."
 *
 * Und das trifft den Zweck: Eine Liste offener Punkte hilft nur, wenn man sie
 * SIEHT, ohne sie zu suchen. Als Lagerseite musste jemand daran denken, sie
 * aufzurufen -- und wer daran denkt, weiß meist ohnehin schon, was ansteht.
 *
 * Dasselbe Muster wie bei den überfälligen Bestellungen (OverdueOrders): Die
 * Startseite braucht keine Erinnerung. Die Abfragen bleiben, wo sie waren --
 * in StockAttention, jetzt als reine Datenquelle ohne eigene Seite.
 *
 * NICHTS ZU TUN HEISST NICHTS ANZEIGEN: Ein Widget, das täglich „alles in
 * Ordnung" meldet, wird nach einer Woche überlesen -- und dann auch an dem
 * Tag, an dem etwas drinsteht.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class StockAttentionWidget extends Widget
{
    protected string $view = 'warehouse.filament.widgets.stock-attention';

    /** Direkt unter den überfälligen Bestellungen. */
    protected static ?int $sort = -15;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        if (! (auth()->user()?->can(Permissions::STOCK_VIEW) ?? false)) {
            return false;
        }

        return self::hasAnything();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'withoutCertificate' => self::withoutCertificate(),
            'expired' => self::expiredLots(),
            'below' => self::belowMinimum(),
            'blocked' => self::blockedLots(),
            'noDocs' => self::missingDocuments(),
        ];
    }

    private static function hasAnything(): bool
    {
        return self::withoutCertificate()->isNotEmpty()
            || self::expiredLots()->isNotEmpty()
            || self::belowMinimum()->isNotEmpty()
            || self::blockedLots()->isNotEmpty()
            || self::missingDocuments()->isNotEmpty();
    }

    /** @return Collection<int, StockLot> */
    public static function expiredLots(): Collection
    {
        return StockLot::query()
            ->with('partType')
            ->where('state', '!=', 'disposed')
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', now())
            ->withRemainingQuantity()
            ->get()
            ->filter(fn (StockLot $lot): bool => $lot->remainingQuantity() > 0)
            ->values();
    }

    /** @return Collection<int, PartType> */
    public static function belowMinimum(): Collection
    {
        return PartType::query()
            ->belowMinimum()
            ->with('supplier')
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, StockLot> */
    public static function blockedLots(): Collection
    {
        return StockLot::query()
            ->with(['partType', 'stateChanges'])
            ->whereIn('state', ['quarantined', 'unserviceable', 'unsalvageable'])
            ->withRemainingQuantity()
            ->get()
            ->filter(fn (StockLot $lot): bool => $lot->remainingQuantity() > 0)
            ->values();
    }

    /**
     * Form-1-Ware ganz ohne Nachweis -- gesperrt, nicht bloß unvollständig.
     *
     * @return Collection<int, StockLot>
     */
    public static function withoutCertificate(): Collection
    {
        return StockLot::query()
            ->with('partType')
            ->whereHas('partType', fn ($q) => $q->where('requires_form_one', true))
            ->whereNotIn('state', ['disposed', 'unsalvageable'])
            ->withRemainingQuantity()
            ->get()
            ->filter(fn (StockLot $lot): bool => $lot->remainingQuantity() > 0
                && ! $lot->hasRequiredDocument()
                && ! $lot->isRestrictedToItsAircraft())
            ->values();
    }

    /**
     * Nummer erfasst, Scan fehlt -- die Audit-Mahnung.
     *
     * @return Collection<int, StockLot>
     */
    public static function missingDocuments(): Collection
    {
        return StockLot::query()
            ->with('partType')
            ->whereHas('partType', fn ($q) => $q->where('requires_form_one', true))
            ->whereNotIn('state', ['disposed'])
            ->withRemainingQuantity()
            ->get()
            ->filter(fn (StockLot $lot): bool => $lot->remainingQuantity() > 0
                && $lot->hasRequiredDocument()
                && ! $lot->hasDocumentFile())
            ->values();
    }
}
