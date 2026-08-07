<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Actions;

use App\Core\Access\Authority;
use App\Models\User;
use App\Modules\Warehouse\Enums\LotOrigin;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\MovementType;
use App\Modules\Warehouse\Models\LotStateChange;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StockMovement;
use App\Modules\Warehouse\Permissions;
use App\Modules\Warehouse\Support\LotNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Booking a part out of an aircraft and into the store.
 *
 * The case the brief needs for instruments: something comes out of D-KABC, sits on
 * a shelf, and goes back in later. Until now a lot could only come into being
 * at goods-in, so a removed part had nowhere to live.
 *
 * Three rules shape this, and each of them comes from somewhere:
 *
 * 1. WHETHER IT WAS SERVICEABLE IS A DETERMINATION, not a checkbox. Saying a
 *    part came out fit is a judgement somebody answers for, so it needs a
 *    qualification and is frozen into the record with the credential it was made
 *    under -- exactly like declaring something unserviceable, only in the other
 *    direction. Without that determination the part is of unknown condition and
 *    lands in quarantine, by the same logic as goods arriving without paperwork.
 *
 * 2. REPLACEMENT-INTERVAL PARTS HAVE NO WAY BACK. Spark plugs and hoses are
 *    replaced, not recovered; letting one onto the shelf invites it being
 *    fitted again, which is the one thing the interval exists to prevent.
 *    Overhaul-interval parts are different -- a tow release is overhauled and
 *    refitted, and is precisely why this exists.
 *
 * 3. WITHOUT A FORM 1 IT GOES BACK WHERE IT CAME FROM. A removal record proves
 *    the part was serviceable when it came out and nothing more. Fitting it to a
 *    DIFFERENT aircraft needs a Form 1 from an organisation with a component
 *    rating, which a club normally does not hold. Enforced in
 *    StockLot::mayBeFittedTo().
 *
 * See docs/AUSGEBAUTE-TEILE.md for the research these rest on.
 */
final readonly class RemovePartFromAircraft
{
    public function __construct(private Authority $authority) {}

    /**
     * @param  array<string, mixed>  $lotData  serial number, compartment, ...
     */
    public function handle(
        PartType $partType,
        float $quantity,
        string $aircraft,
        User $user,
        string $reason,
        bool $determinedServiceable,
        ?string $aircraftType = null,
        ?string $removedAt = null,
        array $lotData = [],
    ): StockLot {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('A removal has to be a positive quantity.');
        }

        if (trim($aircraft) === '') {
            throw new InvalidArgumentException(
                'The aircraft it came out of is required -- without it the part has no origin.'
            );
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason for the removal is required.');
        }

        if (! $partType->allowsReuseAfterRemoval()) {
            throw new RuntimeException(sprintf(
                '%s is on a replacement interval and is not put back into stock. '
                .'Scrap it instead.',
                $partType->name,
            ));
        }

        if ($partType->serial_tracked && $quantity != 1.0) {
            throw new InvalidArgumentException(
                'A serialised part is removed one at a time: the serial number identifies one item.'
            );
        }

        // Only the determination needs a qualification. Recording that a part
        // came out at all does not -- and refusing the booking would leave the
        // part off the books entirely, which is worse than recording it as being
        // of unknown condition.
        $qualification = null;

        if ($determinedServiceable) {
            if (! $this->authority->permits($user, Permissions::STOCK_QUARANTINE_CERTIFY)) {
                throw new RuntimeException(sprintf(
                    'Recording a part as serviceable on removal requires the "%s" permission.',
                    Permissions::STOCK_QUARANTINE_CERTIFY,
                ));
            }

            $qualification = $this->authority->qualificationFor($user, Permissions::STOCK_QUARANTINE_CERTIFY);

            if ($qualification === null) {
                throw new RuntimeException(
                    'Recording a part as serviceable on removal is a determination and is '
                    .'reserved for qualified staff: a valid Part-66 licence is required.'
                );
            }
        }

        $when = $removedAt !== null ? Carbon::parse($removedAt) : now();

        return DB::transaction(function () use (
            $partType, $quantity, $aircraft, $aircraftType, $user, $reason,
            $determinedServiceable, $qualification, $when, $lotData
        ): StockLot {
            $lot = StockLot::create([
                'part_type_id' => $partType->id,
                'origin' => LotOrigin::Removal,
                'removed_from_aircraft' => trim($aircraft),
                'removed_from_aircraft_type' => $aircraftType,
                'removed_at' => $when->toDateString(),
                'removal_reason' => trim($reason),

                /*
                 * Immer die erzeugte Nummer: Ein ausgebautes Teil bringt kein
                 * Form 1 mit -- der Nachweis ist die Feststellung, die unten
                 * festgehalten wird, und die hat keine Zertifikatsnummer.
                 */
                'lot_number' => LotNumber::forNewLot($when->toDateString(), null),
                'serial_number' => $lotData['serial_number'] ?? null,

                // The evidence is the determination recorded below, not a
                // certificate from anybody else.
                'document_type' => StockLot::DOCUMENT_NONE,

                'storage_compartment_id' => $lotData['storage_compartment_id'] ?? $partType->storage_compartment_id,
                'received_at' => $when->toDateString(),

                // No shelf life is derived. It runs from manufacture or from a
                // delivery, not from the day something was taken out of an
                // aircraft -- and an invented date on an airworthiness record is
                // worse than an absent one.
                'expires_at' => null,

                'state' => $determinedServiceable ? LotState::Serviceable : LotState::Quarantined,
            ]);

            LotStateChange::create([
                'stock_lot_id' => $lot->id,
                'from_state' => LotState::Quarantined,
                'to_state' => $determinedServiceable ? LotState::Serviceable : LotState::Quarantined,
                'reason' => $determinedServiceable
                    ? __('warehouse.removal.determined_serviceable', ['reason' => trim($reason)])
                    : __('warehouse.removal.condition_unknown', ['reason' => trim($reason)]),
                'aircraft_reference' => trim($aircraft),
                'aircraft_type' => $aircraftType,
                'user_id' => $user->id,

                // Certificate content, copied at the moment of the act -- E7.
                'determined_by_name' => $qualification !== null ? $user->name : null,
                'qualification_type' => $qualification?->type,
                'qualification_reference' => $qualification?->reference,
                'qualification_category' => $qualification?->category,
                'qualification_valid_until' => $qualification?->valid_until,

                'occurred_at' => $when,
            ]);

            StockMovement::create([
                'part_type_id' => $partType->id,
                'stock_lot_id' => $lot->id,
                'type' => MovementType::Receipt,
                'quantity' => $quantity,
                'occurred_at' => $when,
                'user_id' => $user->id,
                'aircraft_reference' => trim($aircraft),
                'note' => trim($reason),
            ]);

            return $lot;
        });
    }
}
