<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Actions;

use App\Models\User;
use App\Modules\Warehouse\Enums\MovementType;
use App\Modules\Warehouse\Events\PartIssuedToAircraft;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Taking stock out.
 *
 * Where the traceability chain is actually forged: the movement can carry a
 * work order and an aircraft, so the path certificate -> lot -> part -> job ->
 * aircraft becomes answerable in both directions. Both are plain strings with
 * no foreign key, because task cards and aircraft live in modules that need not
 * be installed (D4) -- the warehouse works on its own, and gains meaning when
 * the others arrive.
 */
final class IssueStock
{
    public function handle(
        PartType $partType,
        float $quantity,
        ?StockLot $lot = null,
        ?User $user = null,
        ?string $workOrderReference = null,
        ?string $aircraftReference = null,
        ?string $note = null,
        ?string $occurredAt = null,
    ): StockMovement {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('An issue is entered as a positive quantity.');
        }

        if ($partType->isLotTracked() && $lot === null) {
            throw new InvalidArgumentException(
                'This part is tracked by lot -- which lot it came from has to be recorded.'
            );
        }

        if ($lot !== null) {
            $this->assertIssuable($lot, $partType, $quantity, $aircraftReference);
        } elseif ($partType->currentStock() < $quantity) {
            throw new RuntimeException('There is not that much in stock.');
        }

        $movement = DB::transaction(fn (): StockMovement => StockMovement::create([
            'part_type_id' => $partType->id,
            'stock_lot_id' => $lot?->id,
            'type' => MovementType::Issue,
            'quantity' => -1 * abs($quantity),
            'occurred_at' => $occurredAt !== null ? Carbon::parse($occurredAt) : now(),
            'user_id' => $user?->id,
            'work_order_reference' => $workOrderReference,
            'aircraft_reference' => $aircraftReference,
            'note' => $note,
        ]));

        /*
         * Told, not asked. The warehouse announces that a part went to an
         * aircraft and stops caring; whether anything listens depends on which
         * modules the club installed, and the warehouse must work either way.
         *
         * Outside the transaction on purpose: a listener that fails must not
         * roll back a booking that was correct. The part left the shelf whatever
         * the fleet made of the news.
         */
        if (filled($aircraftReference)) {
            event(PartIssuedToAircraft::from($movement->fresh()));
        }

        return $movement;
    }

    /**
     * Which lot the interface should offer first.
     *
     * First expired, first out -- so nothing quietly ages out on the shelf.
     * Only a suggestion: for quantity-tracked parts the storeman may take a
     * different lot without giving a reason, because traceability hangs on the
     * certificate recorded against the lot and not on which one was picked.
     *
     * For serialised parts this returns nothing on purpose. There the serial
     * number is asked for outright: the choice IS the identification, and
     * suggesting one would invite confirming the wrong part. See F26.
     *
     * The aircraft matters here even though this is only a suggestion: a lot
     * removed from another aircraft and carrying no Form 1 would be refused at
     * booking, and suggesting one is an invitation to hit that wall. With no
     * aircraft stated a restricted lot is likewise not suggested -- the
     * restriction can only be honoured if the destination is named, so the
     * booking would be refused as well.
     */
    public function suggestLot(PartType $partType, ?string $aircraftReference = null): ?StockLot
    {
        if ($partType->serial_tracked) {
            return null;
        }

        return $partType->lots()->issuable()->fefo()->get()
            ->first(fn (StockLot $lot): bool => $lot->mayBeFittedTo($aircraftReference));
    }

    private function assertIssuable(
        StockLot $lot,
        PartType $partType,
        float $quantity,
        ?string $aircraftReference,
    ): void {
        if ($lot->part_type_id !== $partType->id) {
            throw new InvalidArgumentException('That lot belongs to a different part type.');
        }

        if (! $lot->state->allowsIssue()) {
            throw new RuntimeException(sprintf(
                'Lot %s is %s and must not be issued.',
                $lot->lot_number,
                $lot->state->label(),
            ));
        }

        // Belt and braces for the case where the state and the shelf disagree.
        // A lot standing in the quarantine cupboard must not be issued even if
        // its state says otherwise -- somebody moved it there for a reason, and
        // nobody takes parts out of that cupboard. 145.A.42 requires the
        // separation to be real, not merely recorded.
        if ($lot->storageCompartment?->isQuarantine() ?? false) {
            throw new RuntimeException(sprintf(
                'Lot %s is held in quarantine storage (%s) and must not be issued.',
                $lot->lot_number,
                $lot->storageCompartment->fullName(),
            ));
        }

        if ($lot->hasExpired()) {
            throw new RuntimeException(sprintf(
                'Lot %s expired on %s.',
                $lot->lot_number,
                $lot->expires_at->format('d.m.Y'),
            ));
        }

        // A part taken out of an aircraft is backed by a determination that it
        // was serviceable when it came out -- and nothing more. Fitting it to a
        // DIFFERENT aircraft needs a Form 1 from an organisation with a
        // component rating, which a club normally does not hold. So without one
        // it goes back where it came from.
        if (! $lot->mayBeFittedTo($aircraftReference)) {
            throw new RuntimeException(sprintf(
                'Lot %s was removed from %s and carries no Form 1, so it may only go back '
                .'into that aircraft. Fitting it elsewhere needs a certificate from an '
                .'organisation holding a component rating.',
                $lot->lot_number,
                $lot->removed_from_aircraft,
            ));
        }

        if ($lot->remainingQuantity() < $quantity) {
            throw new RuntimeException(sprintf(
                'Lot %s holds only %s.',
                $lot->lot_number,
                rtrim(rtrim(number_format($lot->remainingQuantity(), 3, ',', ''), '0'), ','),
            ));
        }
    }
}
