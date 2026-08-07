<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Events;

use App\Modules\Fleet\Models\Installation;

/**
 * A part came off an aircraft.
 *
 * The return leg. The warehouse has had a removal path since before the fleet
 * existed -- it was built for the club with no fleet module, where somebody
 * types the registration by hand. Now that the fleet knows what is fitted where,
 * taking a part off there should put it on the shelf without anybody typing it
 * twice.
 *
 * Same shape as the outbound event, and the same reason: the payload is the
 * interface. The warehouse learns nothing about installations, counters or
 * limits -- it is told a part, an aircraft, a quantity and whether somebody
 * qualified declared it serviceable, which is exactly what its own removal
 * action already takes.
 *
 * What deliberately does NOT travel is the operating time. That is the fleet's
 * and stays there: a lot on a shelf has a calendar life, not a running one, and
 * when the part goes back on, FitComponent finds its history by serial number
 * rather than reading it off the lot.
 */
final readonly class ComponentRemovedFromAircraft
{
    public function __construct(
        public string $aircraftReference,
        public string $partName,
        public float $quantity,
        public string $reason,
        public bool $determinedServiceable,
        public ?int $partTypeId = null,
        public ?string $serialNumber = null,
        public ?string $aircraftType = null,
        public ?int $userId = null,
        public ?string $removedAt = null,
        public ?int $installationId = null,
    ) {}

    public static function from(Installation $installation, string $reason, bool $determinedServiceable): self
    {
        return new self(
            aircraftReference: (string) $installation->aircraft?->registration,
            partName: $installation->part_name,
            quantity: (float) $installation->quantity,
            reason: $reason,
            determinedServiceable: $determinedServiceable,
            partTypeId: $installation->part_type_id,
            serialNumber: $installation->serial_number,
            aircraftType: $installation->aircraft?->model,
            userId: $installation->removed_by,
            removedAt: $installation->removed_at?->toDateString(),
            installationId: $installation->id,
        );
    }
}
