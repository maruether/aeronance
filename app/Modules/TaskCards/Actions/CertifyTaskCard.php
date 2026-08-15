<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Actions;

use App\Core\Access\Authority;
use App\Models\User;
use App\Modules\Fleet\Actions\RecordMaintenance;
use App\Modules\Fleet\Models\ComponentLimit;
use App\Modules\TaskCards\Enums\FindingState;
use App\Modules\TaskCards\Enums\ParticipationKind;
use App\Modules\TaskCards\Enums\TaskCardState;
use App\Modules\TaskCards\Models\Finding;
use App\Modules\TaskCards\Models\TaskCard;
use App\Modules\TaskCards\Permissions;
use App\Modules\TaskCards\Support\CertifyingScope;
use App\Modules\TaskCards\Support\OwnWorkOnly;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * The two signatures on a card.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * COMPLETE says the work is finished. CERTIFY says it was done properly.
 *
 * the decision was this over a single signature: "wer die arbeit gemacht hat, meldet
 * sie fertig. ein Qualifizierter zeichnet sie danach ab. das bildet die
 * werkstattrealität ab."
 *
 * Which it does, and it also resolves something a single signature cannot. A
 * mechanic without a licence has to be able to finish his own card -- otherwise
 * somebody else signs for work they did not do, which is worse than the problem
 * it solves. And unchecked work must not read as certified. Two signatures make
 * both true at once.
 *
 * Only the second is a determination, so only the second needs a qualification
 * and freezes the credential. Recording that you finished a job is a statement
 * of fact about your own afternoon.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class CertifyTaskCard
{
    public function __construct(
        private Authority $authority,
        private RecordMaintenance $recordMaintenance,
    ) {}

    /**
     * First signature: the work is finished.
     *
     * Needs the permission and nothing else. The person who did it is the person
     * who knows, and demanding a licence here would mean the licence holder
     * signs for an afternoon he did not spend.
     */
    /**
     * @param  int|null  $minutes  Arbeitszeit, gleich mit erfasst
     */
    public function complete(
        TaskCard $card,
        User $user,
        string $workPerformed,
        ?int $minutes = null,
        ParticipationKind $as = ParticipationKind::Executed,
    ): TaskCard {
        if ($card->state !== TaskCardState::Open) {
            throw new RuntimeException(sprintf('This card is already %s.', $card->state->label()));
        }

        if (trim($workPerformed) === '') {
            throw new InvalidArgumentException(
                'What was actually done has to be recorded -- the instruction says what was '
                .'asked for, not what happened.'
            );
        }

        if (! $this->authority->permits($user, Permissions::CARDS_WORK)) {
            throw new RuntimeException(sprintf(
                'Completing a card requires the "%s" permission.',
                Permissions::CARDS_WORK,
            ));
        }

        /*
         * ─────────────────────────────────────────────────────────────────────
         * DIE ZEIT GLEICH HIER, wenn sie mitgegeben wird.
         *
         * Feldtest: "beim fertigmelden sollte man auch eine arbeitszeit
         * eintragen können. Der prozess erst zeit eintragen dann fertig melden
         * nervt." Genau so war es: Die Wache unten verlangt Stunden, und wer
         * sie nicht vorher eingetragen hatte, wurde weggeschickt, um über
         * einen zweiten Dialog wiederzukommen.
         *
         * Die Zeit bleibt ein eigener Datensatz mit eigener Person und
         * eigener Art der Beteiligung -- der Erfahrungsnachweis lebt davon.
         * Hier wird nur der häufigste Fall abgekürzt: Wer meldet, hat es
         * selbst gemacht.
         * ─────────────────────────────────────────────────────────────────────
         */
        if ($minutes !== null && $minutes > 0) {
            app(ManageWorkOrder::class)->recordTime(
                card: $card,
                person: $user,
                minutes: $minutes,
                as: $as,
            );

            $card->load('times');
        }

        if ($card->times()->doesntExist()) {
            /*
             * No hours, no completion.
             *
             * Not bureaucracy: the experience logbook is meant to be an
             * evaluation of these cards rather than a second thing to keep, and
             * a card with no hours contributes nothing to it. Asked for now,
             * while somebody remembers, rather than reconstructed in January.
             */
            throw new RuntimeException(
                'No working time has been recorded on this card. The experience log is '
                .'derived from these entries, so a card without them is one that never '
                .'happened as far as anybody\'s licence is concerned.'
            );
        }

        $card->update([
            'state' => TaskCardState::Completed,
            'completed_at' => now(),
            'completed_by' => $user->id,
            'completed_by_name' => $user->name,
            'work_performed' => trim($workPerformed),
        ]);

        return $card->fresh();
    }

    /**
     * Second signature: somebody qualified has checked it.
     *
     * And if the card was raised against a fleet limit, this is where that limit
     * is ticked off -- the rule for how the two modules meet: "eine
     * anstehende aufgabe bekommt eine arbeitskarte, wenn diese abgezeichnet ist,
     * ist auch die aufgabe erledigt."
     */
    public function certify(TaskCard $card, User $user, ?string $certifiedAt = null): TaskCard
    {
        if ($card->state === TaskCardState::Open) {
            throw new RuntimeException(
                'This card has not been completed yet. Somebody has to finish the work '
                .'before anybody can say it was done properly.'
            );
        }

        if ($card->state !== TaskCardState::Completed) {
            throw new RuntimeException(sprintf('This card is already %s.', $card->state->label()));
        }

        /*
         * ─────────────────────────────────────────────────────────────────────
         * KRITISCHE ARBEIT OHNE UNABHAENGIGE KONTROLLE WIRD NICHT FREIGEGEBEN.
         *
         * Hier bekommt die Kontrolle ihre Zaehne. Ohne diese Zeile waere die
         * Markierung "kritisch" eine Notiz, die man ueberliest -- und der
         * Nachweis entstuende genau dann nicht, wenn es eilig ist, also im
         * einzigen Fall, der zaehlt.
         *
         * Die Reihenfolge ist damit: fertiggemeldet -> kontrolliert ->
         * freigegeben. Siehe InspectCriticalTask.
         * ─────────────────────────────────────────────────────────────────────
         */
        if ($card->critical && $card->inspected_at === null) {
            throw new RuntimeException(__('taskcards.inspection.refused.certify_without_inspection'));
        }

        // Two refusals with two messages: lacking the permission is
        // administrative, lacking the licence is about the person.
        if (! $this->authority->permits($user, Permissions::CARDS_CERTIFY)) {
            throw new RuntimeException(sprintf(
                'Signing a card off requires the "%s" permission.',
                Permissions::CARDS_CERTIFY,
            ));
        }

        $qualification = $this->authority->qualificationFor(
            $user,
            Permissions::CARDS_CERTIFY,
            $card->aircraft_registration,
        );

        if ($qualification === null) {
            throw new RuntimeException(
                'Signing off a card is a determination and is reserved for qualified '
                .'staff: a valid Part-66 licence, or a pilot-owner authorisation for this '
                .'aircraft, is required.'
            );
        }

        /*
         * THE PILOT-OWNER LIMIT, and it is not a nuance.
         *
         * Part-66 certifying staff release work whoever performed it -- that is
         * what the licence is for. A pilot-owner authorisation lets somebody
         * sign for their OWN limited maintenance and nothing else. Treating the
         * two as interchangeable, which is what this did until it was pointed
         * it out, hands pilot-owners a certifying privilege they do not have.
         */
        if (! OwnWorkOnly::permits($qualification, $card, $user)) {
            throw new RuntimeException(sprintf(
                'A pilot-owner authorisation only covers work carried out personally. %s',
                OwnWorkOnly::refusalReason($card, $user),
            ));
        }

        /*
         * WHOSE work was the question above. WHAT work is this one, and they are
         * genuinely separate.
         *
         * A licence endorsed "no maintenance exceeding MA.803(b)" -- which is
         * how converted national licences often read -- may sign for OTHER
         * people's work, and only for the tasks a pilot-owner could have done.
         * Neither rule implies the other, so neither replaces the other; a
         * limitation excluding a construction method (metal, say) is checked
         * here too, and applies across the whole licence regardless of category.
         */
        $refusal = CertifyingScope::refusalFor($qualification, $card, $user);

        if ($refusal !== null) {
            throw new RuntimeException($refusal);
        }

        return DB::transaction(function () use ($card, $user, $qualification, $certifiedAt): TaskCard {
            $card->update([
                'state' => TaskCardState::Certified,
                'certified_at' => $certifiedAt !== null ? $certifiedAt : now(),
                'certified_by' => $user->id,

                // Certificate content, copied at the moment of the act (E7).
                'certified_by_name' => $user->name,
                'qualification_type' => $qualification->type,
                'qualification_reference' => $qualification->reference,
                'qualification_category' => $qualification->category,
            ]);

            $this->dischargeLimit($card);

            /*
             * The card that was raised FOR a finding resolves it at this
             * signature -- not when the card was raised, not when the work was
             * reported done. Three places in the module promised this and none
             * delivered it; the finding sat in "scheduled" forever, which the
             * release gate now reads as blocking.
             */
            Finding::query()
                ->where('resolving_task_card_id', $card->id)
                ->where('state', FindingState::Scheduled->value)
                ->get()
                ->each(function (Finding $finding) use ($card, $user): void {
                    $finding->update([
                        'state' => FindingState::Resolved,
                        'resolved_on' => now()->toDateString(),
                        'resolution' => sprintf(
                            'Behoben mit Karte %s, abgezeichnet von %s.',
                            $card->number,
                            $user->name,
                        ),
                    ]);
                });

            return $card->fresh();
        });
    }

    /**
     * A signed card discharges the limit it was raised against.
     *
     * Through the fleet's own action, so the asymmetric anchor rule applies
     * unchanged -- done late anchors on the old due date, done early on the
     * actual one. Restating that rule here would be restating it wrongly within
     * a year.
     *
     * Failure is swallowed on purpose. The card IS signed; if the limit cannot
     * be moved on -- the aircraft stopped keeping that counter, somebody deleted
     * the limit -- that is a fleet problem and must not undo a signature that
     * was properly given.
     */
    private function dischargeLimit(TaskCard $card): void
    {
        if ($card->component_limit_id === null) {
            return;
        }

        $limit = ComponentLimit::find($card->component_limit_id);

        if ($limit === null) {
            return;
        }

        try {
            $this->recordMaintenance->handle($limit, $card->certified_at?->toDateString());
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function cancel(TaskCard $card, User $user, string $reason): TaskCard
    {
        if ($card->isCertified()) {
            throw new RuntimeException('A signed card is not cancelled. Raise a new one.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A cancelled card has to say why.');
        }

        $card->update([
            'state' => TaskCardState::Cancelled,
            'cancellation_reason' => trim($reason),
        ]);

        /*
         * A finding that was scheduled onto this card is OUTSTANDING again --
         * the work it waited for is not going to happen. Without this, the
         * cancelled card left the finding in "scheduled" with a dead reference,
         * and (before the gate learned better) a release could pass over an
         * unresolved blocking defect nobody qualified had ruled on.
         */
        Finding::query()
            ->where('resolving_task_card_id', $card->id)
            ->where('state', FindingState::Scheduled->value)
            ->get()
            ->each(fn (Finding $finding) => $finding->update([
                'state' => FindingState::Open,
                'resolving_task_card_id' => null,
            ]));

        return $card->fresh();
    }
}
