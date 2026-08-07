<?php

declare(strict_types=1);

namespace App\Modules\Directives\Actions;

use App\Core\Access\Authority;
use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\Directives\Enums\ComplianceState;
use App\Modules\Directives\Models\Directive;
use App\Modules\Directives\Models\DirectiveApplication;
use App\Modules\Directives\Permissions;
use App\Modules\Fleet\Models\Aircraft;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Confirming a line for an aircraft -- the act the module exists for.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * FOUR ANSWERS, NOT TWO. Vorgabe: "es gibt aber nicht nur ja/nein sondern auch
 * nicht zutreffend (mit begründung) und nicht durchgeführt."
 *
 * The pair that has to stay distinct is `open` and `not_carried_out`. Both mean
 * the work has not happened; only one of them means somebody decided that.
 * Collapsing them would lose the single most useful fact in the record -- whether
 * anybody has read the line at all.
 *
 * All three assessments need a qualification, because all three are statements
 * about an aircraft's airworthiness. Saying "does not apply to us" is not the
 * cautious option: get it wrong and a mandatory directive silently leaves the
 * list.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class AssessDirective
{
    public function __construct(private Authority $authority) {}

    /**
     * Carried out.
     *
     * For a recurring directive this also works out when it comes round again --
     * anchored on the date it was ACTUALLY done, not on when it was due. A
     * directive done early recurs early; that is the honest reading of an
     * interval, and the same asymmetry the fleet applies to component limits.
     */
    public function comply(
        Directive $directive,
        Aircraft $aircraft,
        User $user,
        string $method,
        ?string $on = null,
        ?string $taskCardReference = null,
    ): DirectiveApplication {
        if (trim($method) === '') {
            throw new InvalidArgumentException(
                'How it was complied with has to be recorded -- that is what the entry is for.'
            );
        }

        $qualification = $this->requireStanding($directive, $aircraft, $user, 'Complying with');
        $when = $on !== null ? Carbon::parse($on) : now();

        return DB::transaction(function () use ($directive, $aircraft, $user, $qualification, $method, $when, $taskCardReference): DirectiveApplication {
            $application = $this->applicationFor($directive, $aircraft);

            $application->fill([
                'state' => ComplianceState::Complied,
                'assessed_at' => $when->toDateString(),
                'assessed_by' => $user->id,
                'assessed_by_name' => $user->name,
                'qualification_type' => $qualification->type,
                'qualification_reference' => $qualification->reference,
                'method' => trim($method),
                'reason' => null,
                'task_card_reference' => $taskCardReference,
                'counters_at_compliance' => $aircraft->currentValues(),
            ]);

            [$dueAt, $dueValue] = $this->nextDue($directive, $aircraft, $when);
            $application->next_due_at = $dueAt;
            $application->next_due_value = $dueValue;

            $application->save();

            return $application->fresh();
        });
    }

    /**
     * Assessed and it does not apply.
     *
     * Stays in the list. the decision was this over hiding it, and the reason is the
     * one that matters to an inspector: a line marked "not applicable, S/N
     * outside range" proves somebody looked. A line that is simply absent proves
     * nothing at all.
     */
    public function markNotApplicable(
        Directive $directive,
        Aircraft $aircraft,
        User $user,
        string $reason,
        ?string $on = null,
    ): DirectiveApplication {
        return $this->recordNegative(
            $directive, $aircraft, $user, $reason, ComplianceState::NotApplicable, $on,
            'Declaring a directive not applicable',
        );
    }

    /**
     * Applies, and has deliberately not been done.
     *
     * The state that is an answer rather than a gap. Recording it does not make
     * the aircraft airworthy -- a mandatory directive left undone blocks, and the
     * airworthiness check says so. What it does is put a name and a reason next to
     * the fact, which is the difference between a decision and an oversight.
     */
    public function markNotCarriedOut(
        Directive $directive,
        Aircraft $aircraft,
        User $user,
        string $reason,
        ?string $on = null,
    ): DirectiveApplication {
        /*
         * Only an optional line may be refused.
         *
         * Vorgabe: "nur optional darf den status nicht durchgeführt erhalten."
         * There is no declaration for skipping a mandatory directive, so the
         * refusal names the two answers that DO exist rather than just saying no.
         */
        if (! $directive->permitsRefusal()) {
            throw new RuntimeException(sprintf(
                '%s is mandatory. There is no such thing as deciding not to carry it out: '
                .'either it is complied with, or it does not apply to this aircraft -- and '
                .'until then it stands in the way.',
                $directive->label(),
            ));
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Declaring a directive not carried out requires a reason -- without one the '
                .'entry is indistinguishable from somebody clearing their list.'
            );
        }

        /*
         * ─────────────────────────────────────────────────────────────────────
         * PART-66 OR THE HOLDER -- AND EXPLICITLY NOT A PILOT-OWNER.
         *
         * Vorgabe: "nicht durchgeführt braucht auch part66 oder halter (nicht
         * p/o)."
         *
         * The reason it is not simply "a qualification" is that this decision is
         * a different KIND of decision from the others. Complying is technical.
         * Declaring something inapplicable is technical. Deciding that a
         * recommendation will not be followed is a technical judgement (Part-66)
         * OR an operator's call about their own aircraft (the holder) -- cost,
         * benefit, timing.
         *
         * A pilot-owner authorisation is neither: it is a narrow privilege to
         * certify one's own limited maintenance. It says nothing about whether a
         * manufacturer's recommendation may be waived, and treating it as
         * sufficient here would have been the same mistake as letting a
         * pilot-owner release somebody else's work.
         * ─────────────────────────────────────────────────────────────────────
         */
        if (! $this->authority->permits($user, Permissions::DIRECTIVES_ASSESS)) {
            throw new RuntimeException(sprintf(
                'Declaring a directive not carried out requires the "%s" permission.',
                Permissions::DIRECTIVES_ASSESS,
            ));
        }

        $licence = $this->part66Licence($user);
        $isHolder = $this->isHolderOf($aircraft, $user);

        if ($licence === null && ! $isHolder) {
            throw new RuntimeException(sprintf(
                'Deciding against an optional directive is reserved for a Part-66 licence '
                .'holder or for the holder of %s. A pilot-owner authorisation does not cover '
                .'it -- it certifies maintenance, it does not waive the manufacturer\'s '
                .'recommendations.',
                $aircraft->registration,
            ));
        }

        $when = $on !== null ? Carbon::parse($on) : now();
        $application = $this->applicationFor($directive, $aircraft);

        $application->fill([
            'state' => ComplianceState::NotCarriedOut,
            'assessed_at' => $when->toDateString(),
            'assessed_by' => $user->id,
            'assessed_by_name' => $user->name,

            // The capacity the person acted in, because that is the interesting
            // question afterwards: was this a technical judgement or the
            // operator's call?
            'qualification_type' => $licence?->type ?? Directive::CAPACITY_HOLDER,
            'qualification_reference' => $licence?->reference,

            'reason' => trim($reason),
            'method' => null,
            'next_due_at' => null,
            'next_due_value' => null,
        ])->save();

        return $application->fresh();
    }

    /**
     * A valid Part-66 licence, or null.
     *
     * Asked for directly rather than through qualificationFor(), because that
     * accepts a pilot-owner authorisation too -- which is exactly what must not
     * count here.
     */
    private function part66Licence(User $user): ?Qualification
    {
        return $user->validQualifications()
            ->where('type', Qualification::TYPE_PART66)
            ->first();
    }

    /**
     * Whether this person is the holder of this aircraft.
     *
     * Through the holder record's user link. A club aircraft's holder is the club,
     * so this is true for whoever the club's holder record points at -- which is
     * the person who may take that decision for it.
     */
    private function isHolderOf(Aircraft $aircraft, User $user): bool
    {
        $holder = $aircraft->holder;

        return $holder !== null
            && $holder->user_id !== null
            && (int) $holder->user_id === (int) $user->id;
    }

    /**
     * Back to unassessed.
     *
     * For the honest mistake -- wrong aircraft, wrong line. It does NOT erase the
     * previous answer: activitylog holds every state this row has been in, and
     * that is the record. Only the current view resets.
     */
    public function reopen(Directive $directive, Aircraft $aircraft, User $user, string $reason): DirectiveApplication
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Reopening a line has to say why.');
        }

        $this->requireStanding($directive, $aircraft, $user, 'Reopening an assessment');

        $application = $this->applicationFor($directive, $aircraft);

        $application->fill([
            'state' => ComplianceState::Open,
            'reason' => trim($reason),
            'assessed_at' => null,
            'assessed_by' => null,
            'assessed_by_name' => null,
            'qualification_type' => null,
            'qualification_reference' => null,
            'method' => null,
            'task_card_reference' => null,
            'next_due_at' => null,
            'next_due_value' => null,
        ])->save();

        return $application->fresh();
    }

    private function recordNegative(
        Directive $directive,
        Aircraft $aircraft,
        User $user,
        string $reason,
        ComplianceState $state,
        ?string $on,
        string $verb,
    ): DirectiveApplication {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(sprintf(
                '%s requires a reason -- without one the entry is indistinguishable from '
                .'somebody clearing their list.',
                $verb,
            ));
        }

        $qualification = $this->requireStanding($directive, $aircraft, $user, $verb);
        $when = $on !== null ? Carbon::parse($on) : now();

        $application = $this->applicationFor($directive, $aircraft);

        $application->fill([
            'state' => $state,
            'assessed_at' => $when->toDateString(),
            'assessed_by' => $user->id,
            'assessed_by_name' => $user->name,
            'qualification_type' => $qualification->type,
            'qualification_reference' => $qualification->reference,
            'reason' => trim($reason),
            'method' => null,

            // A negative answer has no recurrence: there is nothing to come round.
            'next_due_at' => null,
            'next_due_value' => null,
        ])->save();

        return $application->fresh();
    }

    /**
     * The row for this pair, created unassessed if it does not exist.
     *
     * findOrNew rather than a fresh insert, because the unique index means one row
     * per directive per aircraft: a repeat compliance updates the row and the
     * audit trail carries the history.
     */
    public function applicationFor(Directive $directive, Aircraft $aircraft): DirectiveApplication
    {
        return DirectiveApplication::firstOrNew(
            ['directive_id' => $directive->id, 'aircraft_id' => $aircraft->id],
            [
                'aircraft_registration' => $aircraft->registration,
                'state' => ComplianceState::Open,
            ],
        );
    }

    /**
     * When a recurring directive is due again.
     *
     * @return array{0: ?string, 1: ?float}
     */
    private function nextDue(Directive $directive, Aircraft $aircraft, Carbon $doneAt): array
    {
        if (! $directive->is_recurring) {
            return [null, null];
        }

        $dueAt = $directive->interval_months !== null
            ? $doneAt->copy()->addMonths($directive->interval_months)->toDateString()
            : null;

        $dueValue = null;

        if ($directive->interval_counter !== null && $directive->interval_value !== null) {
            $current = $aircraft->currentValues()[$directive->interval_counter] ?? null;

            // Counted from the reading at compliance, so an aircraft that has
            // flown 300 hours gets its next 100-hour item at 400, not at 100.
            $dueValue = $current !== null
                ? (float) $current + (float) $directive->interval_value
                : (float) $directive->interval_value;
        }

        return [$dueAt, $dueValue];
    }

    /**
     * The standing an assessment requires.
     *
     * Permission and qualification, the same two-stage authority the rest of the
     * project uses (E8): the permission says somebody may operate the function,
     * the qualification says they may answer for the statement.
     */
    private function requireStanding(
        Directive $directive,
        Aircraft $aircraft,
        User $user,
        string $verb,
    ): Qualification {
        if (! $this->authority->permits($user, Permissions::DIRECTIVES_ASSESS)) {
            throw new RuntimeException(sprintf(
                '%s requires the "%s" permission.',
                $verb,
                Permissions::DIRECTIVES_ASSESS,
            ));
        }

        $qualification = $this->authority->qualificationFor(
            $user,
            Permissions::DIRECTIVES_ASSESS,
            $aircraft->registration,
        );

        if ($qualification === null) {
            throw new RuntimeException(sprintf(
                '%s is a statement about this aircraft and is reserved for qualified staff: '
                .'a valid Part-66 licence, or a pilot-owner authorisation for %s.',
                $verb,
                $aircraft->registration,
            ));
        }

        return $qualification;
    }
}
