<?php

declare(strict_types=1);

namespace App\Modules\Directives\Actions;

use App\Core\Access\Authority;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Directives\Models\Directive;
use App\Modules\Directives\Permissions;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Enums\ActivityKind;
use App\Modules\TaskCards\Models\TaskCard;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Permissions as TaskCardPermissions;
use RuntimeException;

/**
 * Raising a task card for a directive.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * OPTIONAL, LIKE THE PARTS ISSUE. This module requires the fleet, not the task
 * cards -- a club that keeps an LTA list without work orders is a real
 * arrangement, and the list works as a plain tick there.
 *
 * The loop mirrors the findings: raise the card, and the compliance is recorded
 * when that card is CERTIFIED, not when it is raised. Until then the directive
 * stays outstanding, because the work has not been checked yet.
 *
 * Deliberately NOT automatic. A finding gets resolved by its card's signature
 * because a finding is a defect somebody reported; a directive compliance is a
 * statement to an authority, and somebody qualified says it explicitly -- with
 * the card number as evidence. The card is how the work gets organised, not who
 * signs for it.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class ScheduleDirectiveCard
{
    public function __construct(
        private Authority $authority,
        private ModuleManager $modules,
    ) {}

    public function isAvailable(): bool
    {
        return $this->modules->isEnabled('taskcards');
    }

    public function handle(
        Directive $directive,
        Aircraft $aircraft,
        WorkOrder $order,
        User $user,
    ): TaskCard {
        if (! $this->isAvailable()) {
            throw new RuntimeException(
                'The task cards module is not enabled, so there is no visit to add a card to. '
                .'A compliance can still be recorded directly.'
            );
        }

        if (! $this->authority->permits($user, Permissions::DIRECTIVES_VIEW)) {
            throw new RuntimeException(sprintf(
                'Raising a card for a directive requires the "%s" permission.',
                Permissions::DIRECTIVES_VIEW,
            ));
        }

        /*
         * AND the task-card permission, not instead of it. This action writes
         * into somebody's work order; inside the task cards module that takes
         * CARDS_WORK, and a second door with a lower sill would make the rule
         * over there decorative. Reading the directive list must never be
         * enough to put work onto a visit.
         */
        if (! $this->authority->permits($user, TaskCardPermissions::CARDS_WORK)) {
            throw new RuntimeException(sprintf(
                'Raising a card also requires the "%s" permission -- it writes into the visit.',
                TaskCardPermissions::CARDS_WORK,
            ));
        }

        if ((int) $order->aircraft_id !== (int) $aircraft->id) {
            throw new RuntimeException(sprintf(
                'That visit belongs to a different aircraft than %s. A card for this '
                .'directive there would put a false trace into both records.',
                $aircraft->registration,
            ));
        }

        if ($directive->isSuperseded()) {
            throw new RuntimeException(sprintf(
                '%s has been superseded by %s -- raise the card for that one.',
                $directive->label(),
                $directive->supersededBy?->label() ?? '?',
            ));
        }

        return app(ManageWorkOrder::class)->addCard(
            $order,
            sprintf('%s: %s', $directive->label(), $directive->title),

            // The instruction carries what the line actually says, plus where to
            // read it -- the person at the aircraft should not have to go looking.
            trim(sprintf(
                "%s\n\n%s%s",
                $directive->summary ?? '',
                $directive->comply_before !== null
                    ? 'Frist: '.$directive->comply_before->format('d.m.Y')."\n"
                    : '',
                $directive->reference_url ?? '',
            )) ?: null,

            // The task cards module already had a kind for exactly this, which is
            // what makes the two modules fit without either knowing the other:
            // "AD compliance" was named before this module existed.
            ActivityKind::AdCompliance,
        );
    }
}
