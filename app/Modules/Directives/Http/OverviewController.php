<?php

declare(strict_types=1);

namespace App\Modules\Directives\Http;

use App\Core\Modules\ModuleManager;
use App\Modules\Directives\Enums\ComplianceState;
use App\Modules\Directives\Models\Directive;
use App\Modules\Directives\Models\DirectiveApplication;
use App\Modules\Directives\Permissions;
use App\Modules\Fleet\Models\Aircraft;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The LTA/TM overview as a sheet of paper, for the aircraft file.
 *
 * What the printed version adds over the screen: it states the tally at the top
 * -- how many lines, how many unread -- and it signs. An overview handed to an
 * inspector without a date and a name on it is a printout; with them it is a
 * statement that somebody worked down the list.
 */
final class OverviewController
{
    public function __invoke(Request $request, Aircraft $aircraft): View
    {
        abort_unless(app(ModuleManager::class)->isEnabled('directives'), 404);

        abort_unless($request->user()?->can(Permissions::DIRECTIVES_VIEW) ?? false, 403);

        $applications = DirectiveApplication::query()
            ->where('aircraft_id', $aircraft->id)
            ->with('directive')
            ->get()
            ->keyBy('directive_id');

        $lines = Directive::query()
            ->current()
            ->orderBy('kind')
            ->orderBy('number')
            ->get()
            ->filter(fn (Directive $d): bool => $applications->has($d->id)
                || $d->mayApplyTo($aircraft))
            ->map(fn (Directive $d): array => [
                'directive' => $d,
                'application' => $applications->get($d->id),
            ])
            ->values();

        return view('directives.print.overview', [
            'aircraft' => $aircraft,
            'lines' => $lines,
            /*
             * The sheet has to carry this, and it is the reason this line is not
             * left to the screen.
             *
             * This is the page an inspector holds at the annual. A short list on
             * paper reads as "little was published"; for a type nobody looks
             * after any more, it means "nobody publishes anything" -- and the
             * club researched, or did not. Printed without that sentence, the
             * sheet asserts something it cannot back up.
             */
            'aircraftType' => $aircraft->aircraftType,
            'unassessed' => $lines->filter(fn (array $l): bool => $l['application'] === null
                || $l['application']->state === ComplianceState::Open)->count(),
            'outstanding' => $lines->filter(fn (array $l): bool => $l['application'] === null
                || $l['application']->isOutstanding())->count(),
        ]);
    }
}
