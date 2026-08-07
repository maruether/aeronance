<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Actions;

use App\Core\Access\Authority;
use App\Models\User;
use App\Modules\Warehouse\Enums\MovementType;
use App\Modules\Warehouse\Models\StockMovement;
use App\Modules\Warehouse\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Putting a booking right.
 *
 * The counter-booking that decision E1 has been pointing at since the first
 * migration: `reverses_movement_id` has sat in the schema from day one with
 * nothing writing to it. Until now the only way to correct anything was through
 * a stocktake, which meant every ordinary slip -- issued from the wrong lot,
 * booked in twice, fat-fingered quantity -- had to be dressed up as a counting
 * difference.
 *
 * A correction is not an edit. The original stays exactly where it was and a
 * second, opposite entry is written beside it, pointing back at the first. Both
 * together explain what happened, which is also what a correction looks like on
 * paper. A ledger whose entries can be revised is not a ledger.
 *
 * Two refusals carry the weight:
 *
 * 1. DESTRUCTION CANNOT BE REVERSED. A counter-booking against a disposal would
 *    assert that the part is back on the shelf, and it is in the bin. If the
 *    disposal itself was booked in error, that is a fresh receipt with an
 *    explanation -- not a claim that the destruction never happened.
 *
 * 2. NOTHING IS REVERSED TWICE. Otherwise the same mistake can be corrected
 *    repeatedly, each time moving the stock further from the truth, and the
 *    chain of references stops meaning anything.
 */
final readonly class ReverseMovement
{
    public function __construct(private Authority $authority) {}

    /**
     * Movement types a counter-booking may be written against.
     *
     * Repair dispatches and returns are absent on purpose: they have a path of
     * their own that keeps the dispatch record straight. Undoing one from here
     * would move the stock and leave the repair saying something else.
     */
    private const REVERSIBLE = [
        MovementType::Receipt,
        MovementType::Issue,
        MovementType::Correction,
    ];

    public function handle(
        StockMovement $movement,
        User $user,
        string $reason,
        ?string $occurredAt = null,
    ): StockMovement {
        if (! $this->authority->permits($user, Permissions::STOCK_CORRECT)) {
            throw new RuntimeException(sprintf(
                'Correcting a booking requires the "%s" permission.',
                Permissions::STOCK_CORRECT,
            ));
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'A reason is required -- without it the counter-booking says only that '
                .'somebody changed their mind.'
            );
        }

        if ($movement->type === MovementType::Disposal) {
            throw new RuntimeException(
                'A destruction cannot be reversed: the part is gone, and a counter-booking '
                .'would claim it is back on the shelf. If the disposal itself was wrong, '
                .'book the part in again and say so.'
            );
        }

        if (! in_array($movement->type, self::REVERSIBLE, strict: true)) {
            throw new RuntimeException(sprintf(
                '"%s" movements are not corrected this way -- they have a path of their own.',
                $movement->type->label(),
            ));
        }

        if ($this->reversalOf($movement) !== null) {
            throw new RuntimeException(sprintf(
                'This booking has already been corrected on %s.',
                $this->reversalOf($movement)->occurred_at->format('d.m.Y'),
            ));
        }

        $quantity = -1 * (float) $movement->quantity;

        // Reversing a receipt that has since been issued from would push the lot
        // below nil. The stock did not vanish because the paperwork was wrong,
        // so the honest answer is a stocktake, not a bigger counter-booking.
        if ($movement->stock_lot_id !== null && $quantity < 0) {
            $remaining = $movement->lot?->remainingQuantity() ?? 0.0;

            if ($remaining + $quantity < -0.0005) {
                throw new RuntimeException(sprintf(
                    'Lot %s holds only %s, so this booking can no longer be taken back in '
                    .'full -- part of it has been used since. Record a stocktake difference '
                    .'instead.',
                    $movement->lot?->lot_number ?? '?',
                    rtrim(rtrim(number_format($remaining, 3, '.', ''), '0'), '.'),
                ));
            }
        }

        return DB::transaction(fn (): StockMovement => StockMovement::create([
            'part_type_id' => $movement->part_type_id,
            'stock_lot_id' => $movement->stock_lot_id,
            'type' => MovementType::Correction,
            'quantity' => $quantity,
            'occurred_at' => $occurredAt !== null ? Carbon::parse($occurredAt) : now(),
            'user_id' => $user->id,

            // Same work order and aircraft as the original: the correction
            // belongs to the same event, and a counter-booking that drops them
            // breaks the very chain the movement was recorded for.
            'work_order_reference' => $movement->work_order_reference,
            'aircraft_reference' => $movement->aircraft_reference,

            'reverses_movement_id' => $movement->id,
            'note' => trim($reason),
        ]));
    }

    /**
     * The counter-booking already written against this movement, if any.
     */
    public function reversalOf(StockMovement $movement): ?StockMovement
    {
        return StockMovement::where('reverses_movement_id', $movement->id)->first();
    }

    /**
     * Whether this booking can still be taken back.
     *
     * Used by the interface to decide whether to offer the action at all --
     * an action that is always shown and usually refuses teaches people to
     * ignore refusals.
     */
    public function isReversible(StockMovement $movement): bool
    {
        return in_array($movement->type, self::REVERSIBLE, strict: true)
            && $this->reversalOf($movement) === null;
    }
}
