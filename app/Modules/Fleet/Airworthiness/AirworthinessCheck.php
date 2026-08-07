<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Airworthiness;

use App\Modules\Fleet\Models\Aircraft;
use Illuminate\Contracts\Container\Container;

/**
 * "Hier ist noch was offen."
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS IS NOT: a declaration that an aircraft is airworthy.
 *
 * The brief set the framing: "da geht es vor allem um eine art warnung 'Hier ist
 * noch was offen'." That is a much more useful thing to build and a much more
 * honest one. Airworthiness is a judgement a qualified person makes with the
 * aircraft in front of them; what software can do is make sure nothing they
 * would want to know is sitting unnoticed in a database.
 *
 * So it lists open items and never issues a verdict. An empty list means nothing
 * was found -- not that the aircraft is fit. The difference is the same one the
 * AD module will need: the tool may add work, never remove it.
 *
 * It is COMPOSABLE because the answer spans modules. A club with the fleet alone
 * has papers and limits; add task cards and open findings join in; add releases
 * and a missing certificate does. Each module contributes what it knows, and one
 * that is not installed contributes nothing.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class AirworthinessCheck implements ContributesOpenItems
{
    /** @var list<class-string<ContributesOpenItems>> */
    private array $contributors = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<ContributesOpenItems>  $contributor
     */
    public function register(string $contributor): void
    {
        if (! in_array($contributor, $this->contributors, strict: true)) {
            $this->contributors[] = $contributor;
        }
    }

    /**
     * Everything worth looking at before this aircraft flies.
     *
     * @return list<OpenItem>
     */
    /**
     * Open items that make a RELEASE TO SERVICE unsound.
     *
     * A narrower question than "is this aircraft airworthy", and deliberately so.
     * Most of what this class reports says the aircraft may not fly -- an expired
     * ARC, a due component limit -- and none of that makes the maintenance
     * carried out on it unreleasable. A CRS certifies work, not flightworthiness.
     *
     * What does belong here is anything that makes the SIGNATURE itself unsound.
     * The first case is an unassessed LTA/TM line: signing while nobody has read
     * the manufacturer's list is signing over an unknown. Contributors opt in per
     * item; the default is false, so this stays empty unless somebody means it.
     *
     * @return list<OpenItem>
     */
    public function releaseBlockersFor(Aircraft $aircraft): array
    {
        return array_values(array_filter(
            $this->openItemsFor($aircraft),
            fn (OpenItem $item): bool => $item->blocksRelease,
        ));
    }

    public function openItemsFor(Aircraft $aircraft): array
    {
        $items = $this->ownItems($aircraft);

        foreach ($this->contributors as $contributor) {
            $items = array_merge($items, $this->container->make($contributor)->openItemsFor($aircraft));
        }

        return $items;
    }

    public function hasOpenItems(Aircraft $aircraft): bool
    {
        return $this->openItemsFor($aircraft) !== [];
    }

    /**
     * What the fleet itself knows.
     *
     * @return list<OpenItem>
     */
    private function ownItems(Aircraft $aircraft): array
    {
        $items = [];

        $review = $aircraft->currentReview();

        if ($review === null) {
            $items[] = new OpenItem(
                source: 'fleet',
                what: __('fleet.review.singular'),
                detail: __('fleet.due.no_review'),
            );
        } elseif (! $review->isValid()) {
            $items[] = new OpenItem(
                source: 'fleet',
                what: __('fleet.review.singular'),
                detail: __('fleet.airworthiness.expired_on', [
                    'date' => $review->valid_until->format('d.m.Y'),
                ]),
            );
        }

        foreach ($aircraft->installations()->whereNull('removed_at')->with('limits')->get() as $installation) {
            foreach ($installation->limits as $limit) {
                if (! $limit->status()->isPastDue()) {
                    continue;
                }

                $items[] = new OpenItem(
                    source: 'fleet',
                    what: $installation->label(),
                    detail: sprintf('%s — %s', $limit->describe(), $limit->status()->label()),

                    // Inside the permitted overrun it is a warning, not a
                    // stopper. Colouring the two alike is how a list stops being
                    // read.
                    blocking: $limit->isBeyondTolerance(),
                );
            }
        }

        /*
         * Minimum equipment that is not fitted.
         *
         * the rule, and the reason the flag exists at all: "baue ich das
         * zusätzliche Garmin G5 aus darf ich fliegen, nehm ich die Analoganzeige
         * steht der vogel."
         */
        $missing = $aircraft->installations()
            ->minimumEquipment()
            ->whereNotNull('removed_at')
            ->get()
            ->reject(fn ($removed) => $aircraft->installations()
                ->whereNull('removed_at')
                ->where('part_name', $removed->part_name)
                ->exists());

        foreach ($missing as $item) {
            $items[] = new OpenItem(
                source: 'fleet',
                what: $item->part_name,
                detail: __('fleet.airworthiness.minimum_missing'),
            );
        }

        /*
         * Back from an external shop and nothing yet says it may fly.
         *
         * The gap that matters: the aircraft is in the hangar and looks
         * finished, which is exactly when somebody takes it on the strength of
         * "it's back, isn't it". An open order that has NOT come back is not
         * reported here -- the aircraft is elsewhere and nobody is about to fly
         * it.
         */
        foreach ($aircraft->externalWorkOrders()->awaitingRelease()->get() as $order) {
            $items[] = new OpenItem(
                source: 'fleet',
                what: __('fleet.external.singular'),
                detail: __('fleet.external.awaiting_release', ['shop' => $order->shop_name]),
            );
        }

        foreach ($aircraft->documents as $document) {
            if ($document->expires() && ! $document->isValid()) {
                $items[] = new OpenItem(
                    source: 'fleet',
                    what: $document->type->label(),
                    detail: __('fleet.airworthiness.expired_on', [
                        'date' => $document->valid_until->format('d.m.Y'),
                    ]),
                );
            }
        }

        $weighing = $aircraft->weighings()->first();

        if ($weighing !== null && $weighing->valid_until !== null && ! $weighing->isValid()) {
            $items[] = new OpenItem(
                source: 'fleet',
                what: __('fleet.weighing.singular'),
                detail: __('fleet.airworthiness.expired_on', [
                    'date' => $weighing->valid_until->format('d.m.Y'),
                ]),
            );
        }

        return $items;
    }
}
