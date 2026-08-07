<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Airworthiness;

use App\Modules\Fleet\Airworthiness\ContributesOpenItems;
use App\Modules\Fleet\Airworthiness\OpenItem;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Models\Finding;
use App\Modules\TaskCards\Models\TaskCard;
use App\Modules\TaskCards\Models\WorkOrder;

/**
 * What this module contributes to "hier ist noch was offen".
 *
 * The extension point the fleet left open, used for the first time -- and it
 * works exactly as intended: the fleet learns nothing about findings, this
 * module learns nothing about reviews or weighings, and a club running the fleet
 * alone simply gets fewer items.
 */
final class OutstandingFindings implements ContributesOpenItems
{
    /** @return list<OpenItem> */
    public function openItemsFor(Aircraft $aircraft): array
    {
        $items = [];

        foreach (Finding::where('aircraft_id', $aircraft->id)->outstanding()->get() as $finding) {
            $items[] = new OpenItem(
                source: 'workorders',
                what: $finding->label(),
                detail: $this->describe($finding),

                // Whether a finding stops the aircraft is a person's judgement,
                // entered when it was recorded. A deferral that has run out
                // blocks regardless: the permission to wait was granted until a
                // date, and that date has passed.
                blocking: $finding->is_blocking || $finding->deferralHasLapsed(),
            );
        }

        /*
         * Cards finished but not signed off.
         *
         * The gap the two signatures exist to make visible. Work is done, the
         * aircraft looks finished, and nobody qualified has looked at it -- which
         * is precisely the state that would otherwise pass unnoticed.
         */
        $waiting = TaskCard::query()
            ->where('aircraft_registration', $aircraft->registration)
            ->awaitingCertification()
            ->get();

        foreach ($waiting as $card) {
            $items[] = new OpenItem(
                source: 'workorders',
                what: $card->label(),
                detail: __('taskcards.awaiting_certification'),
            );
        }

        /*
         * A visit whose cards are all signed and which has no release.
         *
         * The gap the third signature exists to make visible, and the one that
         * looks most finished from outside: every card ticked, the aircraft in
         * the hangar, and nothing saying it may fly. Before this, such a visit
         * produced no open item at all -- the card-level check had gone quiet
         * because the cards were done.
         */
        $unreleased = WorkOrder::query()
            ->where('aircraft_id', $aircraft->id)
            ->whereNull('released_at')
            ->whereNot('state', WorkOrder::STATE_CANCELLED)
            ->with('taskCards')
            ->get()
            ->filter(fn (WorkOrder $order): bool => $order->isReadyForRelease());

        foreach ($unreleased as $order) {
            $items[] = new OpenItem(
                source: 'workorders',
                what: $order->label(),
                detail: __('taskcards.release.awaiting'),
            );
        }

        return $items;
    }

    private function describe(Finding $finding): string
    {
        if ($finding->deferralHasLapsed()) {
            return __('taskcards.finding.deferral_lapsed', [
                'date' => $finding->deferred_until->format('d.m.Y'),
            ]);
        }

        return sprintf('%s — %s', $finding->state->label(), $finding->found_on->format('d.m.Y'));
    }
}
