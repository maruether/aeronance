<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Actions;

use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\MovementType;
use App\Modules\Warehouse\Enums\RepairDestination;
use App\Modules\Warehouse\Enums\RepairState;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\RepairDispatch;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StockMovement;
use App\Modules\Warehouse\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Sending a part away to be repaired.
 *
 * A third way out of the store, alongside issuing and scrapping, and it is not
 * a variant of either. An issue ends the part's life in the warehouse; a scrap
 * ends it altogether. This is a journey the part is expected to come back from,
 * so the booking has to leave a thread attached.
 *
 * It matters more than it looks, because of what the brief settled: assume no club
 * holds a component rating. That makes this the ONE lawful route by which a part
 * already tied to one aircraft becomes freely usable again -- send it to an
 * organisation that does hold the rating, and what comes back carries their
 * Form 1. The restriction is not circumvented, it is discharged by someone
 * entitled to discharge it.
 *
 * Two rules, and only two, because refusals without a reason are worse than
 * none:
 *
 * 1. A LOT DETERMINED BEYOND REPAIR MAY NOT BE SENT FOR REPAIR. Unsalvageable
 *    is one-way by design (145.A.42): such a part must never re-enter the
 *    supply system. Sending it to a shop and booking back what returns is
 *    precisely how it would re-enter, so the door is shut here as well as at
 *    the state machine.
 *
 * 2. QUARANTINED AND UNSERVICEABLE PARTS MAY BE SENT. This is the normal case
 *    and the reason the action exists -- it deliberately does NOT go through
 *    IssueStock::assertIssuable(), which would refuse exactly the parts that
 *    need repairing.
 *
 * No qualification is required. Putting a part in a parcel says nothing about
 * its airworthiness; the determination that it is unserviceable was made
 * earlier, by someone qualified, and is already on the record.
 */
