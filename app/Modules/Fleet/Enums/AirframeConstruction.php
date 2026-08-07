<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Enums;

use App\Core\Enums\MaintenanceSubject;

/**
 * What an airframe is built of.
 *
 * SEVERAL AT ONCE IS THE NORMAL CASE in gliding, not an edge case: an ASK 13 is
 * a welded steel tube fuselage with wooden wings, and a Ka 6 with a glass nose
 * cone is neither purely wood nor purely composite. So an aircraft carries a
 * list, and a licence limitation bites if it excludes ANY of them.
 *
 * The fleet keeps its own word for this rather than using the core's directly:
 * here it describes an aircraft, there it describes what a licence excludes.
 * They must not drift apart, which is what subject() is for -- one method, and
 * the compiler complains if a case is ever added without an answer.
 */
enum AirframeConstruction: string
{
    case Wood = 'wood';
    case Metal = 'metal';
    case Composite = 'composite';

    public function subject(): MaintenanceSubject
    {
        return match ($this) {
            self::Wood => MaintenanceSubject::Wood,
            self::Metal => MaintenanceSubject::Metal,
            self::Composite => MaintenanceSubject::Composite,
        };
    }

    public function label(): string
    {
        return __('fleet.construction.'.$this->value);
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
