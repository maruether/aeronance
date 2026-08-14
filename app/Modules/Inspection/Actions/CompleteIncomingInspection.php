<?php

declare(strict_types=1);

namespace App\Modules\Inspection\Actions;

use App\Models\User;
use App\Modules\Inspection\Enums\CheckItem;
use App\Modules\Inspection\Enums\CheckResult;
use App\Modules\Inspection\Enums\InspectionState;
use App\Modules\Inspection\Models\IncomingInspection;
use App\Modules\Inspection\Models\InspectionCheck;
use App\Modules\Warehouse\Actions\ChangeLotState;
use App\Modules\Warehouse\Enums\LotState;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Signing an incoming inspection off.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ACCEPTANCE IS THE WAREHOUSE'S ACT, PERFORMED THROUGH THE WAREHOUSE.
 *
 * Lifting the quarantine says "this is fit for service", and the warehouse
 * already decided what that costs: the Part-66 qualification behind
 * Quarantined → Serviceable (E8). This module does not get to soften that by
 * writing the state itself, and it does not get to duplicate the rule either --
 * a copy would drift, and it would drift in the permissive direction, because
 * that is the direction bugs go unnoticed.
 *
 * So acceptance goes through ChangeLotState and inherits every refusal it
 * makes. If the person at the counter is not qualified to release parts, the
 * acceptance fails and the goods stay where they are.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * REJECTION MOVES NOTHING.
 *
 * It leaves the goods quarantined and writes down why. Whether they go back to
 * the supplier, sit in the corner awaiting a decision, or turn out to be scrap
 * is a separate act with its own record -- and, for scrap, its own
 * qualification. An inspection that could scrap parts as a side effect of a
 * dropdown would be the cheapest way in the system to destroy evidence.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class CompleteIncomingInspection
{
    public function __construct(private ChangeLotState $changeLotState) {}

    /**
     * @param  array<string, array{result: CheckResult|string|null, note?: string|null}>  $answers
     *                                                                                              Keyed by check item value.
     */
    public function accept(
        IncomingInspection $inspection,
        User $user,
        array $answers = [],
        ?string $note = null,
    ): IncomingInspection {
        return $this->decide($inspection, $user, InspectionState::Accepted, $answers, $note);
    }

    /** @param  array<string, array{result: CheckResult|string|null, note?: string|null}>  $answers */
    public function reject(
        IncomingInspection $inspection,
        User $user,
        array $answers = [],
        ?string $note = null,
    ): IncomingInspection {
        return $this->decide($inspection, $user, InspectionState::Rejected, $answers, $note);
    }

    /** @param  array<string, array{result: CheckResult|string|null, note?: string|null}>  $answers */
    private function decide(
        IncomingInspection $inspection,
        User $user,
        InspectionState $outcome,
        array $answers,
        ?string $note,
    ): IncomingInspection {
        /*
         * A decided inspection is final. Correcting one means recording a new
         * finding against the goods -- the same rule that governs a release to
         * service, and for the same reason: a record that can be revised is not
         * evidence of anything.
         */
        if (! $inspection->state->isOpen()) {
            throw new InvalidArgumentException(
                __('inspection.refused.already_decided', ['state' => $inspection->state->label()]),
            );
        }

        return DB::transaction(function () use ($inspection, $user, $outcome, $answers, $note): IncomingInspection {
            $this->recordAnswers($inspection, $answers);

            $inspection->load('checks');

            /*
             * EVERY question answered -- for acceptance AND for rejection.
             *
             * The temptation is to let a rejection through with three items
             * open, because the delivery is going back anyway. But the reason it
             * is going back is the finding, and the finding is worth nothing if
             * nobody wrote down what else was wrong with the consignment.
             */
            if (! $inspection->isAnswered()) {
                throw new InvalidArgumentException(__('inspection.refused.unanswered'));
            }

            foreach ($inspection->checks as $check) {
                if ($check->needsNote()) {
                    throw new InvalidArgumentException(
                        __('inspection.refused.note_missing', ['item' => $check->item->label()]),
                    );
                }
            }

            $note = trim((string) $note);

            /*
             * Something failed and it is being accepted anyway. That happens for
             * good reasons -- a dented box around an undamaged part -- and it is
             * allowed, but not silently: whoever signs has to say why. Refusing
             * it outright would only teach people to tick "pass" on the box.
             */
            if ($outcome === InspectionState::Accepted && $inspection->hasFailures() && $note === '') {
                throw new InvalidArgumentException(__('inspection.refused.accept_despite_failure'));
            }

            if ($outcome === InspectionState::Rejected && $note === '') {
                throw new InvalidArgumentException(__('inspection.refused.reject_without_reason'));
            }

            if ($outcome === InspectionState::Accepted) {
                $this->release($inspection, $user);
            }

            $inspection->update([
                'state' => $outcome,
                'decided_by_id' => $user->getKey(),
                // Copied, not referenced: the record has to keep saying who
                // signed it after the account is renamed or the person leaves.
                'decided_by_name' => $user->name,
                'decided_at' => now(),
                'decision_note' => $note !== '' ? $note : null,
            ]);

            return $inspection->fresh(['checks']);
        });
    }

    /**
     * Lift the hold the arrival put on the goods.
     */
    private function release(IncomingInspection $inspection, User $user): void
    {
        $lot = $inspection->lot;

        if ($lot === null || $lot->state !== LotState::Quarantined) {
            /*
             * Bulk stock has no lot to release, and a lot somebody already dealt
             * with by hand is not this action's business. Accepting still gets
             * recorded either way -- the record is the part of this that always
             * applies.
             */
            return;
        }

        /*
         * ─────────────────────────────────────────────────────────────────────
         * ANNEHMEN IST NICHT FREIGEBEN -- und beim Papier trennt sich das.
         *
         * Eine Annahme trotz Mangels ist erlaubt und richtig: Ein verbeulter
         * Karton um ein heiles Teil ist genau der Fall, für den es die
         * Begründung gibt. Ein durchgefallener ZERTIFIKATS-Punkt ist aber
         * etwas anderes -- er sagt, dass der Nachweis nicht stimmt, und dann
         * lässt sich die Lufttüchtigkeit nicht feststellen (ML.A.501). Die
         * Ware wird angenommen (sie liegt ja da), das Los bleibt gesperrt.
         *
         * Die allgemeine Nachweis-Wache in ChangeLotState fängt nur den Fall
         * "keine Nummer erfasst". Hier steht der andere: Nummer da, Prüfung
         * durchgefallen.
         * ─────────────────────────────────────────────────────────────────────
         */
        foreach ($inspection->checks as $check) {
            if ($check->item === CheckItem::Certificate && $check->result === CheckResult::Fail) {
                return;
            }
        }

        $this->changeLotState->handle(
            lot: $lot,
            target: LotState::Serviceable,
            reason: __('inspection.release_reason'),
            user: $user,
        );
    }

    /** @param  array<string, array{result: CheckResult|string|null, note?: string|null}>  $answers */
    private function recordAnswers(IncomingInspection $inspection, array $answers): void
    {
        if ($answers === []) {
            return;
        }

        foreach ($inspection->checks as $check) {
            $given = $answers[$check->item->value] ?? null;

            if ($given === null) {
                continue;
            }

            $result = $given['result'] ?? null;

            $check->update([
                'result' => $result instanceof CheckResult ? $result : ($result !== null ? CheckResult::from((string) $result) : null),
                'note' => $given['note'] ?? $check->note,
            ]);
        }

        $inspection->setRelation('checks', $inspection->checks()->get());
    }

    /**
     * The unanswered items, for a caller that wants to say what is missing
     * before the refusal above happens.
     *
     * @return list<InspectionCheck>
     */
    public function missing(IncomingInspection $inspection): array
    {
        return $inspection->checks
            ->filter(fn (InspectionCheck $check): bool => $check->result === null)
            ->values()
            ->all();
    }
}
