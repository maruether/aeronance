<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Listeners;

use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Fleet\Events\ComponentRemovedFromAircraft;
use App\Modules\Warehouse\Actions\RemovePartFromAircraft;
use App\Modules\Warehouse\Models\PartType;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A part taken off an aircraft lands on the shelf.
 *
 * Straight through the warehouse's own removal action, so every rule it already
 * enforces applies unchanged: the serviceable determination needs a licence and
 * is frozen with it, a replacement-interval part is refused outright, and
 * without a Form 1 the lot stays tied to the aircraft it came out of. None of
 * that is restated here, which is the point -- a second door would mean a second
 * set of rules, and the second set is always the one that falls behind.
 *
 * Three cases are let past quietly, because this runs behind somebody else's
 * removal and must never turn a correct one into an error:
 *
 *  - the warehouse is not installed, so there is no shelf to put it on;
 *  - the part type is unknown, so there is nothing to book against -- a part
 *    entered by hand into a life record need not exist in the store at all;
 *  - the action refuses. A TBR part is the ordinary example: it comes off and
 *    is scrapped, and the fleet's record of the removal is correct either way.
 */
final readonly class BookRemovedComponentIntoStock
{
    public function __construct(
        private ModuleManager $modules,
        private RemovePartFromAircraft $removal,
    ) {}

    public function handle(ComponentRemovedFromAircraft $event): void
    {
        if (! $this->modules->isEnabled('warehouse')) {
            return;
        }

        $partType = $event->partTypeId !== null ? PartType::find($event->partTypeId) : null;

        if ($partType === null) {
            return;
        }

        $user = $event->userId !== null ? User::find($event->userId) : null;

        if ($user === null) {
            return;
        }

        try {
            // Auf Namen, nicht auf Positionen -- neun Argumente, und genau an
            // so einer Stelle hat ein Einschub in der Mitte schon zweimal
            // still alles danach verschoben.
            $this->removal->handle(
                partType: $partType,
                quantity: $event->quantity,
                aircraft: $event->aircraftReference,
                user: $user,
                reason: $event->reason,
                determinedServiceable: $event->determinedServiceable,
                aircraftType: $event->aircraftType,
                removedAt: $event->removedAt,
                lotData: ['serial_number' => $event->serialNumber],
            );
        } catch (Throwable $e) {
            /*
             * Logged, not raised. The commonest cause is a rule doing its job --
             * a replacement-interval part has no way back onto the shelf -- and
             * the removal from the aircraft is right regardless of whether the
             * part is worth keeping.
             */
            Log::info('Removed component was not booked into stock', [
                'part' => $event->partName,
                'aircraft' => $event->aircraftReference,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
