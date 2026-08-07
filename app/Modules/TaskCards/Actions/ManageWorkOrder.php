<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Actions;

use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\ComponentLimit;
use App\Modules\Fleet\Models\ExternalWorkOrder;
use App\Modules\TaskCards\Enums\ActivityKind;
use App\Modules\TaskCards\Enums\ParticipationKind;
use App\Modules\TaskCards\Models\TaskCard;
use App\Modules\TaskCards\Models\TaskCardTime;
use App\Modules\TaskCards\Models\WorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Opening a visit, writing cards, recording hours, closing it again.
 */
final class ManageWorkOrder
{
    public function open(
        Aircraft $aircraft,
        string $title,
        User $user,
        ?string $description = null,
        ?string $openedAt = null,
    ): WorkOrder {
        if (trim($title) === '') {
            throw new InvalidArgumentException('A visit needs a title -- it is what people look for later.');
        }

        $when = $openedAt !== null ? Carbon::parse($openedAt) : now();

        // The transaction is what makes the generator's lockForUpdate real:
        // under autocommit the lock evaporates at the end of the SELECT, and
        // two concurrent opens both read the same "last" number.
        return DB::transaction(fn (): WorkOrder => WorkOrder::create([
            'aircraft_id' => $aircraft->id,
            'number' => $this->nextNumber($when),
            'title' => trim($title),
            'description' => $description,
            'opened_at' => $when->toDateString(),
            'opened_by' => $user->id,

            // Copied now, because a card written six weeks later has to say what
            // the aircraft had done when the work began.
            'counters_at_open' => $aircraft->currentValues(),
        ]));
    }

    /**
     * @param  ComponentLimit|null  $forLimit  the fleet limit this card discharges
     */
    public function addCard(
        WorkOrder $order,
        string $title,
        ?string $instruction = null,
        ActivityKind $kind = ActivityKind::Maintenance,
        ?string $ataChapter = null,
        ?ComponentLimit $forLimit = null,
        bool $critical = false,
        ?string $criticalReason = null,
        ?string $manualReference = null,
    ): TaskCard {
        if (! $order->isOpen()) {
            throw new RuntimeException('Cards are added to an open visit, not a closed one.');
        }

        if (trim($title) === '') {
            throw new InvalidArgumentException('A card needs a title.');
        }

        $aircraft = $order->aircraft;

        // Same reason as open(): without the transaction the lock in the
        // number generator serializes nothing.
        return DB::transaction(fn (): TaskCard => TaskCard::create([
            'work_order_id' => $order->id,
            'number' => $this->nextCardNumber($order),
            'title' => trim($title),
            'instruction' => $instruction,

            /*
             * Nach welchem Stand gearbeitet wird -- als KOPIE, siehe Migration.
             * Ein Verweis wuerde mitwandern und die Karte rueckwirkend
             * behaupten lassen, nach dem neuen Stand gearbeitet worden zu sein.
             */
            'manual_reference' => $manualReference !== null ? trim($manualReference) : null,

            /*
             * Copied, not read through the relation. A logbook entry records
             * what somebody worked on that day, and that does not change
             * because the aircraft was sold or re-registered.
             */
            'aircraft_registration' => $aircraft?->registration ?? '?',
            'aircraft_model' => $aircraft?->model,

            'ata_chapter' => $ataChapter,
            'activity_kind' => $kind,

            /*
             * Kritische Arbeit -- verlangt spaeter eine unabhaengige Kontrolle,
             * bevor sie freigegeben werden kann. Gesetzt wird sie BEIM ANLEGEN,
             * also bevor jemand die Arbeit gemacht hat: Wer sie nachtraeglich
             * setzen oder wegnehmen koennte, koennte die Kontrolle nach Bedarf
             * an- und abschalten, und das waere genau die Hintertuer, gegen die
             * es die Regel gibt. Siehe InspectCriticalTask.
             */
            'critical' => $critical,
            'critical_reason' => $critical ? $criticalReason : null,

            'component_limit_id' => $forLimit?->id,
        ]));
    }

    /**
     * Somebody's hours on a card.
     *
     * Minutes, because everybody writes "1:45" and nobody writes 1.75.
     */
    public function recordTime(
        TaskCard $card,
        User $person,
        int $minutes,
        ParticipationKind $as,
        ?string $workedOn = null,
        ?string $note = null,
    ): TaskCardTime {
        if ($minutes <= 0) {
            throw new InvalidArgumentException('A working time is a positive number of minutes.');
        }

        if ($card->isCertified()) {
            throw new RuntimeException(
                'This card has been signed off. Hours added afterwards would change what '
                .'somebody put their name to.'
            );
        }

        return TaskCardTime::create([
            'task_card_id' => $card->id,
            'user_id' => $person->id,

            // Copied, so the logbook survives pseudonymisation (E3a).
            'person_name' => $person->name,

            'participation' => $as,
            'minutes' => $minutes,
            'worked_on' => $workedOn ?? now()->toDateString(),
            'note' => $note,
        ]);
    }

