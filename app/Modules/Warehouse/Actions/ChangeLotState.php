<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Actions;

use App\Core\Access\Authority;
use App\Models\User;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\MovementType;
use App\Modules\Warehouse\Models\LotStateChange;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StockMovement;
use App\Modules\Warehouse\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Moving a lot from one condition to another.
 *
 * Where three decisions meet:
 *
 *  - E5/4.6 -- the chain runs one way, and unsalvageable is the end of it. A
 *    part that has reached its life limit or carries a non-repairable defect
 *    must never re-enter the supply system, so the transition back does not
 *    exist. Not for an administrator either.
 *  - E8 -- determinations require a qualification, precautionary blocking does
 *    not. Setting something aside because its paperwork is missing is a
 *    reversible act anyone may perform; stating that a part is unusable is a
 *    judgement someone answers for.
 *  - E7 -- a determination is frozen. Name and credential are copied into the
 *    record, so it stays readable when the account is pseudonymised or the
 *    licence renewed under a new number.
 */
final readonly class ChangeLotState
{
    public function __construct(private Authority $authority) {}

    public function handle(
        StockLot $lot,
        LotState $target,
        string $reason,
        ?User $user,
        ?string $occurredAt = null,
    ): LotStateChange {
        $from = $lot->state;

        if ($from === $target) {
            throw new InvalidArgumentException('The lot is already in that state.');
        }

        if (! $from->canTransitionTo($target)) {
            throw new RuntimeException(sprintf(
                'A lot cannot go from "%s" to "%s".%s',
                $from->label(),
                $target->label(),
                $from === LotState::Unsalvageable
                    ? ' A scrapped part must never re-enter the supply system.'
                    : '',
            ));
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required -- it is what the record is for.');
        }

        $qualification = null;

        if ($this->requiresQualification($from, $target)) {
            /*
             * Nobody attached to the act, and the act is a determination. That
             * is refused outright rather than recorded as anonymous: a
             * statement about a part's condition is only worth what the person
             * behind it is worth, and an unsigned one is worth nothing.
             *
             * A PRECAUTIONARY block is a different matter and may happen
             * unattended -- the incoming inspection sets arrivals aside that
             * way, and an import books stock in with no user at all. Setting
             * something aside claims nothing about it.
             */
            if ($user === null) {
                throw new RuntimeException(
                    'A determination has to be made by somebody: this transition cannot happen unattended.'
                );
            }

            $permission = $this->permissionFor($target);

            // Two different reasons for a refusal, and they need two different
            // messages: lacking the permission is an administrative matter, while
            // lacking the qualification is a statement about the person. Rolling
            // them into one sentence sends people looking in the wrong place --
            // the tests caught exactly that.
            if (! $this->authority->permits($user, $permission)) {
                throw new RuntimeException(sprintf(
                    'You do not hold the "%s" permission, which this determination requires.',
                    $permission,
                ));
            }

            $qualification = $this->authority->qualificationFor($user, $permission);

            if ($qualification === null) {
                throw new RuntimeException(
                    'This determination is reserved for qualified staff: a valid Part-66 licence is required.'
                );
            }
        }

        return DB::transaction(function () use ($lot, $from, $target, $reason, $user, $qualification, $occurredAt): LotStateChange {
            $when = $occurredAt !== null ? Carbon::parse($occurredAt) : now();

            $change = LotStateChange::create([
                'stock_lot_id' => $lot->id,
                'from_state' => $from,
                'to_state' => $target,
                'reason' => trim($reason),
                'quarantine_tag' => $target === LotState::Quarantined
                    ? $this->nextQuarantineTag($when)
                    : null,
                'user_id' => $user?->id,

                // Certificate content, copied rather than referenced -- E7.
                'determined_by_name' => $qualification !== null ? $user?->name : null,
                'qualification_type' => $qualification?->type,
                'qualification_reference' => $qualification?->reference,
                'qualification_category' => $qualification?->category,
                'qualification_valid_until' => $qualification?->valid_until,

                'occurred_at' => $when,
            ]);

            $lot->update(['state' => $target]);

            // Disposal is a movement, not a deletion. The quantity goes to nil
            // and the record stays -- otherwise the evidence that the part ever
            // existed goes out with the rubbish, and that is what an audit asks
            // about.
            if ($target === LotState::Disposed) {
                $remaining = $lot->remainingQuantity();

                if ($remaining > 0) {
                    StockMovement::create([
                        'part_type_id' => $lot->part_type_id,
                        'stock_lot_id' => $lot->id,
                        'type' => MovementType::Disposal,
                        'quantity' => -1 * $remaining,
                        'occurred_at' => $when,
                        // Guaranteed present -- disposal is a determination, and
                        // those are refused above when nobody is attached.
                        'user_id' => $user?->id,
                        'note' => trim($reason),
                    ]);
                }
            }

            return $change;
        });
    }

    /**
     * Which permission covers this determination.
     *
     * Scrapping and disposal sit together; everything else that is a
     * determination -- unserviceable, or fit for service again -- is the
     * quarantine certification. The two are NOT hierarchical: someone allowed
     * to scrap does not thereby gain the right to release parts back into
     * service. A role bundles them where that makes sense, which is a decision
     * for the club rather than something baked into the code.
     */
    public function permissionFor(LotState $target): string
    {
        return match ($target) {
            LotState::Unsalvageable, LotState::Disposed => Permissions::STOCK_SCRAP,
            default => Permissions::STOCK_QUARANTINE_CERTIFY,
        };
    }

    /**
     * Precautionary or determined?
     *
     * The dividing line is not between "blocked" and "scrapped" but between
     * pulling something out of circulation and pronouncing on its condition.
     * Setting a lot aside because its paperwork has not arrived needs nothing;
     * releasing it again does, because that is a statement that it is fit.
     */
    public function requiresQualification(LotState $from, LotState $target): bool
    {
        if ($target === LotState::Quarantined) {
            return false;
        }

        return $target->requiresQualification()
            || ($from === LotState::Quarantined && $target === LotState::Serviceable)
            || ($from === LotState::Unserviceable && $target === LotState::Serviceable);
    }

    /**
     * Quarantine tags run YYYYMM-NNN, restarting each month.
     *
     * Assigned when the lot is set aside and never reused, even if the block is
     * lifted again: the slip was printed and hung on the part.
     */
    private function nextQuarantineTag(Carbon $when): string
    {
        $prefix = $when->format('Ym');

        $last = LotStateChange::query()
            ->where('quarantine_tag', 'like', $prefix.'-%')
            ->lockForUpdate()
            ->orderByDesc('quarantine_tag')
            ->value('quarantine_tag');

        $next = $last === null ? 1 : ((int) substr((string) $last, -3)) + 1;

        return sprintf('%s-%03d', $prefix, $next);
    }
}
