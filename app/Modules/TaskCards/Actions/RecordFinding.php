<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Actions;

use App\Core\Access\Authority;
use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Enums\ActivityKind;
use App\Modules\TaskCards\Enums\FindingState;
use App\Modules\TaskCards\Models\Finding;
use App\Modules\TaskCards\Models\TaskCard;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Something noticed, and what becomes of it.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * NOTICING IS NOT DECIDING, and the two need different people.
 *
 * Anybody working on an aircraft may report a finding -- that is an observation,
 * and discouraging it by demanding a licence would mean cracks get mentioned in
 * the tea room instead of the record.
 *
 * Deciding a finding can WAIT is something else. "Holds until the next
 * inspection" is a judgement about airworthiness, and it is frozen with the
 * credential it was made under like every other one in this system.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class RecordFinding
{
    public function __construct(private Authority $authority) {}

    public function record(
        Aircraft $aircraft,
        string $title,
        string $description,
        User $user,
        bool $isBlocking = true,
        ?TaskCard $noticedOn = null,
        ?string $foundOn = null,
    ): Finding {
        if (trim($title) === '' || trim($description) === '') {
            throw new InvalidArgumentException(
                'A finding needs a description -- "Riss" on its own tells the next person '
                .'nothing about where or how big.'
            );
        }

        if (! $this->authority->permits($user, Permissions::FINDINGS_RECORD)) {
            throw new RuntimeException(sprintf(
                'Recording a finding requires the "%s" permission.',
                Permissions::FINDINGS_RECORD,
            ));
        }

        $when = $foundOn !== null ? Carbon::parse($foundOn) : now();

        return Finding::create([
            'aircraft_id' => $aircraft->id,
            'task_card_id' => $noticedOn?->id,
            'number' => $this->nextNumber($when),
            'title' => trim($title),
            'description' => trim($description),
            'is_blocking' => $isBlocking,
            'found_by' => $user->id,
            'found_by_name' => $user->name,
            'found_on' => $when->toDateString(),
        ]);
    }

    /**
     * Raising a card to deal with it.
     *
     * The finding does not close here -- it becomes scheduled. It closes when
     * the card that fixes it is signed off, which is the only moment anybody can
     * honestly say the thing is dealt with.
     */
    public function schedule(Finding $finding, WorkOrder $order, User $user): TaskCard
    {
        if (! $finding->isOutstanding()) {
            throw new RuntimeException(sprintf('This finding is already %s.', $finding->state->label()));
        }

        // The same rule as linking an external order: a crack in D-KXYZ's spar
        // scheduled into D-KABC's annual is a false trace in both records.
        if ((int) $finding->aircraft_id !== (int) $order->aircraft_id) {
            throw new RuntimeException(
                'That finding belongs to a different aircraft than this visit. Scheduling '
                .'it here would put a false trace into both records.'
            );
        }

        return DB::transaction(function () use ($finding, $order): TaskCard {
            $card = app(ManageWorkOrder::class)->addCard(
                $order,
                $finding->title,
                $finding->description,
                ActivityKind::Repair,
            );

            $finding->update([
                'state' => FindingState::Scheduled,
                'resolving_task_card_id' => $card->id,
            ]);

            return $card;
        });
    }

    /**
     * Deciding it can wait.
     *
     * The act with teeth, and the one an auditor reads first. A deferral needs
     * the qualification, is frozen with it, and keeps the finding on the
     * aircraft's open list -- because the entire risk of a deferred finding is
     * that it goes quiet.
     */
    /**
     * The standing a ruling on a finding requires.
     *
     * Same permission and qualification as deferring, because all three are the
     * same kind of act: somebody looks at a defect and answers for what happens
     * to it next. Recording one stays free -- more eyes on defects is the point.
     */
    private function requireDetermination(Finding $finding, User $user, string $verb): void
    {
        if (! $this->authority->permits($user, Permissions::FINDINGS_DEFER)) {
            throw new RuntimeException(sprintf(
                '%s a finding requires the "%s" permission.',
                $verb,
                Permissions::FINDINGS_DEFER,
            ));
        }

        $qualification = $this->authority->qualificationFor(
            $user,
            Permissions::FINDINGS_DEFER,
            $finding->aircraft_registration ?? $finding->aircraft?->registration,
        );

        if ($qualification === null) {
            throw new RuntimeException(sprintf(
                '%s a finding is a determination and is reserved for qualified staff.',
                $verb,
            ));
        }
    }

    public function defer(
        Finding $finding,
        User $user,
        string $reason,
        ?string $until = null,
    ): Finding {
        if (! $finding->isOutstanding()) {
            throw new RuntimeException(sprintf('This finding is already %s.', $finding->state->label()));
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'A deferral has to say why. "Later" is not a reason somebody can be held to.'
            );
        }

        if (! $this->authority->permits($user, Permissions::FINDINGS_DEFER)) {
            throw new RuntimeException(sprintf(
                'Deferring a finding requires the "%s" permission.',
                Permissions::FINDINGS_DEFER,
            ));
        }

        $qualification = $this->authority->qualificationFor(
            $user,
            Permissions::FINDINGS_DEFER,
            $finding->aircraft?->registration,
        );

        if ($qualification === null) {
            throw new RuntimeException(
                'Deciding that a finding can wait is a determination and is reserved for '
                .'qualified staff: a valid Part-66 licence, or a pilot-owner authorisation '
                .'for this aircraft, is required.'
            );
        }

        $finding->update([
            'state' => FindingState::Deferred,
            'deferred_until' => $until,
            'deferral_reason' => trim($reason),
            'deferred_by' => $user->id,
            'deferred_by_name' => $user->name,
            'deferral_qualification_type' => $qualification->type,
            'deferral_qualification_reference' => $qualification->reference,
        ]);

        return $finding->fresh();
    }

    public function resolve(Finding $finding, User $user, string $resolution): Finding
    {
        if ($finding->state === FindingState::Resolved) {
            throw new RuntimeException('This finding is already resolved.');
        }

        if (trim($resolution) === '') {
            throw new InvalidArgumentException('What was done about it has to be recorded.');
        }

        /*
         * Stating that a defect is dealt with is a determination (E8), and the
         * review showed why it cannot be free: a blocking finding stands in the
         * way of the release, and resolve() without a qualification let ANYONE
         * clear that gate with a sentence. The ordinary path costs nothing
         * extra -- certifying the fixing card resolves the finding under the
         * certifier's signature; this manual path is for findings fixed outside
         * a card, and it demands the same standing.
         */
        $this->requireDetermination($finding, $user, 'Resolving');

        $finding->update([
            'state' => FindingState::Resolved,
            'resolved_on' => now()->toDateString(),
            'resolution' => trim($resolution),
        ]);

        return $finding->fresh();
    }

    /**
     * Looked at again and found not to be a defect.
     *
     * Distinct from resolved on purpose: nothing was done, and a record saying
     * otherwise would be wrong in a way somebody could rely on.
     */
    public function dismiss(Finding $finding, User $user, string $reason): Finding
    {
        if (! $finding->isOutstanding()) {
            throw new RuntimeException(sprintf('This finding is already %s.', $finding->state->label()));
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Dismissing a finding has to say why.');
        }

        // "That is not a defect" is as much a determination as "that can wait".
        $this->requireDetermination($finding, $user, 'Dismissing');

        $finding->update([
            'state' => FindingState::Dismissed,
            'resolved_on' => now()->toDateString(),
            'resolution' => trim($reason),
        ]);

        return $finding->fresh();
    }

    private function nextNumber(Carbon $when): string
    {
        $prefix = 'B'.$when->format('Y');

        $last = Finding::withTrashed()
            ->where('number', 'like', $prefix.'-%')
            ->lockForUpdate()
            ->orderByDesc('number')
            ->value('number');

        $next = $last === null ? 1 : ((int) substr((string) $last, -3)) + 1;

        return sprintf('%s-%03d', $prefix, $next);
    }
}
