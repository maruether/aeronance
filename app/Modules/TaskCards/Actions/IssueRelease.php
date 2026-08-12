<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Actions;

use App\Core\Access\Authority;
use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\Fleet\Airworthiness\AirworthinessCheck;
use App\Modules\Fleet\Airworthiness\OpenItem;
use App\Modules\TaskCards\Events\ReleaseIssued;
use App\Modules\TaskCards\Models\Finding;
use App\Modules\TaskCards\Models\ReleaseToService;
use App\Modules\TaskCards\Models\TaskCard;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Permissions;
use App\Modules\TaskCards\Support\OwnWorkOnly;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Issuing the certificate of release to service.
 *
 * The third signature, and the only one an operator acts on: "fertig gemeldet"
 * says the work is finished, "abgezeichnet" says it was done properly, and this
 * says the aircraft may fly.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * FOUR REFUSALS, and each answers a different way of getting this wrong.
 *
 * 1. EVERY CARD SIGNED OFF. A card nobody has checked is exactly what the second
 *    signature exists to surface; releasing over one would bury it under a
 *    certificate that says the opposite.
 *
 * 2. NO BLOCKING FINDING OUTSTANDING. This is what deferral is FOR -- a finding
 *    somebody qualified decided can wait is not blocking, and one nobody has
 *    ruled on is. The distinction was built two commits ago and this is the place
 *    it earns its keep.
 *
 * 3. PILOT-OWNER ONLY FOR THEIR OWN WORK, at the level of the whole visit. The brief
 *    was emphatic and the regulation is: a CRS may cover work by others, a
 *    pilot-owner authorisation may not. Here that means EVERY card in the visit
 *    has to be their own -- a visit where a mechanic did one card is a visit a
 *    pilot-owner cannot release, even if they did the other nine.
 *
 * 4. NOT TWICE. A second release is a correction referencing the first, never a
 *    duplicate standing beside it.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class IssueRelease
{
    public function __construct(private Authority $authority) {}

    public function handle(
        WorkOrder $order,
        User $user,
        ?string $maintenanceData = null,
        ?string $statement = null,
        ?string $releasedAt = null,
    ): ReleaseToService {
        $order->load(['taskCards.times', 'aircraft']);

        if ($order->isReleased()) {
            throw new RuntimeException(
                'This visit has already been released. A correction is a new release '
                .'referencing the existing one.'
            );
        }

        if (! $order->isReadyForRelease()) {
            throw new RuntimeException($this->whyNotReady($order));
        }

        $blocking = $this->blockingFindings($order);

        if ($blocking !== []) {
            throw new RuntimeException(sprintf(
                'These findings are outstanding and not deferred: %s. A release cannot be '
                .'issued over an unresolved defect -- deferring one is a decision somebody '
                .'has to take and answer for.',
                implode(', ', $blocking),
            ));
        }

        /*
         * Anything that makes the signature itself unsound -- today that means an
         * unassessed LTA/TM line. Vorgabe: "nicht beurteilt ist ne red flag und
         * verhindert die freigabe."
         *
         * Asked through the fleet's airworthiness check rather than of the
         * directives module directly: this module requires fleet and knows
         * nothing of directives, and the check already collects from whoever is
         * installed. A club without the LTA module gets an empty list and notices
         * nothing.
         *
         * Note what is NOT gated here: the fleet's own items. An expired ARC
         * grounds an aircraft without making its maintenance unreleasable -- a
         * CRS certifies work, not flightworthiness.
         */
        if ($order->aircraft !== null) {
            $unsound = app(AirworthinessCheck::class)->releaseBlockersFor($order->aircraft);

            if ($unsound !== []) {
                throw new RuntimeException(sprintf(
                    'These have to be settled before a release can be signed: %s. Signing '
                    .'while a line of the list is unread is signing over an unknown.',
                    implode('; ', array_map(
                        fn (OpenItem $i): string => $i->what.' ('.$i->detail.')',
                        $unsound,
                    )),
                ));
            }
        }

        if (! $this->authority->permits($user, Permissions::CARDS_CERTIFY)) {
            throw new RuntimeException(sprintf(
                'Issuing a release requires the "%s" permission.',
                Permissions::CARDS_CERTIFY,
            ));
        }

        $qualification = $this->authority->qualificationFor(
            $user,
            Permissions::CARDS_CERTIFY,
            $order->aircraft?->registration,
        );

        if ($qualification === null) {
            throw new RuntimeException(
                'A release to service is reserved for qualified staff: a valid Part-66 '
                .'licence, or a pilot-owner authorisation for this aircraft, is required.'
            );
        }

        /*
         * The pilot-owner limit, applied to the whole visit.
         *
         * Card by card would not be enough: a release covers everything in the
         * visit, so one card done by somebody else puts the whole certificate
         * outside a pilot-owner's authorisation.
         */
        if ($qualification->type === Qualification::TYPE_PILOT_OWNER) {
            $foreign = $this->cardsWorkedOnByOthers($order, $user);

            if ($foreign !== []) {
                throw new RuntimeException(sprintf(
                    'A pilot-owner authorisation only covers work carried out personally, '
                    .'and a release covers the whole visit. These cards involve somebody '
                    .'else: %s. Somebody holding a Part-66 licence has to issue this.',
                    implode(', ', $foreign),
                ));
            }
        }

        $when = $releasedAt !== null ? Carbon::parse($releasedAt) : now();

        $fertig = DB::transaction(function () use ($order, $user, $qualification, $when, $maintenanceData, $statement): ReleaseToService {
            /*
             * The checks above ran on unlocked data, which is fine for telling a
             * person WHY -- but two inspectors clicking within the same second
             * both pass them. The lock plus re-check inside the transaction is
             * what actually enforces "not twice": the second transaction waits
             * here, then sees the first one's released_at.
             */
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->released_at !== null) {
                throw new RuntimeException(
                    'This visit has already been released. A correction is a new release '
                    .'referencing the existing one.'
                );
            }

            $release = ReleaseToService::create([
                'work_order_id' => $order->id,
                'aircraft_id' => $order->aircraft_id,

                // Copied: a certificate that starts reading differently once the
                // aircraft is re-registered is not a certificate.
                'aircraft_registration' => $order->aircraft?->registration ?? '?',
                'aircraft_model' => $order->aircraft?->model,

                'number' => $this->nextNumber($when),
                'statement' => $statement !== null && trim($statement) !== ''
                    ? trim($statement)
                    : $this->defaultStatement($order),
                'maintenance_data' => $maintenanceData,

                'released_at' => $when,
                'released_by' => $user->id,

                // Certificate content, frozen at the moment of the act (E7).
                'released_by_name' => $user->name,
                'qualification_type' => $qualification->type,
                'qualification_reference' => $qualification->reference,
                'qualification_category' => $qualification->category,
                'qualification_valid_until' => $qualification->valid_until,

                'counters_at_release' => $order->aircraft?->currentValues() ?? [],
            ]);

            /*
             * The freeze, in the same transaction as the certificate.
             *
             * Written to the work order directly rather than derived, so every
             * later save can check a column instead of running a query -- and so
             * the two can never disagree, because nothing exists between them.
             *
             * THE RELEASE ALSO CLOSES THE VISIT. Before this, releasing an open
             * visit deadlocked it: close() runs update(), the freeze refuses
             * every update, so the visit sat in "open" forever with a button
             * that always errored. The certificate IS the end of the visit --
             * there is nothing left to happen after the third signature, so the
             * closing figures are written here in the same breath.
             */
            $order->forceFill([
                'released_at' => $when,
                'state' => WorkOrder::STATE_CLOSED,
                'closed_at' => $order->closed_at ?? $when->toDateString(),
                'counters_at_close' => $order->counters_at_close ?? ($order->aircraft?->currentValues() ?? []),
            ])->saveQuietly();

            return $release->fresh();
        });

        $this->announce($fertig);

        return $fertig;
    }

    /**
     * Die Bescheinigung der Lebenslaufakte melden -- NACH der Transaktion.
     *
     * Skalare Nutzlast, Druck-URL hier gebaut (dieses Modul kennt seine
     * Route). Wer zuhoert, entscheidet die Flotte; ist sie stumm, ist das
     * kein Fehler dieses Moduls. Siehe ReleaseIssued.
     */
    private function announce(ReleaseToService $release): void
    {
        if ($release->aircraft_id === null) {
            return;
        }

        event(new ReleaseIssued(
            aircraftId: $release->aircraft_id,
            releaseNumber: (string) $release->number,
            releasedAt: $release->released_at->toDateString(),
            printUrl: route('taskcards.release', $release),
        ));
    }

    /**
     * A correction: a new release that says what was wrong with the old one.
     *
     * Never an edit. The superseded certificate keeps its text and its signature,
     * which is what "Korrekturen nur als neue, referenzierende Einträge" means in
     * practice -- somebody signed those words, and they stay signed.
     */
    public function correct(
        ReleaseToService $original,
        User $user,
        string $reason,
        ?string $statement = null,
    ): ReleaseToService {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'A correcting release has to say what was wrong with the one it replaces.'
            );
        }

        if ($original->isSuperseded()) {
            throw new RuntimeException('That release has already been corrected.');
        }

        $order = $original->workOrder()->withTrashed()->first();

        if ($order === null) {
            throw new RuntimeException('The visit behind this release is gone.');
        }

        if (! $this->authority->permits($user, Permissions::CARDS_CERTIFY)) {
            throw new RuntimeException(sprintf(
                'Correcting a release requires the "%s" permission.',
                Permissions::CARDS_CERTIFY,
            ));
        }

        $qualification = $this->authority->qualificationFor(
            $user,
            Permissions::CARDS_CERTIFY,
            $original->aircraft_registration,
        );

        if ($qualification === null) {
            throw new RuntimeException(
                'Correcting a release is itself a release and is reserved for qualified '
                .'staff.'
            );
        }

        /*
         * The pilot-owner limit applies here with full force. A correction is a
         * new certificate over the SAME work -- the review caught that this path
         * skipped the check, which would have let an owner "correct" a Part-66
         * holder's release of a mechanic's work and put their own name on it.
         */
        if ($qualification->type === Qualification::TYPE_PILOT_OWNER) {
            $order->load('taskCards.times');
            $foreign = $this->cardsWorkedOnByOthers($order, $user);

            if ($foreign !== []) {
                throw new RuntimeException(sprintf(
                    'A pilot-owner authorisation only covers work carried out personally, '
                    .'and a correcting release covers the whole visit. These cards involve '
                    .'somebody else: %s.',
                    implode(', ', $foreign),
                ));
            }
        }

        $fertig = DB::transaction(function () use ($original, $order, $user, $qualification, $reason, $statement): ReleaseToService {
            /*
             * Same shape as handle(): the check above was on unlocked data. The
             * lock makes the second of two concurrent corrections wait, then see
             * the first -- and the unique index on supersedes_release_id is the
             * backstop if anything slips past.
             */
            ReleaseToService::query()->lockForUpdate()->findOrFail($original->id);

            if ($original->fresh()->isSuperseded()) {
                throw new RuntimeException('That release has already been corrected.');
            }

            return ReleaseToService::create([
                'work_order_id' => $order->id,
                'aircraft_id' => $original->aircraft_id,
                'aircraft_registration' => $original->aircraft_registration,
                'aircraft_model' => $original->aircraft_model,
                'number' => $this->nextNumber(now()),
                'statement' => $statement !== null && trim($statement) !== ''
                ? trim($statement)
                : $original->statement,
                'maintenance_data' => $original->maintenance_data,
                'released_at' => now(),
                'released_by' => $user->id,
                'released_by_name' => $user->name,
                'qualification_type' => $qualification->type,
                'qualification_reference' => $qualification->reference,
                'qualification_category' => $qualification->category,
                'qualification_valid_until' => $qualification->valid_until,
                'counters_at_release' => $original->counters_at_release,
                'supersedes_release_id' => $original->id,
                'correction_reason' => trim($reason),
            ]);
        });

        $this->announce($fertig);

        return $fertig;
    }

    /**
     * Whether this person could release this visit at all.
     *
     * For the interface, so a button that cannot succeed is not offered -- and
     * so the reason can be shown instead.
     */
    public function refusalFor(WorkOrder $order, User $user): ?string
    {
        if ($order->isReleased()) {
            return __('taskcards.release.already');
        }

        if (! $order->isReadyForRelease()) {
            return $this->whyNotReady($order);
        }

        $blocking = $this->blockingFindings($order);

        if ($blocking !== []) {
            return __('taskcards.release.blocked_by_findings', ['list' => implode(', ', $blocking)]);
        }

        // Same question as handle() asks, so the button is not offered when it
        // would only refuse.
        if ($order->aircraft !== null) {
            $unsound = app(AirworthinessCheck::class)->releaseBlockersFor($order->aircraft);

            if ($unsound !== []) {
                return __('taskcards.release.blocked_by_airworthiness', [
                    'list' => implode('; ', array_map(fn (OpenItem $i): string => $i->what, $unsound)),
                ]);
            }
        }

        return null;
    }

    private function whyNotReady(WorkOrder $order): string
    {
        if ($order->taskCards()->doesntExist()) {
            return 'A visit with no cards has nothing to release -- what would the '
                .'certificate be about?';
        }

        $waiting = $order->taskCards
            ->filter(fn (TaskCard $c): bool => ! $c->state->isClosed())
            ->map(fn (TaskCard $c): string => $c->number)
            ->implode(', ');

        return sprintf(
            'These cards are not signed off yet: %s. A release over an unchecked card '
            .'would certify the one thing nobody has checked.',
            $waiting,
        );
    }

    /**
     * Findings that stand in the way.
     *
     * Deferred ones do not -- that is exactly what deferring is for, and it is a
     * decision somebody qualified took and answers for. One nobody has ruled on
     * does.
     *
     * @return list<string>
     */
    private function blockingFindings(WorkOrder $order): array
    {
        return Finding::query()
            ->where('aircraft_id', $order->aircraft_id)
            ->where('is_blocking', true)
            ->where(function ($q): void {
                $q->where('state', 'open')

                    /*
                     * Scheduled blocks too. Raising a card for a finding needs no
                     * qualification -- reading "scheduled" as "out of the way"
                     * would let anyone clear the release gate by clicking
                     * "einplanen". The finding leaves the gate when its card is
                     * CERTIFIED (which resolves it), or when somebody qualified
                     * defers it -- both acts a person answers for.
                     */
                    ->orWhere('state', 'scheduled')

                    /*
                     * A deferral that has run out has run out. The airworthiness
                     * check already treated a lapsed deferral as blocking; the
                     * release gate saying otherwise was the two halves of one
                     * module contradicting each other.
                     */
                    ->orWhere(function ($q): void {
                        $q->where('state', 'deferred')
                            ->whereNotNull('deferred_until')
                            ->where('deferred_until', '<', now()->toDateString());
                    });
            })
            ->get()
            ->map(fn (Finding $f): string => $f->number)
            ->all();
    }

    /**
     * Cards in this visit that somebody other than this person worked on.
     *
     * @return list<string>
     */
    private function cardsWorkedOnByOthers(WorkOrder $order, User $user): array
    {
        return $order->taskCards
            ->reject(fn (TaskCard $card): bool => $card->state->value === 'cancelled')
            ->reject(fn (TaskCard $card): bool => OwnWorkOnly::isEntirelyOwnWork($card, $user))
            ->map(fn (TaskCard $card): string => $card->number)
            ->values()
            ->all();
    }

    /**
     * The words above the signature, where nobody typed their own.
     *
     * Assembled once and stored, not rendered at display time: a signature
     * belongs to the text that was above it, not to whatever a later template
     * produces.
     */
    private function defaultStatement(WorkOrder $order): string
    {
        return __('taskcards.release.statement', [
            'registration' => $order->aircraft?->registration ?? '?',
            'title' => $order->title,
            'number' => $order->number,
            'cards' => $order->taskCards
                ->reject(fn (TaskCard $c): bool => $c->state->value === 'cancelled')
                ->count(),
        ]);
    }

    /** Release numbers run CRS-YYYY-NNN. */
    private function nextNumber(Carbon $when): string
    {
        $prefix = 'CRS-'.$when->format('Y');

        $last = ReleaseToService::query()
            ->where('number', 'like', $prefix.'-%')
            ->lockForUpdate()
            ->orderByDesc('number')
            ->value('number');

        // Parsed from behind the prefix rather than the last three characters:
        // number 1000 read through substr(-3) becomes 000, and the sequence
        // collapses. Unlikely at club scale, cheap to be correct about.
        $next = $last === null ? 1 : ((int) substr((string) $last, strlen($prefix) + 1)) + 1;

        return sprintf('%s-%03d', $prefix, $next);
    }
}
