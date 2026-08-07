<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Actions;

use App\Core\Access\Authority;
use App\Models\User;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StorageCompartment;
use App\Modules\Warehouse\Permissions;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Moving a lot to another compartment.
 *
 * Not a stock movement: nothing is received, issued or destroyed, the same
 * quantity is simply somewhere else. It would be wrong in the ledger, where
 * every line is a change in how much there is.
 *
 * What makes it more than an address change is the quarantine store. 145.A.42
 * wants unserviceable stock physically separated from serviceable stock, and
 * this system already takes that seriously -- IssueStock refuses a lot standing
 * in a quarantine compartment even when its state says otherwise. But that check
 * fires at issue, by which time the part has been sitting among the good stock
 * for months. The shelf and the record have to agree when the part is PUT there,
 * not when somebody finally reaches for it.
 *
 * So two rules, mirror images of each other:
 *
 * 1. INTO the quarantine store sets the lot aside. Physically separating
 *    something IS setting it aside, and leaving the state saying "serviceable"
 *    would reproduce exactly the two-stories problem that expiry had. It is
 *    precautionary and reversible, so no qualification -- E8.
 *
 * 2. OUT of it is refused while the lot is still blocked. Otherwise "move it
 *    back to the shelf" becomes a way of releasing a part without anyone
 *    determining anything, which is the one thing the separation exists to
 *    prevent. Release it first, through the door with the licence on it.
 *
 * The second rule is narrower than it first looks, and deliberately: it bites
 * only on LEAVING the quarantine store. Refusing every move of a blocked lot
 * into normal storage was the first attempt and it was wrong -- a lot that
 * arrives without its paperwork is quarantined while sitting in an ordinary
 * compartment, so the rule pinned it there, and a club with no quarantine
 * location configured could not move such a lot at all. Blocking a move that
 * does not worsen the separation achieves nothing except somebody carrying the
 * box anyway and recording nothing. What must not happen is UNDOING a
 * separation that exists; that is what is refused. The rest is advice, and
 * belongsToQuarantineStore() lets the interface give it.
 */
final readonly class TransferStock
{
    public function __construct(
        private Authority $authority,
        private ChangeLotState $changeState,
    ) {}

    public function handle(
        StockLot $lot,
        StorageCompartment $target,
        User $user,
        ?string $reason = null,
    ): StockLot {
        if (! $this->authority->permits($user, Permissions::STOCK_TRANSFER)) {
            throw new RuntimeException(sprintf(
                'Moving stock requires the "%s" permission.',
                Permissions::STOCK_TRANSFER,
            ));
        }

        if ($lot->storage_compartment_id === $target->id) {
            throw new InvalidArgumentException('The lot is already there.');
        }

        if ($lot->state === LotState::Disposed || $lot->remainingQuantity() <= 0) {
            throw new RuntimeException(sprintf(
                'Lot %s holds nothing -- there is nothing to move.',
                $lot->lot_number,
            ));
        }

        $intoQuarantine = $target->isQuarantine();

        // Leaving the quarantine store while still blocked would be a release
        // that nobody made. The message names the door to use instead.
        if ($this->wouldUndoSeparation($lot, $target)) {
            throw new RuntimeException(sprintf(
                'Lot %s is %s and is standing in quarantine storage. Taking it back to the '
                .'shelf would be a release, and a release is a determination -- moving it '
                .'is not. Release it first.',
                $lot->lot_number,
                $lot->state->label(),
            ));
        }

        // The reason ends up on the quarantine tag that gets printed and hung on
        // the part, so here it is not optional.
        if ($intoQuarantine && $lot->state === LotState::Serviceable && trim((string) $reason) === '') {
            throw new InvalidArgumentException(
                'Moving serviceable stock into the quarantine store sets it aside, and a '
                .'reason is required -- it goes on the tag.'
            );
        }

        return DB::transaction(function () use ($lot, $target, $user, $reason, $intoQuarantine): StockLot {
            // Through the state action rather than beside it, so the tag is
            // numbered and the record is written exactly as it is everywhere
            // else. Nothing about quarantine works differently because it
            // happened to be reached by moving a box.
            if ($intoQuarantine && $lot->state === LotState::Serviceable) {
                $this->changeState->handle(
                    $lot,
                    LotState::Quarantined,
                    __('warehouse.transfer.quarantined_reason', [
                        'compartment' => $target->fullName(),
                        'reason' => trim((string) $reason),
                    ]),
                    $user,
                );
            }

            // The move itself is an attribute change, recorded by the activity
            // log -- there is no quantity here for the ledger to carry.
            $lot->update(['storage_compartment_id' => $target->id]);

            return $lot->fresh();
        });
    }

    /**
     * Whether this move would take a blocked lot out of the quarantine store.
     */
    public function wouldUndoSeparation(StockLot $lot, StorageCompartment $target): bool
    {
        return ! $target->isQuarantine()
            && ! $lot->state->allowsIssue()
            && ($lot->storageCompartment?->isQuarantine() ?? false);
    }

    /**
     * Whether this lot may go to that compartment, for the interface to ask
     * before it offers the move.
     */
    public function mayMoveTo(StockLot $lot, StorageCompartment $target): bool
    {
        if ($lot->state === LotState::Disposed || $lot->remainingQuantity() <= 0) {
            return false;
        }

        return ! $this->wouldUndoSeparation($lot, $target);
    }

    /**
     * Whether this lot ought to be in the quarantine store and is not.
     *
     * Not a refusal -- see the class comment. But it is worth saying out loud
     * when somebody moves a blocked lot around the ordinary shelves, because
     * 145.A.42 wants it separated and the person holding the box is the one who
     * can do that.
     */
    public function belongsToQuarantineStore(StockLot $lot, StorageCompartment $target): bool
    {
        return ! $target->isQuarantine() && ! $lot->state->allowsIssue();
    }
}
