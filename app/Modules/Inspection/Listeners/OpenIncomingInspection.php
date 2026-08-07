<?php

declare(strict_types=1);

namespace App\Modules\Inspection\Listeners;

use App\Core\Modules\ModuleManager;
use App\Modules\Inspection\Enums\CheckItem;
use App\Modules\Inspection\Enums\InspectionState;
use App\Modules\Inspection\Models\IncomingInspection;
use App\Modules\Inspection\Models\InspectionCheck;
use App\Modules\Warehouse\Actions\ChangeLotState;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Events\StockReceived;
use App\Modules\Warehouse\Models\StockLot;

/**
 * Goods arrived — open the checklist and hold them.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE HOLD IS THE HALF THAT MATTERS.
 *
 * A record saying "this was never inspected" is worth something to an auditor
 * and nothing to the aircraft. What actually keeps an uninspected part off a
 * wing is that it cannot be issued -- so the arrival goes straight into
 * quarantine, and only signing the inspection lifts it.
 *
 * Quarantine is the right state and not a misuse of one: the warehouse defines
 * it as PRECAUTIONARY -- set aside pending a decision, no qualification needed
 * to put it there, reversible. That is exactly what an unchecked delivery is.
 * Releasing it is the qualified act, and the warehouse already enforces that.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE GAP, NAMED OUT LOUD: bulk stock cannot be held.
 *
 * Standard parts are a pooled quantity with no lot -- there is nothing to
 * quarantine, and the quantity is available the moment it is booked. For those
 * the inspection is a RECORD, not a gate.
 *
 * That is a limit of the warehouse's own model rather than an oversight here,
 * and it is not as bad as it sounds: 145.A.42(c) treats standard parts
 * differently anyway, on conformity to a standard rather than on a release
 * certificate. Should it ever need to become a real gate, the fix belongs in
 * the warehouse -- pooled stock would need a blocked quantity -- and not in a
 * workaround here.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class OpenIncomingInspection
{
    public function __construct(
        private ModuleManager $modules,
        private ChangeLotState $changeLotState,
    ) {}

    public function handle(StockReceived $event): void
    {
        /*
         * The listener is registered once at boot, so it also has to ask
         * whether the module is switched on -- "deaktivieren blendet
         * Funktionen aus und stoppt Jobs des Moduls". A club that turns the
         * inspection off must get its old one-step goods-in back, immediately
         * and completely.
         */
        if (! $this->modules->isEnabled('inspection')) {
            return;
        }

        $inspection = IncomingInspection::create([
            'stock_movement_id' => $event->movementId,
            'part_type_id' => $event->partTypeId,
            'stock_lot_id' => $event->stockLotId,
            'state' => InspectionState::Open,
            'arrived_at' => $event->occurredAt,
        ]);

        $items = CheckItem::forDelivery(
            $event->classification,
            $event->certificateRequired,
            $event->hasShelfLife,
            $event->serialTracked,
        );

        foreach ($items as $item) {
            InspectionCheck::create([
                'incoming_inspection_id' => $inspection->getKey(),
                'item' => $item,
                // Unanswered. An inspection cannot be signed with one of these
                // still open -- that is the whole mechanism.
                'result' => null,
            ]);
        }

        $this->hold($event);
    }

    /**
     * Set the arrival aside until somebody has looked at it.
     */
    private function hold(StockReceived $event): void
    {
        if ($event->stockLotId === null) {
            return; // Bulk stock -- see the note above.
        }

        $lot = StockLot::find($event->stockLotId);

        if ($lot === null || $lot->state !== LotState::Serviceable) {
            /*
             * Already set aside by whoever booked it -- a repair return without
             * a certificate arrives quarantined by itself. Blocking it twice
             * would produce a second quarantine tag for one physical slip.
             */
            return;
        }

        $this->changeLotState->handle(
            lot: $lot,
            target: LotState::Quarantined,
            reason: __('inspection.hold_reason'),
            user: null,
            occurredAt: $event->occurredAt,
        );
    }
}