final readonly class DispatchForRepair
{
    public function __construct(private ModuleManager $modules) {}

    public function handle(
        PartType $partType,
        float $quantity,
        ?StockLot $lot,
        User $user,
        string $reason,
        RepairDestination $destination = RepairDestination::External,
        ?string $shopName = null,
        ?string $shopApproval = null,
        ?Supplier $shop = null,
        ?string $dispatchReference = null,
        ?string $expectedBackAt = null,
        ?string $dispatchedAt = null,
    ): RepairDispatch {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('A dispatch is entered as a positive quantity.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason for the repair is required.');
        }

        if (! $destination->isAvailable($this->modules)) {
            throw new RuntimeException(sprintf(
                'The destination "%s" needs the "%s" module, which is not installed.',
                $destination->value,
                $destination->requiresModule(),
            ));
        }

        /*
         * ─────────────────────────────────────────────────────────────────────
         * EIN BETRIEB AUS DEM VERZEICHNIS SCHLAEGT DEN FREITEXT.
         *
         * Name und Zulassungsnummer werden dann von dort KOPIERT -- nicht
         * verwiesen. Wohin ein Teil ging und unter welcher Nummer, muss lesbar
         * bleiben, auch wenn der Betrieb spaeter umbenannt wird oder seine
         * Zulassung wechselt. Dieselbe Regel wie beim Bescheinigungsinhalt am
         * Los (E7).
         * ─────────────────────────────────────────────────────────────────────
         */
        if ($shop !== null) {
            /*
             * ABGELAUFENE ZULASSUNG WIRD ABGELEHNT. Was von dort
             * zurueckkommt, traegt eine Bescheinigung, die nichts wert ist --
             * und das faellt sonst erst auf, wenn Jahre spaeter jemand danach
             * fragt, rueckwirkend fuer alles aus dieser Zeit.
             */
            if ($shop->approvalHasLapsed()) {
                throw new RuntimeException(__('warehouse.repair.refused.approval_lapsed', [
                    'shop' => $shop->name,
                    'date' => $shop->approval_expires_at?->format('d.m.Y') ?? '—',
                ]));
            }

            $shopName ??= $shop->name;
            $shopApproval ??= $shop->approval_number;
        }

        // Who will sign the certificate is the whole point of sending it away,
        // so an outside destination without a name is a parcel to nowhere.
        if ($destination === RepairDestination::External && trim((string) $shopName) === '') {
            throw new InvalidArgumentException(
                'The organisation the part is going to has to be recorded: it is who will '
                .'certify the repair.'
            );
        }

        if ($partType->isLotTracked() && $lot === null) {
            throw new InvalidArgumentException(
                'This part is tracked by lot -- which lot it came from has to be recorded.'
            );
        }

        if ($lot !== null) {
            $this->assertDispatchable($lot, $partType, $quantity);
        } elseif ($partType->currentStock() < $quantity) {
            throw new RuntimeException('There is not that much in stock.');
        }

        $when = $dispatchedAt !== null ? Carbon::parse($dispatchedAt) : now();

        return DB::transaction(function () use (
            $partType, $quantity, $lot, $user, $reason, $destination,
            $shopName, $shopApproval, $shop, $dispatchReference, $expectedBackAt, $when
        ): RepairDispatch {
            $dispatch = RepairDispatch::create([
                'part_type_id' => $partType->id,
                'stock_lot_id' => $lot?->id,
                'quantity' => $quantity,

                // Copied rather than looked up later: which item went away has
                // to stay readable even if the lot is corrected or emptied.
                'serial_number' => $lot?->serial_number,

                'destination' => $destination,
                'supplier_id' => $shop?->getKey(),
                'shop_name' => $shopName !== null ? trim($shopName) : null,
                'shop_approval' => $shopApproval !== null ? trim($shopApproval) : null,
                'dispatch_reference' => $dispatchReference,
                'reason' => trim($reason),

                // Travels with the part. If nothing comes back on paper, the
                // restriction is still in force -- and by then nobody would
                // remember it was ever there.
                'restricted_to_aircraft' => $lot?->isRestrictedToItsAircraft() ?? false
                    ? $lot->removed_from_aircraft
                    : null,
                'aircraft_type' => $lot?->removed_from_aircraft_type,

                'dispatched_at' => $when->toDateString(),
                'dispatched_by' => $user->id,
                'expected_back_at' => $expectedBackAt,
                'state' => RepairState::Dispatched,
            ]);

            StockMovement::create([
                'part_type_id' => $partType->id,
                'stock_lot_id' => $lot?->id,
                'type' => MovementType::RepairDispatch,
                'quantity' => -1 * abs($quantity),
                'occurred_at' => $when,
                'user_id' => $user->id,
                'aircraft_reference' => $dispatch->restricted_to_aircraft,
                'note' => trim($reason),
            ]);

            return $dispatch;
        });
    }

    private function assertDispatchable(StockLot $lot, PartType $partType, float $quantity): void
    {
        if ($lot->part_type_id !== $partType->id) {
            throw new InvalidArgumentException('That lot belongs to a different part type.');
        }

        // The one refusal that matters. Unsalvageable and disposed are final
        // determinations; a repair round trip would be the way back into the
        // supply system that 145.A.42 exists to prevent.
        if (in_array($lot->state, [LotState::Unsalvageable, LotState::Disposed], strict: true)) {
            throw new RuntimeException(sprintf(
                'Lot %s was determined %s. That is final: it must not re-enter the supply '
                .'system, and a repair is not a way back.',
                $lot->lot_number,
                $lot->state->label(),
            ));
        }

        if ($lot->remainingQuantity() < $quantity) {
            throw new RuntimeException(sprintf(
                'Lot %s holds only %s.',
                $lot->lot_number,
                rtrim(rtrim(number_format($lot->remainingQuantity(), 3, '.', ''), '0'), '.'),
            ));
        }
    }
}
