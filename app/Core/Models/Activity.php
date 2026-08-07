<?php

declare(strict_types=1);

namespace App\Core\Models;

use RuntimeException;
use Spatie\Activitylog\Models\Activity as BaseActivity;

/**
 * The audit trail -- append-only, and enforced here rather than by convention.
 *
 * Decision E3. The value of a log lies entirely in the fact that its ABSENCE
 * means something: no entry, nothing happened. The moment entries can be edited
 * or removed, that inference collapses -- every missing entry might be a
 * deleted one -- and for an audit the record becomes worthless.
 *
 * There is no permission to delete entries either. Both together are the
 * mechanism: no way in through the interface, and no way in through code that
 * forgot the rule.
 *
 * Retention (three years) is a separate matter and runs as a job over entries
 * whose time has expired, not as an editing capability held by a person.
 */
final class Activity extends BaseActivity
{
    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException(
                'Audit entries cannot be changed. Corrections are recorded as a new entry.'
            );
        });

        self::deleting(function (): never {
            throw new RuntimeException(
                'Audit entries cannot be deleted. Retention runs as a scheduled job.'
            );
        });
    }

    /**
     * Removes an expired entry, for the retention job only.
     *
     * Deliberately awkward to reach and named for what it is. Everything else
     * goes through the guards above.
     */
    public function forceRetentionDelete(): void
    {
        self::withoutEvents(function (): void {
            static::query()->whereKey($this->getKey())->delete();
        });
    }
}
