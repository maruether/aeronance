<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Listeners;

use App\Core\Modules\ModuleManager;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\ComponentLimit;
use App\Modules\Fleet\Models\ComponentType;
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
 * What it does NOT do is INVENT life limits. Vorgabe: "daraus ergibt sich das
 * nicht jedes bauteil eine laufzeit hat. Ein Ölfilter geht z.B. automatisch mit
 * der Motorwartung und ein neuer kommt." An automatic guess would be wrong more
 * often than right, and wrong in the direction of a false sense of control.
 *
 * Since the field test it DOES inherit them where somebody stated them: a
 * component type explicitly linked to the issued part type (Feldtest:
 * "eine schleppkupplung kann beides sein. Kopplung zwischen beiden?") hands
 * its template limits to the installation -- as COPIES, E7-style, so a later
 * edit of the master never touches an existing installation. A stated
 * template is not a guess; the unlinked case stays exactly as before.
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

        /*
         * Das verknuepfte Muster -- ueber die lose partTypeId aus der
         * Event-Nutzlast, reiner Flotten-Code. Kein Treffer heisst: wie
         * bisher, ohne Katalogeintrag und ohne Laufzeiten.
         */
        $muster = $event->partTypeId !== null
            ? ComponentType::query()->where('part_type_id', $event->partTypeId)->first()
            : null;

        $installation = Installation::create([
            'aircraft_id' => $aircraft->id,
            'component_type_id' => $muster?->id,
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

        if ($muster === null) {
            return;
        }

        /*
         * Die Vorlagen KOPIEREN, nie referenzieren: Dieser Einbau lief unter
         * den Grenzen von HEUTE, und genau die muss der Nachweis zeigen --
         * auch wenn das Muster morgen andere bekommt (E7). Kalender-Grenzen
         * ankern von selbst am installed_at (ComponentLimit::anchorDate).
         */
        foreach ($muster->limits as $vorlage) {
            ComponentLimit::create([
                'installation_id' => $installation->id,
                'kind' => $vorlage->kind,
                'value' => $vorlage->value,
                'tolerance_percent' => $vorlage->tolerance_percent,
                'tolerance_absolute' => $vorlage->tolerance_absolute,
                'source' => $vorlage->source,
                'note' => $vorlage->note,
            ]);
        }
    }
}
