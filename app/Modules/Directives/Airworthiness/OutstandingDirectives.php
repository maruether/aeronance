<?php

declare(strict_types=1);

namespace App\Modules\Directives\Airworthiness;

use App\Core\Modules\ModuleManager;
use App\Modules\Directives\Enums\ComplianceState;
use App\Modules\Directives\Models\Directive;
use App\Modules\Directives\Models\DirectiveApplication;
use App\Modules\Fleet\Airworthiness\ContributesOpenItems;
use App\Modules\Fleet\Airworthiness\OpenItem;
use App\Modules\Fleet\Models\Aircraft;

/**
 * What the directive list still wants, for the fleet's "hier ist noch was offen".
 *
 * Contributed through the fleet's extension point rather than by reaching into it
 * -- the second user of that interface after the task cards' findings, which is
 * the first real evidence it was the right seam.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * FOUR DIFFERENT THINGS SHOW UP HERE, and they are worth distinguishing because
 * they call for different actions:
 *
 *  - a line nobody has read yet (open)
 *  - a line somebody read and did not carry out (blocking if mandatory)
 *  - a complied recurring line whose interval has come round
 *  - a directive that touches this aircraft and has no assessment row AT ALL
 *
 * The last one is the one that would otherwise be invisible: importing a
 * manufacturer list does not create assessment rows, so a new LTA lands in the
 * database and nothing on the aircraft's page mentions it. Working it out here
 * rather than materialising rows at import time keeps the two tables honest --
 * applicability is a judgement that can change when a component is fitted, and a
 * row written at import would freeze yesterday's answer.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class OutstandingDirectives implements ContributesOpenItems
{
    /** @return list<OpenItem> */
    public function openItemsFor(Aircraft $aircraft): array
    {
        /*
         * Der Provider registriert diesen Beitrag bedingungslos und verlaesst
         * sich darauf, dass HIER nachgefragt wird. Ohne diese Zeile meldete
         * ein deaktiviertes Directives-Modul weiter offene Punkte -- und
         * blockierte im schlimmsten Fall eine Freigabe mit einer Liste, die
         * der Verein bewusst abgeschaltet hat.
         */
        if (! app(ModuleManager::class)->isEnabled('directives')) {
            return [];
        }

        $items = [];

        $applications = DirectiveApplication::query()
            ->where('aircraft_id', $aircraft->id)
            ->with(['directive', 'aircraft'])
            ->get();

        foreach ($applications as $application) {
            $directive = $application->directive;

            if ($directive === null || $directive->isSuperseded()) {
                continue;
            }

            if (! $application->isOutstanding()) {
                continue;
            }

            $items[] = new OpenItem(
                source: 'directives',
                what: $directive->label().' — '.$directive->title,
                detail: $this->describe($application),
                blocking: $application->isBlocking(),
                blocksRelease: $application->blocksRelease(),
            );
        }

        // Directives that touch this aircraft and have not been assessed at all.
        $assessedIds = $applications->pluck('directive_id')->all();

        $unassessed = Directive::query()
            ->current()

            ->when($assessedIds !== [], fn ($q) => $q->whereNotIn('id', $assessedIds))
            ->get()
            ->filter(fn (Directive $d): bool => $d->mayApplyTo($aircraft));

        foreach ($unassessed as $directive) {
            $items[] = new OpenItem(
                source: 'directives',
                what: $directive->label().' — '.$directive->title,
                detail: __('directives.open.never_assessed'),

                /*
                 * Blocking regardless of bindingness, and blocking the release
                 * too. Vorgabe: "nicht beurteilt ist ne red flag und verhindert
                 * die freigabe." Nobody has read the line, so nobody can say
                 * whether it is the harmless one -- the uncertainty is the
                 * problem, not the directive.
                 */
                blocking: true,
                blocksRelease: true,
            );
        }

        return $items;
    }

    private function describe(DirectiveApplication $application): string
    {
        return match (true) {
            $application->state === ComplianceState::Open => __('directives.open.unassessed'),
            $application->state === ComplianceState::NotCarriedOut => __('directives.open.not_carried_out', [
                'reason' => (string) $application->reason,
            ]),
            default => __('directives.open.recurrence_due', [
                'due' => $application->next_due_at?->format('d.m.Y') ?? '—',
            ]),
        };
    }
}
