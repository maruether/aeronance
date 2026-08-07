<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Actions;

use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\TaskCards\Models\TaskCard;
use App\Modules\Warehouse\Actions\IssueStock;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StockMovement;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Taking a part out of the store for a card.
 *
 * CLAUDE.md: "Teileentnahme nur, wenn das Lagermodul aktiv ist." So this is the
 * one place in the module that asks whether another module is there -- and it
 * asks rather than requiring, because a club with cards and no store is a real
 * arrangement and the cards still work.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * IT BOOKS THROUGH THE WAREHOUSE'S OWN ACTION, WHICH IS THE ENTIRE POINT.
 *
 * Every rule the warehouse enforces then applies unchanged: FEFO, the expiry
 * check, the quarantine cupboard, and -- the one that would be easiest to lose
 * here -- a removal lot without a Form 1 going back only into the aircraft it
 * came out of. Restating any of that would mean restating it wrongly within a
 * year, and this is precisely the path where somebody would be tempted to.
 *
 * The card contributes what the warehouse cannot know: which job this was for,
 * and which aircraft it went into. Both already exist as plain strings on a
 * movement, so nothing new crosses the boundary.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class IssuePartToCard
{
    public function __construct(
        private ModuleManager $modules,
        private IssueStock $issueStock,
    ) {}

    public function isAvailable(): bool
    {
        return $this->modules->isEnabled('warehouse');
    }

    public function handle(
        TaskCard $card,
        PartType $partType,
        float $quantity,
        User $user,
        ?StockLot $lot = null,
        ?string $note = null,
    ): StockMovement {
        if (! $this->isAvailable()) {
            throw new RuntimeException(
                'The warehouse module is not enabled, so there is no store to take a part '
                .'out of.'
            );
        }

        if ($card->isCertified()) {
            throw new RuntimeException(
                'This card has been signed off. A part booked to it afterwards would '
                .'change what somebody put their name to.'
            );
        }

        /*
         * The write below is a warehouse movement -- no TaskCard row is touched,
         * so the model freeze never fires on this path. These two checks are the
         * card-side of the freeze: a cancelled card is work nobody did, and a
         * released visit's parts trail sits under a certificate.
         */
        if ($card->state->value === 'cancelled') {
            throw new RuntimeException(
                'This card was cancelled -- work nobody did consumes no parts.'
            );
        }

        if ($card->workOrder?->isReleased() ?? false) {
            throw new RuntimeException(
                'The visit this card belongs to has been released to service. Its parts '
                .'trail is part of what was certified.'
            );
        }

        /*
         * Straight through the warehouse. The aircraft registration matters more
         * than it looks: it is what makes the warehouse refuse a removal lot
         * belonging to a different aircraft, and passing it is the difference
         * between that rule working here and being quietly bypassed.
         */
        return $this->issueStock->handle(
            $partType,
            $quantity,
            $lot,
            $user,
            $card->number,
            $card->aircraft_registration,
            $note,
        );
    }

    /**
     * What has already gone to this card.
     *
     * Read back out of the ledger rather than kept a second time here. The
     * warehouse owns that record, and a copy would be a second truth that drifts.
     *
     * @return Collection<int, StockMovement>
     */
    public function issuedTo(TaskCard $card)
    {
        if (! $this->isAvailable()) {
            return collect();
        }

        return StockMovement::query()
            ->where('work_order_reference', $card->number)
            ->with(['partType', 'lot'])
            ->orderBy('occurred_at')
            ->get();
    }
}
