<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Enums;

/**
 * How far an external job has got.
 *
 * The step worth keeping separate is RETURNED but not yet RELEASED. The aircraft
 * is physically back and somebody will want to fly it, and until the release is
 * recorded there is nothing saying it may. That gap is exactly where an aircraft
 * quietly goes flying on the strength of "it's back, isn't it", so it is a state
 * of its own and the airworthiness check reports it.
 */
enum ExternalWorkState: string
{
    case Commissioned = 'commissioned';
    case Returned = 'returned';
    case Released = 'released';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('fleet.external.state.'.$this->value);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Commissioned, self::Returned], strict: true);
    }

    /** Back, but nothing says it may fly yet. */
    public function isAwaitingRelease(): bool
    {
        return $this === self::Returned;
    }
}
