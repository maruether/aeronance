<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Events;

use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\StockMovement;

/**
 * A part left the store for a named aircraft.
 *
 * The first module interface in the project, and the analysis called it a year
 * before there was anything to connect: "Lager übergibt Nachweis an Flotte. Es
 * lohnt sich, dafür beim Entwurf ein Event vorzusehen, auch wenn zunächst
 * niemand darauf hört."
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The payload is the interface, and that is deliberate.
 *
 * Everything a listener could need is carried here as plain values, so the fleet
 * never has to open a warehouse table to find out what arrived. If it did, the
 * two modules would be joined at the schema and the boundary would exist only in
 * the documentation.
 *
 * That includes the classification, which decides whether this becomes a life
 * record at all -- Vorgabe: "alles was kein standard part ist. niemanden
 * interessiert die mutter oder niete von würth" -- and the certificate, because
 * "wenn ein Form 1 oder CoC dranhängt geht das papier mit aufs flugzeug über".
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class PartIssuedToAircraft
{
    public function __construct(
        public string $aircraftReference,
        public string $partName,
        public PartClassification $classification,
        public float $quantity,
        public ?int $partTypeId = null,
        public ?string $partNumber = null,
        public ?int $stockLotId = null,
        public ?string $stockLotNumber = null,
        public ?string $serialNumber = null,
        public string $documentType = 'none',
        public ?string $documentReference = null,
        public ?string $documentIssuer = null,
        public ?string $documentIssuerApproval = null,
        public ?string $documentIssuedAt = null,
        public ?string $workOrderReference = null,
        public ?int $userId = null,
        public ?string $occurredAt = null,
    ) {}

    /**
     * Builds the event from a movement that has just been written.
     */
    public static function from(StockMovement $movement): self
    {
        $part = $movement->partType;
        $lot = $movement->lot;

        return new self(
            aircraftReference: (string) $movement->aircraft_reference,
            partName: $part?->name ?? '?',
            classification: $part?->classification ?? PartClassification::Component,
            quantity: abs((float) $movement->quantity),
            partTypeId: $part?->id,
            partNumber: $part?->ipc_part_number,
            stockLotId: $lot?->id,
            stockLotNumber: $lot?->lot_number,
            serialNumber: $lot?->serial_number,
            documentType: $lot?->document_type ?? 'none',
            documentReference: $lot?->document_reference,
            documentIssuer: $lot?->document_issuer,
            documentIssuerApproval: $lot?->document_issuer_approval,
            documentIssuedAt: $lot?->document_issued_at?->toDateString(),
            workOrderReference: $movement->work_order_reference,
            userId: $movement->user_id,
            occurredAt: $movement->occurred_at?->toDateString(),
        );
    }

    /**
     * Whether this is the sort of part that belongs in a life record.
     *
     * Standard parts are not. The rule is stated here rather than in the
     * listener because it is the WAREHOUSE that knows what a standard part is --
     * the fleet only knows it does not want them.
     */
    public function belongsInALifeRecord(): bool
    {
        return $this->classification !== PartClassification::StandardPart;
    }

    public function carriesCertificate(): bool
    {
        return $this->documentType !== 'none' && filled($this->documentReference);
    }
}
