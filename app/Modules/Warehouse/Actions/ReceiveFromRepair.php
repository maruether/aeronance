<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Actions;

use App\Models\User;
use App\Modules\Warehouse\Enums\LotOrigin;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\MovementType;
use App\Modules\Warehouse\Enums\RepairState;
use App\Modules\Warehouse\Events\StockReceived;
use App\Modules\Warehouse\Models\RepairDispatch;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StockMovement;
use App\Modules\Warehouse\Support\LotNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Booking a repaired part back in.
 *
 * A NEW lot, not the old one revived. A lot is a quantity covered by ONE
 * certificate, and after a repair the certificate is a different document from
 * a different organisation -- reusing the old lot would attach the new paper to
 * the old record and quietly rewrite what the part's evidence used to be.
 *
 * What comes back on paper decides everything:
 *
 *   WITH a Form 1   -> serviceable, and any aircraft restriction is DISCHARGED.
 *                      This is what sending the part away was for: an
 *                      organisation holding a component rating certified it, so
 *                      it now travels like any other certified part.
 *
 *   WITHOUT one     -> quarantine, and the restriction stands. Something was
 *                      done to the part and nobody entitled to say so has said
 *                      what. Under ML.A.504 a part whose airworthiness status
 *                      cannot be established is not serviceable, and guessing in
 *                      its favour is exactly the guess that must not be made.
 *
 * The second case is not an error path. A shop may return a part untouched, or
 * with a quote instead of a certificate, and that has to be bookable.
 */
final readonly class ReceiveFromRepair
{
    /**
     * @param  array<string, mixed>  $lotData  document type, reference, issuer, ...
     */
    public function handle(
        RepairDispatch $dispatch,
        User $user,
        array $lotData = [],
        ?string $returnedAt = null,
        ?string $note = null,
    ): StockLot {
        if (! $dispatch->state->isOpen()) {
            throw new RuntimeException(sprintf(
                'This dispatch is already %s.',
                $dispatch->state->label(),
            ));
        }

        $partType = $dispatch->partType;

        if ($partType === null) {
            throw new InvalidArgumentException('The part type behind this dispatch is gone.');
        }

        $when = $returnedAt !== null ? Carbon::parse($returnedAt) : now();

        $documentType = $lotData['document_type'] ?? StockLot::DOCUMENT_NONE;
        $documentReference = $lotData['document_reference'] ?? null;

        $certified = $documentType === StockLot::DOCUMENT_FORM_ONE && filled($documentReference);

        return DB::transaction(function () use (
            $dispatch, $partType, $user, $lotData, $when, $note,
            $documentType, $documentReference, $certified
        ): StockLot {
            /*
             * The isOpen() check above ran on unlocked data. A double-click
             * sends two requests, both pass it, and the same dispatch comes
             * back TWICE -- two lots, double the stock, from one physical
             * part. Under the row lock the second request waits here and then
             * sees the first one's "Returned".
             */
            $dispatch = RepairDispatch::query()->lockForUpdate()->findOrFail($dispatch->id);

            if (! $dispatch->state->isOpen()) {
                throw new RuntimeException(sprintf(
                    'This dispatch is already %s.',
                    $dispatch->state->label(),
                ));
            }

            $lot = StockLot::create([
                'part_type_id' => $partType->id,
                'origin' => LotOrigin::Repair,
                'repair_dispatch_id' => $dispatch->id,

                // Carried over ONLY while uncertified. A Form 1 from the
                // repairing organisation is precisely what lifts it, so writing
                // the aircraft down anyway would leave a restriction in the data
                // that no longer exists in law.
                'removed_from_aircraft' => $certified ? null : $dispatch->restricted_to_aircraft,
                'removed_from_aircraft_type' => $certified ? null : $dispatch->aircraft_type,

                /*
                 * Auch hier die Nummer vom Papier: Ein Betrieb, der ein Teil
                 * instand setzt, stellt ein NEUES Form 1 aus -- und dessen
                 * Nummer ist die, unter der das Los ab jetzt gesucht wird.
                 */
                'lot_number' => LotNumber::forNewLot($when->toDateString(), $documentReference),
                'serial_number' => $dispatch->serial_number,

                'document_type' => $documentType,
                'document_reference' => $documentReference,
                'document_issuer' => $lotData['document_issuer'] ?? $dispatch->shop_name,
                'document_issuer_approval' => $lotData['document_issuer_approval'] ?? $dispatch->shop_approval,
                'document_issued_at' => $lotData['document_issued_at'] ?? null,
                'document_signatory' => $lotData['document_signatory'] ?? null,

                'storage_compartment_id' => $lotData['storage_compartment_id']
                    ?? $dispatch->lot?->storage_compartment_id
                    ?? $partType->storage_compartment_id,

                'received_at' => $when->toDateString(),

                // A repair does not restart a shelf life, and it does not
                // create one either. Whatever the certificate says about it
                // belongs in the certificate fields, entered by hand.
                'expires_at' => $lotData['expires_at'] ?? null,

                'state' => $certified ? LotState::Serviceable : LotState::Quarantined,
            ]);

            $movement = StockMovement::create([
                'part_type_id' => $partType->id,
                'stock_lot_id' => $lot->id,
                'type' => MovementType::RepairReturn,
                'quantity' => abs($dispatch->quantity),
                'occurred_at' => $when,
                'user_id' => $user->id,
                'aircraft_reference' => $lot->removed_from_aircraft,
                'note' => $note ?? __('warehouse.repair.return_note', ['shop' => $dispatch->shop_name ?? '—']),
            ]);

            /*
             * A unit back from the shop is an ARRIVAL, and announcing it here
             * as well is not tidiness -- it is the case where checking the
             * paperwork matters most. What comes back carries a fresh
             * certificate from somebody else's approval, and that certificate
             * is exactly what an incoming inspection exists to look at. Firing
             * only from ReceiveStock would have exempted the repair return
             * silently, which is the worst way to miss a rule.
             */
            event(StockReceived::from($movement));

            $dispatch->update([
                'state' => RepairState::Returned,
                'returned_at' => $when->toDateString(),
                'returned_by' => $user->id,
                'returned_lot_id' => $lot->id,
                'return_note' => $note,
            ]);

            return $lot;
        });
    }

    /**
     * The part is not coming back.
     *
     * Beyond repair, lost in the post, or the quote exceeded a replacement.
     * Nothing is booked into stock -- the quantity already left at dispatch and
     * the ledger has said so since. This only closes the open thread, so the
     * part stops appearing on the list of things that are away.
     */
    public function writeOff(RepairDispatch $dispatch, User $user, string $reason): RepairDispatch
    {
        if (! $dispatch->state->isOpen()) {
            throw new RuntimeException(sprintf(
                'This dispatch is already %s.',
                $dispatch->state->label(),
            ));
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required to write a dispatch off.');
        }

        $dispatch->update([
            'state' => RepairState::WrittenOff,
            'returned_at' => now()->toDateString(),
            'returned_by' => $user->id,
            'return_note' => trim($reason),
        ]);

        return $dispatch;
    }
}
