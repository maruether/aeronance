<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Http;

use App\Core\Modules\ModuleManager;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StockMovement;
use App\Modules\Warehouse\Models\StorageLocation;
use App\Modules\Warehouse\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The inventory report -- stock as it stood on a given day.
 *
 * A stocktake is by definition a statement AS OF a date, and usually a date
 * that has passed by the time anyone gets round to counting: the shelves are
 * walked in January, the question is what was there on the 31st of December.
 *
 * That question is exactly answerable here, not estimated, because stock is the
 * sum of its movements and nothing is ever overwritten. Everything booked since
 * the cut-off is simply excluded from the sum. The predecessor, which
 * overwrote a quantity column, could not have answered it at all.
 *
 * Six sections, printed as one document -- not six reports. It goes in a folder.
 */
final class InventoryReportController
{
    public function __invoke(Request $request): View
    {
        abort_unless(app(ModuleManager::class)->isEnabled('warehouse'), 404);
        abort_unless($request->user()?->can(Permissions::STOCK_REPORT) ?? false, 403);

        $asOf = $this->asOfDate($request);
        $locationId = $request->query('location');
        $withZero = $request->boolean('zero');
        $withJournal = $request->boolean('journal');

        $parts = $this->partsInScope($locationId);

        return view('warehouse.reports.inventory', [
            'asOf' => $asOf,
            'location' => $locationId !== null ? StorageLocation::find($locationId) : null,
            'club' => config('aeronance.organisation.name'),
            'sections' => [
                'stock' => $this->stockSection($parts, $asOf, $withZero),
                'shortfalls' => $this->shortfalls($parts, $asOf),
                'expiry' => $this->expiry($asOf),
                'blocked' => $this->blocked(),
                'missingEvidence' => $this->missingEvidence(),
                'journal' => $withJournal ? $this->journal($request, $asOf) : null,
            ],
        ]);
    }

    private function asOfDate(Request $request): Carbon
    {
        $value = $request->query('as_of');

        // A cut-off in the future would mix in bookings that have not happened
        // yet in any meaningful sense; today is as far as it goes.
        $date = $value !== null ? Carbon::parse((string) $value) : now();

        return $date->isFuture() ? now()->startOfDay() : $date->startOfDay();
    }

    /**
     * @return Collection<int, PartType>
     */
    private function partsInScope(?string $locationId): Collection
    {
        return PartType::query()
            ->with(['storageCompartment.location', 'supplier'])
            ->when($locationId, fn ($q) => $q->whereHas(
                'storageCompartment',
                fn ($c) => $c->where('storage_location_id', $locationId),
            ))
            ->get()
            ->sortBy([
                fn (PartType $p) => $p->storageCompartment?->location?->name ?? 'zzz',
                fn (PartType $p) => $p->storageCompartment?->name ?? 'zzz',
                fn (PartType $p) => $p->name,
            ]);
    }

    /**
     * The stocktake itself.
     *
     * Available and blocked are separate columns because when counting a shelf
     * both are in the same hand, while in usability they are worlds apart.
     *
     * @param  Collection<int, PartType>  $parts
     * @return list<array<string, mixed>>
     */
    private function stockSection(Collection $parts, Carbon $asOf, bool $withZero): array
    {
        $rows = [];

        foreach ($parts as $part) {
            $total = $part->stockAsOf($asOf->toDateString());

            if (! $withZero && abs($total) < 0.0005) {
                continue;
            }

            // Lots that actually held something on the day in question --
            // including ones since emptied, which is the whole point of a
            // cut-off date.
            $lots = $part->lots()
                ->with('movements')
                ->get()
                ->map(fn (StockLot $lot): array => [
                    'lot' => $lot,
                    'quantity' => $lot->remainingQuantityAsOf($asOf->toDateString()),
                ])
                ->filter(fn (array $row): bool => $row['quantity'] > 0.0005)
                ->values()
                ->all();

            $blocked = array_sum(array_map(
                fn (array $row): float => $row['lot']->state->allowsIssue() ? 0.0 : $row['quantity'],
                $lots,
            ));

            $rows[] = [
                'part' => $part,
                'total' => $total,
                'blocked' => $blocked,
                'available' => $total - $blocked,
                'lots' => $lots,
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, PartType>  $parts
     * @return list<array<string, mixed>>
     */
    private function shortfalls(Collection $parts, Carbon $asOf): array
    {
        $rows = [];

        foreach ($parts as $part) {
            if ($part->minimum_stock === null) {
                continue;
            }

            $available = $part->availableStock();

            if ($available >= $part->minimum_stock) {
                continue;
            }

            $rows[] = [
                'part' => $part,
                'available' => $available,
                'missing' => $part->minimum_stock - $available,
            ];
        }

        return $rows;
    }

    /**
     * @return array{expired: Collection<int, StockLot>, soon: Collection<int, StockLot>}
     */
    private function expiry(Carbon $asOf): array
    {
        $lots = StockLot::query()
            ->with('partType')
            ->whereNotNull('expires_at')
            ->whereNot('state', 'disposed')
            ->orderBy('expires_at')
            ->get()
            ->filter(fn (StockLot $lot): bool => $lot->remainingQuantityAsOf($asOf->toDateString()) > 0);

        return [
            'expired' => $lots->filter(fn (StockLot $l): bool => $l->expires_at->lt($asOf)),
            'soon' => $lots->filter(fn (StockLot $l): bool => $l->expires_at->gte($asOf)
                && $l->expires_at->lte($asOf->copy()->addDays(90))),
        ];
    }

    /**
     * @return Collection<int, StockLot>
     */
    private function blocked(): Collection
    {
        return StockLot::query()
            ->with(['partType', 'stateChanges'])
            ->whereIn('state', ['quarantined', 'unserviceable', 'unsalvageable'])
            ->get();
    }

    /**
     * Lots needing a certificate where the document itself was never filed.
     *
     * @return Collection<int, StockLot>
     */
    private function missingEvidence(): Collection
    {
        return StockLot::query()
            ->with('partType')
            ->whereHas('partType', fn ($q) => $q->where('requires_form_one', true))
            ->whereNot('state', 'disposed')
            ->get()
            ->filter(fn (StockLot $lot): bool => ! $lot->hasDocumentFile());
    }

    /**
     * @return Collection<int, StockMovement>
     */
    private function journal(Request $request, Carbon $asOf): Collection
    {
        $from = $request->query('from') !== null
            ? Carbon::parse((string) $request->query('from'))
            : $asOf->copy()->subYear();

        return StockMovement::query()
            ->with(['partType', 'lot', 'user'])
            ->whereBetween('occurred_at', [$from->startOfDay(), $asOf->copy()->endOfDay()])
            ->orderBy('occurred_at')
            ->get();
    }
}
