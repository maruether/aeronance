<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Actions;

use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Models\LotStateChange;
use App\Modules\Warehouse\Models\StockLot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Stock that has passed its date becomes unserviceable by itself.
 *
 * the ruling, and it resolves something that had been quietly wrong: an
 * expired lot sat there in state "serviceable" while isIssuable() said no. Two
 * places telling different stories about the same tin, and the one people read
 * was the wrong one.
 *
 * The reason it can be automatic is the same reason it should be: this is NOT a
 * determination. Nobody is exercising judgement about whether the resin is any
 * good -- a date passed, and the date is a fact the system already holds. E8
 * reserves determinations for qualified staff precisely because they are
 * judgements; arithmetic is not one, so no licence is snapshotted here and
 * user_id stays null. The record says the system did it, because the system did.
 *
 * That also removes the step the brief objected to. Expired resin no longer has to
 * be declared unserviceable by hand, and it never needed "unsalvageable" at all:
 * destroying it is DisposeStock's business, and that skips the condition chain
 * because the quantity ceases to exist rather than being judged.
 *
 * One guard, so the software does not fight a person. If somebody qualified has
 * deliberately put an expired lot back into service -- the manufacturer extended
 * the shelf life and the date has not been updated yet -- that decision is left
 * alone. The right fix there is the date, not a nightly argument about the state.
 */
final class ExpireStock
{
    /**
     * @return list<StockLot> the lots whose state was changed
     */
    public function run(?string $on = null, bool $dryRun = false): array
    {
        $date = $on !== null ? Carbon::parse($on) : now();
        $changed = [];

        foreach ($this->dueLots($date) as $lot) {
            if ($dryRun) {
                $changed[] = $lot;

                continue;
            }

            DB::transaction(function () use ($lot, $date): void {
                LotStateChange::create([
                    'stock_lot_id' => $lot->id,
                    'from_state' => $lot->state,
                    'to_state' => LotState::Unserviceable,
                    'reason' => __('warehouse.expiry.reason', [
                        'date' => $lot->expires_at->format('d.m.Y'),
                    ]),

                    // No person, no licence. Nobody judged anything -- see the
                    // class comment.
                    'user_id' => null,

                    'occurred_at' => $date,
                ]);

                $lot->update(['state' => LotState::Unserviceable]);
            });

            $changed[] = $lot;
        }

        return $changed;
    }

    /**
     * Lots that are past their date and still count as usable.
     *
     * Quarantined ones are included: being set aside pending a decision is not
     * an answer, and the date has now given one. Anything already determined
     * unserviceable, unsalvageable or disposed is left where it is.
     *
     * @return list<StockLot>
     */
    public function dueLots(?Carbon $date = null): array
    {
        $date ??= now();

        return StockLot::query()
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', $date->toDateString())
            ->whereIn('state', [LotState::Serviceable->value, LotState::Quarantined->value])
            ->with('partType')
            ->orderBy('expires_at')
            ->get()
            ->filter(fn (StockLot $lot): bool => $lot->remainingQuantity() > 0)
            ->reject(fn (StockLot $lot): bool => $this->deliberatelyReleased($lot))
            ->values()
            ->all();
    }

    /**
     * Whether somebody put this lot back into service knowing it had expired.
     *
     * A state change to serviceable dated after the expiry is a decision by a
     * person, and reversing it every night would be the software arguing with
     * them. The lot stays as they left it.
     */
    private function deliberatelyReleased(StockLot $lot): bool
    {
        if ($lot->expires_at === null) {
            return false;
        }

        return LotStateChange::query()
            ->where('stock_lot_id', $lot->id)
            ->where('to_state', LotState::Serviceable->value)
            ->whereNotNull('user_id')
            ->whereDate('occurred_at', '>=', $lot->expires_at->toDateString())
            ->exists();
    }
}
