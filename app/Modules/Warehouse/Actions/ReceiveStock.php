<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Actions;

use App\Models\User;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\MovementType;
use App\Modules\Warehouse\Events\StockReceived;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StockMovement;
use App\Modules\Warehouse\Support\LotNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Booking goods in.
 *
 * Two paths, decided by the part type rather than by the person at the shelf
 * (4.5):
 *
 *  - Bulk stock. Nuts and bolts: a movement against the part type, no lot. The
 *    question "which delivery did this come from" has no answer and needs none.
 *  - Lot-tracked. Anything with a Form 1, a shelf life or a serial number gets
 *    a lot of its own, because for those that question has to be answerable.
 *
 * The expiry date is worked out here and STORED rather than computed on demand:
 * if someone corrects the shelf life on the part type later, stock already on
 * the shelf must not silently come back to life.
 */
final class ReceiveStock
{
    /**
     * @param  array<string, mixed>  $lotData
     */
    public function handle(
        PartType $partType,
        float $quantity,
        string $receivedAt,
        ?User $user = null,
        array $lotData = [],
    ): StockMovement {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('A receipt has to be a positive quantity.');
        }

        if ($partType->serial_tracked && $quantity != 1.0) {
            // A serialised part is a lot of one -- that is what the serial
            // number identifies. Two of them are two lots.
            throw new InvalidArgumentException(
                'A serialised part is booked in one at a time: the serial number identifies one item.'
            );
        }

        $this->refuseWithoutCertificate($partType, $lotData);

        return DB::transaction(function () use ($partType, $quantity, $receivedAt, $user, $lotData): StockMovement {
            $lot = $partType->isLotTracked()
                ? $this->createLot($partType, $receivedAt, $lotData)
                : null;

            $movement = StockMovement::create([
                'part_type_id' => $partType->id,
                'stock_lot_id' => $lot?->id,
                'type' => MovementType::Receipt,
                'quantity' => $quantity,
                'occurred_at' => Carbon::parse($receivedAt),
                'user_id' => $user?->id,
                'note' => $lotData['note'] ?? null,
            ]);

            /*
             * Announced, not asked. Whoever cares that goods arrived -- today
             * the incoming inspection, tomorrow whatever else -- hears it here.
             * Fired inside the transaction on purpose: a listener that cannot
             * do its work should take the receipt down with it, because stock
             * on the shelf with no inspection opened for it is precisely the
             * silent outcome that module exists to prevent.
             */
            event(StockReceived::from($movement));

            return $movement;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createLot(PartType $partType, string $receivedAt, array $data): StockLot
    {
        $documentType = $data['document_type'] ?? StockLot::DOCUMENT_NONE;

        return StockLot::create([
            'part_type_id' => $partType->id,
            // Die Nummer vom Form 1, wo es eine gibt -- siehe LotNumber.
            'lot_number' => LotNumber::forNewLot($receivedAt, $data['document_reference'] ?? null),
            'serial_number' => $data['serial_number'] ?? null,
            'batch_number' => $data['batch_number'] ?? null,
            'document_type' => $documentType,
            'document_reference' => $data['document_reference'] ?? null,
            'document_issuer' => $data['document_issuer'] ?? null,
            'document_issuer_approval' => $data['document_issuer_approval'] ?? null,
            'document_issued_at' => $data['document_issued_at'] ?? null,
            'document_signatory' => $data['document_signatory'] ?? null,
            'supplier_id' => $data['supplier_id'] ?? $partType->supplier_id,
            'storage_compartment_id' => $data['storage_compartment_id'] ?? $partType->storage_compartment_id,
            'received_at' => $receivedAt,
            'expires_at' => $partType->expiryFor($receivedAt),

            // Was hier ankommt, hat seinen Nachweis -- ohne ihn kommt es gar
            // nicht bis hierher (refuseWithoutCertificate).
            'state' => LotState::Serviceable,
        ]);
    }

    /**
     * Ohne Form 1 wird gar nicht erst eingebucht.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Vorgabe: „ein los geht erst dann ins lager wenn das form1 da ist. vorher
     * liegt es im wareneingang und ist noch nicht verbucht."
     *
     * Hier stand vorher: Los anlegen, Zustand „gesperrt". Das war eine Stufe zu
     * weit -- ein gesperrtes Los IST Lagerbestand. Es hat eine Losnummer, taucht
     * in Listen auf, wird bei der Inventur gezählt und muss von jemandem
     * entsperrt werden. Der Karton im Wareneingang ist nichts davon: Er ist
     * schlicht noch nicht angekommen.
     *
     * Der Unterschied ist nicht bloß Buchhaltung. Wer den Bestand ansieht, soll
     * sehen, was das Lager HAT -- nicht, was auf dem Tisch neben der Tür liegt
     * und vielleicht zurückgeht. Und die Sperre bleibt damit das, wofür sie
     * gedacht ist: ein Urteil über ein Teil, das im Lager ist.
     *
     * Verweigert wird nur, was auch verlangt ist. Standard Parts und
     * Verbrauchsmaterial ohne `requires_form_one` gehen wie bisher direkt ins
     * Regal.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @param  array<string, mixed>  $data
     *
     * @throws RuntimeException
     */
    private function refuseWithoutCertificate(PartType $partType, array $data): void
    {
        if (! $partType->requires_form_one || ! $partType->isLotTracked()) {
            return;
        }

        $hatNachweis = ($data['document_type'] ?? StockLot::DOCUMENT_NONE) === StockLot::DOCUMENT_FORM_ONE
            && filled($data['document_reference'] ?? null);

        if ($hatNachweis) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s darf ohne Form 1 nicht eingebucht werden. Die Ware bleibt so lange im '
            .'Wareneingang -- eingelagert wird erst, wenn der Nachweis vorliegt.',
            $partType->name,
        ));
    }
}
