<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Enums;

use App\Core\Modules\ModuleManager;

/**
 * Where a part goes to be repaired.
 *
 * Two answers, and only one of them exists today. The starting assumption is
 * that the club holds no component rating and neither does anyone nearby, so
 * repair means putting the part in a parcel and sending it to an organisation
 * that does.
 *
 * The other answer is left declared rather than left out: if a component repair
 * module is ever installed, the club's own shop becomes a destination and the
 * hand-over runs internally. Gating it on the module means the seam is here,
 * unused, instead of having to be cut later.
 */
enum RepairDestination: string
{
    /** Into the parcel: an outside organisation holding the rating. */
    case External = 'external';

    /** The club's own component shop -- only with that module installed. */
    case InHouse = 'in_house';

    public function label(): string
    {
        return __('warehouse.repair.destination.'.$this->value);
    }

    /**
     * The module that has to be installed for this destination to be offered.
     */
    public function requiresModule(): ?string
    {
        return $this === self::InHouse ? 'component-repair' : null;
    }

    public function isAvailable(ModuleManager $modules): bool
    {
        $module = $this->requiresModule();

        return $module === null || $modules->isEnabled($module);
    }

    /**
     * The destinations that can actually be chosen right now.
     *
     * @return list<self>
     */
    public static function available(ModuleManager $modules): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $case): bool => $case->isAvailable($modules),
        ));
    }
}
