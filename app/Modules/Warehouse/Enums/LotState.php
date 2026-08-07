<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Enums;

/**
 * The condition of one batch.
 *
 * The chain runs one way, with a single exception: something set aside as a
 * precaution can come back. A determination cannot.
 *
 *   serviceable  <--->  quarantined  --->  unserviceable  --->  unsalvageable  --->  disposed
 *
 * The distinction that matters is between PRECAUTIONARY and DETERMINED, not
 * between "blocked" and "scrapped". Pulling a part out of circulation because
 * its paperwork is missing needs no qualification and is reversible. Stating
 * that it is unserviceable, unsalvageable, or fit for service again is a
 * qualified act which gets frozen into the record. See decisions E7 and E8.
 *
 * Unsalvageable is final: a part that has reached its life limit or carries a
 * non-repairable defect must never re-enter the supply system (145.A.42). The
 * transition back does not exist, not even for an administrator.
 */
enum LotState: string
{
    case Serviceable = 'serviceable';

    /** Set aside as a precaution -- missing paperwork, suspicion, awaiting a
     *  decision. Reversible, no qualification required. */
    case Quarantined = 'quarantined';

    /** Determined to be unusable as it stands. Qualified act. */
    case Unserviceable = 'unserviceable';

    /** Determined to be beyond repair. One-way. Qualified act. */
    case Unsalvageable = 'unsalvageable';

    /** Physically gone. Quantity nil, record kept -- otherwise the evidence
     *  that it ever existed goes out with the rubbish. */
    case Disposed = 'disposed';

    public function label(): string
    {
        return __('warehouse.lot_state.'.$this->value);
    }

    /** May stock be issued from a lot in this state? */
    public function allowsIssue(): bool
    {
        return $this === self::Serviceable;
    }

    /** Does moving into this state require a qualification? */
    public function requiresQualification(): bool
    {
        return in_array($this, [self::Unserviceable, self::Unsalvageable, self::Disposed], strict: true);
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Serviceable => [self::Quarantined, self::Unserviceable],
            self::Quarantined => [self::Serviceable, self::Unserviceable, self::Unsalvageable],
            self::Unserviceable => [self::Serviceable, self::Unsalvageable],
            self::Unsalvageable => [self::Disposed],
            self::Disposed => [],
        };
    }

    /**
     * These transitions are DECLARATIONS about condition, and the chain above
     * governs those alone.
     *
     * Destruction is not a declaration. A lot emptied by DisposeStock ends up
     * Disposed without walking this chain, and that is not a hole in it: the
     * quantity physically ceased to exist, which is a fact rather than a
     * judgement about airworthiness. Declaring a serviceable lot disposed is
     * still refused here, because the state selector must not become a delete
     * button.
     *
     * What the one-way chain protects is the route BACK INTO SERVICE, and that
     * is untouched either way -- Disposed leads nowhere at all.
     */
    public function isFinal(): bool
    {
        return $this === self::Disposed;
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }
}
