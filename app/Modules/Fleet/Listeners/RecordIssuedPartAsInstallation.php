<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Listeners;

use App\Core\Modules\ModuleManager;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\Installation;
use App\Modules\Warehouse\Events\PartIssuedToAircraft;

/**
 * A part issued to an aircraft becomes a line in that aircraft's life record.
 *
 * The other half of the interface. The warehouse announces; this decides what
 * the fleet makes of it, and the decision is the rule: everything that is
 * not a standard part. Nobody wants a life record listing the Würth nuts.
 *
 * Three refusals, all of them quiet, because this runs behind somebody else's
 * booking and must never turn a correct issue into an error:
 *
 *  - the fleet is not installed, so there is nothing to record into;
 *  - the registration is not one we know, so the entry would hang on a string
 *    that matches no aircraft -- better absent than misfiled;
 *  - it is a standard part.
 *
 * What it does NOT do is invent life limits. Vorgabe: "daraus ergibt sich das
 * nicht jedes bauteil eine laufzeit hat. Ein Ölfilter geht z.B. automatisch mit
 * der Motorwartung und ein neuer kommt." The limits are entered by whoever knows
 * them, if there are any. An automatic guess would be wrong more often than
 * right, and wrong in the direction of a false sense of control.
 */
final readonly class RecordIssuedPartAsInstallation
{
    public function __construct(private ModuleManager $modules) {}

    public function handle(PartIssuedToAircraft $event): void
    {
        if (! $this->modules->isEnabled('fleet')) {
            return;
        }

        if (! $event->belongsInALifeRecord()) {
            return;
        }

        $aircraft = Aircraft::where('registration', trim($event->aircraftReference))->first();

        if ($aircraft === null) {
            return;
        }

        Installation::create([
            'aircraft_id' => $aircraft->id,
            'part_name' => $event->partName,
            'part_number' => $event->partNumber,

            // Loose references -- no key crosses the boundary in either
            // direction, so the chain is walkable without either module owning
            // the other.
            'stock_lot_id' => $event->stockLotId,
            'stock_lot_number' => $event->stockLotNumber,
            'part_type_id' => $event->partTypeId,

            'serial_number' => $event->serialNumber,
            'quantity' => $event->quantity,

            /*
             * The paper comes across with the part. One lot's Form 1 can end up
             * in four aircraft, so this is a copy and not a move -- the analysis
             * settled it as "Vervielfältigung durch Referenz".
             */
            'document_type' => $event->documentType,
            'document_reference' => $event->documentReference,
            'document_issuer' => $event->documentIssuer,
            'document_issuer_approval' => $event->documentIssuerApproval,
            'document_issued_at' => $event->documentIssuedAt,

            'work_order_reference' => $event->workOrderReference,
            'installed_at' => $event->occurredAt ?? now()->toDateString(),
            'installed_by' => $event->userId,

            'counters_at_installation' => $aircraft->currentValues(),
        ]);
    }
}
