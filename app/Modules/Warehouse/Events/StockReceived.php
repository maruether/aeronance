<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Events;

use App\Modules\Warehouse\Enums\MovementType;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\StockMovement;

/**
 * Something arrived at the counter.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE WAREHOUSE ANNOUNCES, IT DOES NOT ASK.
 *
 * The warehouse books the receipt exactly as it always did and says so
 * afterwards. Whether anybody is listening -- whether a Part-145 shop has the
 * incoming inspection switched on, or a club has not -- is none of its
 * business. That is what keeps the module removable: turn the inspection off
 * and this event simply falls on deaf ears, with not one line of warehouse code
 * behaving differently.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * IT FIRES INSIDE THE RECEIPT'S TRANSACTION, deliberately.
 *
 * A listener that cannot do its work must take the receipt down with it. The
 * alternative -- stock on the shelf and no inspection opened for it -- is the
 * one outcome the inspection module exists to prevent, and it would be silent.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE PAYLOAD IS THE INTERFACE, as with PartIssuedToAircraft: everything a
 * listener needs to decide WHAT to ask about this delivery travels as plain
 * values, so nobody has to open a part type to find out whether a certificate
 * was expected.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class StockReceived
{
    public function __construct(
        public int $movementId,
        public int $partTypeId,
        public ?int $stockLotId,
        public float $quantity,
        public string $occurredAt,
        public MovementType $movementType,
        public PartClassification $classification,
        public bool $certificateRequired,
        public bool $hasShelfLife,
        public bool $serialTracked,
        public ?int $userId = null,
    ) {}

    public static function from(StockMovement $movement): self
    {
        $partType = $movement->partType;

        return new self(
            movementId: $movement->id,
            partTypeId: $partType->id,
            stockLotId: $movement->stock_lot_id,
            quantity: (float) $movement->quantity,
            occurredAt: $movement->occurred_at->toDateTimeString(),
            movementType: $movement->type,
            classification: $partType->classification,
            certificateRequired: (bool) $partType->requires_form_one,
            hasShelfLife: $partType->shelf_life_days !== null,
            serialTracked: (bool) $partType->serial_tracked,
            userId: $movement->user_id,
        );
    }
}
