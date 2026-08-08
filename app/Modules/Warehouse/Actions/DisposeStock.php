<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Actions;

use App\Core\Access\Authority;
use App\Models\User;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\MovementType;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\LotStateChange;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StockMovement;
use App\Modules\Warehouse\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Booking stock out because it was destroyed.
 *
 * The way out that was missing. Disposal existed only as the last link of the
 * lot state chain, which meant three things were impossible:
 *
 *  - BULK STOCK COULD NOT BE DESTROYED AT ALL. Nuts, washers, anything kept as a
 *    plain quantity has no lot and therefore no state, so the only way to get
 *    rid of a corroded boxful was a "stocktake difference" -- filing destruction
 *    under counting error, which is exactly the conflation a ledger is for
 *    avoiding.
 *  - ONLY WHOLE LOTS. Three damaged filters out of ten had no path.
 *  - EXPIRED CONSUMABLES TOOK THREE QUALIFIED ACTS. A tin of resin past its date
 *    had to be declared unserviceable, then unsalvageable, then disposed. The
 *    predictable result is that somebody bins it and records nothing.
 *
 * So destruction is modelled as what it is -- a quantity leaving because it no
 * longer exists -- rather than as the end of a condition chain. The lot state
 * follows the quantity: emptied by destruction, the lot is Disposed. Partially
 * destroyed, it keeps the state it had, because the rest of it is unchanged.
 *
 * The record stays either way. A disposed lot keeps its number, its certificate
 * details and its history at nil quantity, because otherwise the evidence that
 * the part ever existed goes out with the rubbish -- and that is precisely what
 * an audit asks about.
 *
 * There is no way back. ReverseMovement refuses to counter-book a disposal: a
 * correction there would assert the part is on the shelf while it is in the bin.
 */
final readonly class DisposeStock
{
    public function __construct(private Authority $authority) {}

    /**
     * Whether destroying this part is a determination somebody answers for.
     *
     * the ruling, and it draws the line where the regulation does: the
     * Part-66 binding exists for setting the status under 145.A.42, which is
     * about components. Saying a component will never fly again is a judgement,
     * and somebody has to answer for it.
     *
     * A box of corroded nuts and a tin of resin past its date are not that. The
     * rule there is the date or the obvious, and requiring a licence to act on
     * it buys nothing: either a licence holder does storeroom chores, or people
     * bin things and record nothing -- which is the outcome the ledger exists to
     * prevent. The permission still gates it, and the reason and the person are
     * frozen into the record either way.
     */
    public function requiresQualification(PartType $partType): bool
    {
        return $partType->classification === PartClassification::Component;
    }

    /**
     * @param  StockLot|null  $lot  required for lot-tracked parts
     */
    public function handle(
        PartType $partType,
        float $quantity,
        ?StockLot $lot,
        User $user,
        string $reason,
        ?string $occurredAt = null,
    ): StockMovement {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('A disposal is entered as a positive quantity.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'A reason is required. "Destroyed" without one is a quantity that vanished.'
            );
        }

        if ($partType->isLotTracked() && $lot === null) {
            throw new InvalidArgumentException(
                'This part is tracked by lot -- which lot was destroyed has to be recorded.'
            );
        }

        if (! $this->authority->permits($user, Permissions::STOCK_SCRAP)) {
            throw new RuntimeException(sprintf(
                'Destroying stock requires the "%s" permission.',
                Permissions::STOCK_SCRAP,
            ));
        }

        $qualification = null;

        if ($this->requiresQualification($partType)) {
            $qualification = $this->authority->qualificationFor($user, Permissions::STOCK_SCRAP);

            if ($qualification === null) {
                throw new RuntimeException(
                    'Writing stock off as destroyed is a determination and is reserved for '
                    .'qualified staff: a valid Part-66 licence is required.'
                );
            }
        }

        if ($lot !== null && $lot->part_type_id !== $partType->id) {
            throw new InvalidArgumentException('That lot belongs to a different part type.');
        }

        $when = $occurredAt !== null ? Carbon::parse($occurredAt) : now();

        return DB::transaction(function () use (
            $partType, $quantity, $lot, $user, $reason, $qualification, $when
        ): StockMovement {
            /*
             * The quantity check binds only under lock -- same pattern and same
             * reason as IssueStock: stock is a SUM over an append-only journal,
             * and checked on unlocked data two parallel bookings both pass.
             */
            if ($lot !== null) {
                $lot = StockLot::query()->lockForUpdate()->findOrFail($lot->id);

                if ($lot->remainingQuantity() + 0.0005 < $quantity) {
                    throw new RuntimeException(sprintf(
                        'Lot %s holds only %s.',
                        $lot->lot_number,
                        rtrim(rtrim(number_format($lot->remainingQuantity(), 3, '.', ''), '0'), '.'),
                    ));
                }
            } else {
                PartType::query()->lockForUpdate()->findOrFail($partType->id);

                if ($partType->currentStock() + 0.0005 < $quantity) {
                    throw new RuntimeException('There is not that much in stock.');
                }
            }

            $movement = StockMovement::create([
                'part_type_id' => $partType->id,
                'stock_lot_id' => $lot?->id,
                'type' => MovementType::Disposal,
                'quantity' => -1 * abs($quantity),
                'occurred_at' => $when,
                'user_id' => $user->id,
                'note' => trim($reason),
            ]);

            // The state follows the quantity rather than leading it. Emptied by
            // destruction the lot is Disposed; partially destroyed it keeps the
            // state it had, because the rest of it has not changed.
            //
            // Written straight to the lot rather than through ChangeLotState,
            // and the distinction is the point: that chain governs DECLARATIONS
            // about condition, where jumping straight to "disposed" is a way of
            // using the state selector as a delete button and stays refused.
            // This is not a declaration. The quantity ceased to exist, which is
            // a fact; the lot reading "disposed" afterwards is bookkeeping, not
            // a claim about airworthiness. An adversarial test guards the other
            // door and still passes.
            if ($lot !== null && $lot->fresh()->remainingQuantity() <= 0.0005) {
                $from = $lot->state;

                LotStateChange::create([
                    'stock_lot_id' => $lot->id,
                    'from_state' => $from,
                    'to_state' => LotState::Disposed,
                    'reason' => trim($reason),
                    'user_id' => $user->id,

                    // Certificate content, copied at the moment of the act -- E7.
                    'determined_by_name' => $qualification !== null ? $user->name : null,
                    'qualification_type' => $qualification?->type,
                    'qualification_reference' => $qualification?->reference,
                    'qualification_category' => $qualification?->category,
                    'qualification_valid_until' => $qualification?->valid_until,

                    'occurred_at' => $when,
                ]);

                $lot->update(['state' => LotState::Disposed]);
            }

            return $movement;
        });
    }

    /**
     * Everything past its date, for the screen to offer without being asked.
     *
     * Expired stock is the commonest reason to destroy anything and the easiest
     * to overlook: it sits on the shelf looking exactly like the rest of it.
     *
     * @return Collection<int, StockLot>
     */
    public function expiredLots(): Collection
    {
        return StockLot::query()
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', now()->toDateString())
            ->where('state', '!=', LotState::Disposed->value)
            ->with('partType')
            ->orderBy('expires_at')
            ->get()
            ->filter(fn (StockLot $lot): bool => $lot->remainingQuantity() > 0)
            ->values();
    }
}