    /**
     * Tying a visit to the external order it commissioned.
     *
     * The record this completes: an annual whose engine went to a shop shows,
     * on the SAME page, which order that was and who released it -- instead of
     * the two records knowing nothing of each other while describing the same
     * event. The column had existed since the first migration; nothing wrote it.
     *
     * One refusal with teeth: the order has to belong to the visit's aircraft.
     * An overhaul commissioned for D-KXYZ says nothing about D-KABC's annual,
     * and linking it there would put a false trace into both records.
     */
    public function linkExternalOrder(
        WorkOrder $order,
        ExternalWorkOrder $external,
        User $user,
    ): WorkOrder {
        if ($order->state === WorkOrder::STATE_CANCELLED) {
            throw new RuntimeException('A cancelled visit records nothing further.');
        }

        if ((int) $external->aircraft_id !== (int) $order->aircraft_id) {
            throw new RuntimeException(sprintf(
                'That order belongs to %s, this visit to %s. Linking them would put a '
                .'false trace into both records.',
                $external->aircraft?->registration ?? '?',
                $order->aircraft?->registration ?? '?',
            ));
        }

        // The released-visit freeze in the model guards this write too -- a
        // frozen visit refuses the update on its own, and that is the intended
        // answer rather than a special case here.
        $order->update(['external_work_order_id' => $external->id]);

        return $order->fresh();
    }

    /**
     * Closing the visit.
     *
     * Refused while any card is merely completed. A card nobody has checked is
     * exactly what the second signature exists to surface, and closing a visit
     * over the top of one would bury it.
     */
    public function close(WorkOrder $order, User $user, ?string $closedAt = null): WorkOrder
    {
        if (! $order->isOpen()) {
            throw new RuntimeException('This visit is not open.');
        }

        $order->load('taskCards');

        if (! $order->allCardsClosed()) {
            $waiting = $order->taskCards
                ->filter(fn (TaskCard $c): bool => ! $c->state->isClosed())
                ->map(fn (TaskCard $c): string => $c->number)
                ->implode(', ');

            throw new RuntimeException(sprintf(
                'These cards are not signed off yet: %s. A visit closed over an unchecked '
                .'card hides the one thing the second signature is for.',
                $waiting,
            ));
        }

        return DB::transaction(function () use ($order, $closedAt): WorkOrder {
            $order->update([
                'state' => WorkOrder::STATE_CLOSED,
                'closed_at' => $closedAt ?? now()->toDateString(),
                'counters_at_close' => $order->aircraft?->currentValues() ?? [],
            ]);

            return $order->fresh();
        });
    }

    /**
     * Visit numbers run YYYY-NNN, restarting each year.
     */
    private function nextNumber(Carbon $when): string
    {
        $prefix = $when->format('Y');

        /*
         * Max of the parsed suffixes rather than "last row minus three chars":
         * lexicographic ordering puts 1000 before 999, and substr(-3) reads
         * 1000 as 000. Parsed from behind the prefix instead, and the unique
         * index remains the loud backstop for whatever slips through.
         */
        $next = WorkOrder::withTrashed()
            ->where('number', 'like', $prefix.'-%')
            ->lockForUpdate()
            ->pluck('number')
            ->map(fn (string $n): int => (int) substr($n, strlen($prefix) + 1))
            ->max();

        return sprintf('%s-%03d', $prefix, ($next ?? 0) + 1);
    }

    /**
     * Cards are numbered within their visit: 2026-014/03.
     *
     * Max-parsed, not counted. Counting breaks twice over: two concurrent adds
     * both count N and mint the same number, and a deleted card makes the count
     * disagree with the highest number ever handed out. The number is the join
     * key to the warehouse's parts trail and appears on the printed CRS -- a
     * duplicate here is two jobs becoming one identity.
     */
    private function nextCardNumber(WorkOrder $order): string
    {
        $next = TaskCard::withTrashed()
            ->where('work_order_id', $order->id)
            ->lockForUpdate()
            ->pluck('number')
            ->map(fn (string $n): int => (int) substr($n, strrpos($n, '/') + 1))
            ->max();

        return sprintf('%s/%02d', $order->number, ($next ?? 0) + 1);
    }
}
