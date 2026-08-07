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
use App\Modules\TaskCards\Models\TaskCard;
use App\Modules\TaskCards\Models\WorkOrder;
use RuntimeException;

/**
 * Deciding to make a change the manufacturer has approved.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * A general note is not a thing an aircraft is overdue on -- it is approved data
 * from the design holder, a way to make a change legally. Vorgabe: "DG kann mir
 * zum Beispiel per general TM erlauben ein fenster einzubauen, was via cs stan
 * nicht möglich ist." The same will be true of CS-STAN when that module exists,
 * and this action is the shape both should share.
 *
 * The moment that matters is the DECISION, and it is a moment with consequences:
 * "wenn ich beschließe eine general TM durchzuführen sollte auch direkt eine
 * workorder aufgehen, dort kann dann das material eingebucht werden etc."
 *
 * So this opens a visit if none is open, raises the card on it, and hands both
 * back. From there the ordinary machinery applies -- hours, parts, findings,
 * and the compliance recorded when the card is CERTIFIED rather than when it is
 * raised. Nobody signs for work at the moment they decide to do it.
 *
 * WHAT IT DOES NOT DO is record the compliance. That stays where it is for
 * every other directive: a statement to an authority, made explicitly by
 * somebody qualified, with the card as evidence.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class AdoptGeneralDirective
{
    public function __construct(
        private Authority $authority,
        private ModuleManager $modules,
        private ScheduleDirectiveCard $cards,
    ) {}

    public function isAvailable(): bool
    {
        return $this->modules->isEnabled('taskcards');
    }

    /**
     * @return array{order: WorkOrder, card: TaskCard, opened: bool}
     */
    public function handle(Directive $directive, Aircraft $aircraft, User $user): array
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException(
                'Ohne das Arbeitskarten-Modul gibt es keinen Vorgang, in dem die Arbeit '
                .'und das Material landen könnten. Die Durchführung lässt sich dann nur '
                .'direkt vermerken.'
            );
        }

        /*
         * The permission to raise the work, not to sign it off. Deciding to fit
         * something and certifying that it was fitted correctly are two acts by
         * possibly two people, and only the second needs the qualification.
         */
        if (! $this->authority->permits($user, Permissions::DIRECTIVES_VIEW)) {
            throw new RuntimeException(sprintf(
                'Eine Anweisung einzuplanen erfordert die Berechtigung "%s".',
                Permissions::DIRECTIVES_VIEW,
            ));
        }

        if (! $directive->mayApplyTo($aircraft)) {
            throw new RuntimeException(sprintf(
                '%s gilt nicht für %s. Der Hersteller nennt dort: %s',
                $directive->label(),
                $aircraft->registration,
                $directive->subject_model ?? $directive->subject_designation ?? 'kein Muster',
            ));
        }

        /*
         * An open visit is reused rather than a second one opened beside it.
         * Two visits on one aircraft on one day is how a club ends up booking
         * parts against the wrong one -- and the whole point of opening a visit
         * here is that the material has somewhere to go.
         */
        $existing = WorkOrder::query()
            ->where('aircraft_id', $aircraft->id)
            ->whereNull('closed_at')
            ->orderByDesc('opened_at')
            ->first();

        $order = $existing ?? app(ManageWorkOrder::class)->open(
            $aircraft,
            sprintf('%s: %s', $directive->label(), $directive->title),
            $user,
            $directive->summary,
        );

        return [
            'order' => $order,
            'card' => $this->cards->handle($directive, $aircraft, $order, $user),
            'opened' => $existing === null,
        ];
    }
}
