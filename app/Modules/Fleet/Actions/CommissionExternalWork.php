<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Actions;

use App\Core\Access\Authority;
use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\Fleet\Enums\ExternalWorkState;
use App\Modules\Fleet\Enums\InstallationOrigin;
use App\Modules\Fleet\Enums\ReleasedBy;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\ExternalWorkOrder;
use App\Modules\Fleet\Models\Installation;
use App\Modules\Fleet\Permissions;
use App\Modules\Fleet\Support\ApprovedOrganisations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Giving work to another organisation, and taking it back.
 *
 * Three acts, deliberately separate, because they are done at different times by
 * potentially different people:
 *
 *   commission -> the aircraft goes away
 *   receive    -> it comes back, with a report and possibly new parts in it
 *   release    -> somebody says it may fly
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE RELEASE IS THE PART THAT MATTERS, and the brief leaves it open on purpose:
 * "Es ist dabei offen ob ich selbst freigebe oder die fremdwerft."
 *
 * If the shop signs, we record their signature and their approval number, and
 * that is bookkeeping -- the authority is theirs.
 *
 * If WE sign, somebody here is accepting work they did not watch, on the
 * strength of a report. That is a determination: it needs a qualification, it is
 * frozen with the credential it was made under, and it is the first thing an
 * auditor will ask about. The two cases must never look alike afterwards.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class CommissionExternalWork
{
    public function __construct(private Authority $authority) {}

    public function commission(
        Aircraft $aircraft,
        string $shopName,
        string $scope,
        User $user,
        ?string $shopApproval = null,
        ?int $organisationId = null,
        ?string $orderReference = null,
        ?string $sentAt = null,
        ?string $expectedBackAt = null,
    ): ExternalWorkOrder {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * EIN BETRIEB AUS DEM VERZEICHNIS SCHLAEGT DEN FREITEXT -- dieselbe
         * Regel wie beim Teileversand zur Instandsetzung.
         *
         * Name und Zulassungsnummer werden KOPIERT, nicht verwiesen: Wohin ein
         * Luftfahrzeug ging und unter welcher Nummer, muss lesbar bleiben, auch
         * wenn der Betrieb spaeter umbenannt wird oder seine Zulassung
         * wechselt (E7).
         *
         * Ueber ApprovedOrganisations, damit die Flotte das Lager nur
         * anfasst, wenn es ueberhaupt da ist -- sie steht allein.
         * ─────────────────────────────────────────────────────────────────────
         */
        $betrieb = ApprovedOrganisations::find($organisationId);

        if ($betrieb !== null) {
            /*
             * ABGELAUFENE ZULASSUNG WIRD ABGELEHNT. Was von dort zurueckkommt,
             * traegt eine Bescheinigung, die nichts wert ist -- und bei einem
             * ganzen Luftfahrzeug wiegt das schwerer als bei einem Bauteil.
             */
            if ($betrieb['lapsed']) {
                throw new RuntimeException(__('fleet.external.refused.approval_lapsed', [
                    'shop' => $betrieb['name'],
                    'date' => $betrieb['expires_at']?->format('d.m.Y') ?? '—',
                ]));
            }

            $shopName = $betrieb['name'];
            $shopApproval ??= $betrieb['approval'];
        }

        if (trim($shopName) === '') {
            throw new InvalidArgumentException('The organisation doing the work has to be named.');
        }

        if (trim($scope) === '') {
            throw new InvalidArgumentException(
                'What was commissioned has to be recorded -- "external work" on its own '
                .'answers nothing later.'
            );
        }

        return ExternalWorkOrder::create([
            'aircraft_id' => $aircraft->id,
            'shop_name' => trim($shopName),
            'shop_approval' => $shopApproval !== null ? trim($shopApproval) : null,
            'order_reference' => $orderReference,
            'scope' => trim($scope),
            'sent_at' => $sentAt ?? now()->toDateString(),
            'expected_back_at' => $expectedBackAt,
            'sent_by' => $user->id,
            'state' => ExternalWorkState::Commissioned,
        ]);
    }

    /**
     * The aircraft is back, with a report.
     *
     * Deliberately does NOT release it. The aircraft is in the hangar and looks
     * finished, and that is exactly the moment somebody flies it on the strength
     * of "it's back, isn't it" -- so coming back and being allowed to fly are
     * two entries, and the gap between them is visible.
     */
    public function receive(
        ExternalWorkOrder $order,
        User $user,
        ?string $reportReference = null,
        ?string $returnedAt = null,
    ): ExternalWorkOrder {
        if ($order->state !== ExternalWorkState::Commissioned) {
            throw new RuntimeException(sprintf('This order is already %s.', $order->state->label()));
        }

        $order->update([
            'state' => ExternalWorkState::Returned,
            'returned_at' => $returnedAt ?? now()->toDateString(),
            'report_reference' => $reportReference,
        ]);

        return $order->fresh();
    }

    /**
     * Recording a part the shop fitted.
     *
     * It never touched our store and we did not watch it arrive, so it carries
     * its own provenance -- and, like an onboarding line, has to name what it
     * was taken from. Here that is the shop's report, which is the only thing
     * standing behind the entry.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordFittedPart(
        ExternalWorkOrder $order,
        string $partName,
        User $user,
        array $attributes = [],
        ?string $installedAt = null,
    ): Installation {
        if (trim($partName) === '') {
            throw new InvalidArgumentException('A part needs a name to appear in a life record.');
        }

        if ($order->state === ExternalWorkState::Cancelled) {
            throw new RuntimeException('Nothing was fitted during a cancelled order.');
        }

        $aircraft = $order->aircraft;

        return Installation::create(array_merge($attributes, [
            'aircraft_id' => $aircraft->id,
            'external_work_order_id' => $order->id,
            'origin' => InstallationOrigin::External,
            'part_name' => trim($partName),

            /*
             * The shop's report is what stands behind this line, exactly as an
             * onboarding entry names the document it was copied from. Recorded
             * in the same field so both kinds answer "how do you know" the same
             * way -- there is no reason for a reader to learn two conventions.
             */
            'transcribed_from' => $order->report_reference !== null
                ? __('fleet.external.report_of', [
                    'shop' => $order->shop_name,
                    'reference' => $order->report_reference,
                ])
                : __('fleet.external.work_of', ['shop' => $order->shop_name]),
            'transcribed_at' => now()->toDateString(),
            'transcribed_by' => $user->id,
            'transcribed_by_name' => $user->name,

            'installed_at' => $installedAt ?? $order->returned_at?->toDateString() ?? now()->toDateString(),
            'counters_at_installation' => $aircraft->currentValues(),
        ]));
    }

    /**
     * Signing the work off -- theirs or ours.
     */
    public function release(
        ExternalWorkOrder $order,
        ReleasedBy $by,
        User $user,
        ?string $releaseReference = null,
        ?string $externalSignatory = null,
        ?string $externalApproval = null,
        ?string $releasedAt = null,
    ): ExternalWorkOrder {
        if ($order->isReleased()) {
            throw new RuntimeException('This order has already been released.');
        }

        if ($order->state === ExternalWorkState::Cancelled) {
            throw new RuntimeException('A cancelled order is not released.');
        }

        $when = $releasedAt !== null ? Carbon::parse($releasedAt) : now();

        $fields = [
            'state' => ExternalWorkState::Released,
            'released_by' => $by,
            'released_at' => $when->toDateString(),
            'release_reference' => $releaseReference,
        ];

        if ($by === ReleasedBy::External) {
            // Their signature, their authority. We write down what the paper
            // says and claim nothing ourselves.
            if (trim((string) $externalSignatory) === '') {
                throw new InvalidArgumentException(
                    'An external release has to name who signed it -- a certificate with '
                    .'no signatory is not one.'
                );
            }

            $fields['released_by_name'] = trim($externalSignatory);
            $fields['released_by_approval'] = $externalApproval !== null
                ? trim($externalApproval)
                : $order->shop_approval;
        } else {
            /*
             * Ours. Accepting work we did not watch is a determination, so it
             * needs the qualification and freezes the credential -- the same
             * two-stage check as every other act somebody answers for (E8).
             */
            if (! $this->authority->permits($user, Permissions::EXTERNAL_WORK_ACCEPT)) {
                throw new RuntimeException(sprintf(
                    'Releasing external work yourself requires the "%s" permission.',
                    Permissions::EXTERNAL_WORK_ACCEPT,
                ));
            }

            $qualification = $this->authority->qualificationFor(
                $user,
                Permissions::EXTERNAL_WORK_ACCEPT,
                $order->aircraft?->registration,
            );

            if ($qualification === null) {
                throw new RuntimeException(
                    'Accepting work performed by somebody else is a determination and is '
                    .'reserved for qualified staff: a valid Part-66 licence is required.'
                );
            }

            /*
             * A pilot-owner may NEVER release this, and the reason is in the
             * definition rather than in a threshold.
             *
             * Vorgabe: "crs darf fremdarbeiten freigeben. PO explizit nur das was
             * er selbst gemacht hat." External work is, by construction, work
             * somebody else performed -- so there is no version of this act that
             * falls inside a pilot-owner authorisation.
             *
             * My first version accepted either qualification here, which was
             * wrong in the one direction that matters: it let an owner sign off
             * a shop's work on their own aircraft.
             */
            if ($qualification->type === Qualification::TYPE_PILOT_OWNER) {
                throw new RuntimeException(
                    'A pilot-owner authorisation covers only maintenance carried out '
                    .'personally. Work performed by another organisation is outside it -- '
                    .'either the shop signs its own release, or somebody holding a Part-66 '
                    .'licence accepts it.'
                );
            }

            $fields['released_by_user'] = $user->id;
            $fields['released_by_name'] = $user->name;
            $fields['qualification_type'] = $qualification->type;
            $fields['qualification_reference'] = $qualification->reference;
            $fields['qualification_category'] = $qualification->category;
        }

        return DB::transaction(function () use ($order, $fields): ExternalWorkOrder {
            $order->update($fields);

            return $order->fresh();
        });
    }
}
