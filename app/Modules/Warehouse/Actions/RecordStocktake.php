<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Actions;

use App\Models\User;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\MovementType;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StockMovement;
use App\Modules\Warehouse\Support\LotNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Booking what a stocktake actually found.
 *
 * The difference between counted and recorded becomes a correction movement --
 * never an edit, because there is nothing to edit: stock is the sum of its
 * movements (E1), and both entries stay visible so the difference can still be
 * explained a year later.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The rule that makes this delicate, and the own warning:
 *
 *   A SURPLUS ON A LOT-TRACKED PART MUST NEVER BE ADDED TO AN EXISTING LOT.
 *
 * Booking "+1" onto a lot is not an arithmetic correction. It is the assertion
 * that this additional part arrived on that lot's delivery and is therefore
 * covered by that lot's Form 1 -- and nobody counting a shelf knows that. Five
 * oil filters where four were recorded means one filter of unknown origin, and
 * an unknown origin means no certificate.
 *
 * So a surplus opens a NEW lot without a certificate, which under ML.A.504
 * lands in quarantine: a part whose airworthiness status cannot be established
 * is unserviceable. Somebody then has to work out where it came from, which is
 * exactly the right outcome -- rather than a certificate silently acquiring a
 * part it never covered.
 *
 * A SHORTFALL is different and may be booked against a lot. Saying "this lot is
 * one short" claims nothing about any certificate; it records something missing.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class RecordStocktake
{
    /**
     * Bulk stock: the difference in one movement.
     */
    public function correctBulk(
        PartType $partType,
        float $counted,
        User $user,
        ?string $note = null,
        ?string $countedAt = null,
    ): ?StockMovement {
        if ($partType->isLotTracked()) {
            throw new InvalidArgumentException(
                'This part is tracked by lot; corrections have to name the lot.'
            );
        }

        if ($counted < 0) {
            throw new InvalidArgumentException('A counted quantity cannot be negative.');
        }

        $when = $this->countedWhen($countedAt);

        return DB::transaction(function () use ($partType, $counted, $user, $note, $when): ?StockMovement {
            // Same lock as every other quantity path -- see IssueStock.
            PartType::query()->lockForUpdate()->findOrFail($partType->id);

            /*
             * Against the stock AS OF THE COUNTED DAY, not today's. A count is
             * a statement about a date, and that date has usually passed by the
             * time it is entered: counted Saturday, issued Sunday, entered
             * Monday. Measured against today the difference would silently
             * re-add Sunday's issue -- the books would end up high by exactly
             * the movements between counting and entering. stockAsOf() exists
             * for precisely this sentence.
             */
            $difference = $counted - $partType->stockAsOf($when->toDateString());

            if (abs($difference) < 0.0005) {
                return null;
            }

            return StockMovement::create([
                'part_type_id' => $partType->id,
                'type' => MovementType::Correction,
                'quantity' => $difference,
                'occurred_at' => $when,
                'user_id' => $user->id,
                'note' => $note,
            ]);
        });
    }

    /**
     * A shortfall on one lot.
     *
     * Only ever downwards. The surplus case has its own method, because it is
     * not the same operation with a different sign -- see the note at the top.
     */
    public function correctLotShortfall(
        StockLot $lot,
        float $counted,
        User $user,
        ?string $note = null,
        ?string $countedAt = null,
    ): ?StockMovement {
        if ($counted < 0) {
            throw new InvalidArgumentException('A counted quantity cannot be negative.');
        }

        $when = $this->countedWhen($countedAt);

        return DB::transaction(function () use ($lot, $counted, $user, $note, $when): ?StockMovement {
            $lot = StockLot::query()->lockForUpdate()->findOrFail($lot->id);

            // Against the day of the count, not today -- see correctBulk().
            $difference = $counted - $lot->remainingQuantityAsOf($when->toDateString());

            if (abs($difference) < 0.0005) {
                return null;
            }

            if ($difference > 0) {
                throw new RuntimeException(
                    'A surplus cannot be added to an existing lot: it would claim the extra '
                    .'part is covered by that lot\'s certificate. Record it as stock of '
                    .'unknown origin instead.'
                );
            }

            return StockMovement::create([
                'part_type_id' => $lot->part_type_id,
                'stock_lot_id' => $lot->id,
                'type' => MovementType::Correction,
                'quantity' => $difference,
                'occurred_at' => $when,
                'user_id' => $user->id,
                'note' => $note,
            ]);
        });
    }

    /**
     * Parts found that nobody expected.
     *
     * Opens a lot of its own, without a certificate, in quarantine. That is not
     * a punishment for miscounting -- it is what the situation actually is: a
     * part whose origin is unknown has no evidence behind it, and ML.A.504 calls
     * that unserviceable. Somebody now has to establish where it came from, and
     * either produce the paperwork or scrap it.
     */
    public function recordFound(
        PartType $partType,
        float $quantity,
        User $user,
        string $note,
        ?string $countedAt = null,
    ): StockLot {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('A found quantity has to be positive.');
        }

        if (trim($note) === '') {
            throw new InvalidArgumentException(
                'A note is required -- it is the only clue to where this came from.'
            );
        }

        $when = $this->countedWhen($countedAt);

        return DB::transaction(function () use ($partType, $quantity, $user, $note, $when): StockLot {
            $lot = StockLot::create([
                'part_type_id' => $partType->id,
                /*
                 * Immer die erzeugte Nummer: Ein gefundenes Teil HAT kein
                 * Form 1 -- das ist ja gerade der Grund, warum es hier landet.
                 */
                'lot_number' => LotNumber::forNewLot($when->toDateString(), null),
                'document_type' => StockLot::DOCUMENT_NONE,
                'storage_compartment_id' => $partType->storage_compartment_id,
                'received_at' => $when->toDateString(),

                // No certificate, no known origin -- so not usable until
                // somebody with the standing to do so says otherwise.
                'state' => LotState::Quarantined,

                // Deliberately NO expiry date. It would have to be derived from
                // a receipt date nobody knows, and a made-up date on an
                // airworthiness record is worse than an absent one.
                'expires_at' => null,
            ]);

            StockMovement::create([
                'part_type_id' => $partType->id,
                'stock_lot_id' => $lot->id,
                'type' => MovementType::Correction,
                'quantity' => $quantity,
                'occurred_at' => $when,
                'user_id' => $user->id,
                'note' => $note,
            ]);

            return $lot;
        });
    }

    /**
     * When the counting actually happened.
     *
     * The date is free precisely so that a weekend count can be entered on
     * Monday -- but free backwards only. A count dated in the future is a
     * statement about a shelf nobody has looked at yet, and a correction booked
     * on that date would sit in the journal ahead of the movements it claims to
     * correct.
     */
    private function countedWhen(?string $countedAt): Carbon
    {
        $when = $countedAt !== null ? Carbon::parse($countedAt) : now();

        if ($when->isAfter(now())) {
            throw new InvalidArgumentException(
                'A stocktake cannot be dated in the future -- that shelf has not been counted yet.'
            );
        }

        return $when;
    }
}
