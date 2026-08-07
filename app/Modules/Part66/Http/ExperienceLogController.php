<?php

declare(strict_types=1);

namespace App\Modules\Part66\Http;

use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Part66\Permissions;
use App\Modules\Part66\Support\ExperienceLog;
use App\Modules\Part66\Support\RecencyReport;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The experience log as a sheet of paper.
 *
 * The actual deliverable of the original request: something to hand to an
 * authority or to file. Printed rather than exported to a spreadsheet, because
 * what a licence application wants is a document with a person's name on it.
 *
 * The authorisation is the interesting part -- see below.
 */
final class ExperienceLogController
{
    public function __invoke(Request $request): View
    {
        abort_unless(app(ModuleManager::class)->isEnabled('part66'), 404);

        $viewer = $request->user();

        abort_if($viewer === null, 403);

        /*
         * Somebody else's log needs the permission; your own never does.
         *
         * Written as an explicit comparison rather than a policy so the rule is
         * readable in one line: the only way to reach another person's log is to
         * hold the permission, and the default is yourself.
         */
        $personId = (int) $request->query('person', (string) $viewer->id);

        if ($personId !== (int) $viewer->id) {
            abort_unless($viewer->can(Permissions::LOGS_VIEW_ALL), 403);
        }

        $person = User::findOrFail($personId);

        $from = $request->query('from');
        $to = $request->query('to');

        $log = app(ExperienceLog::class);
        $entries = $log->for($person, $from, $to);
        $recencyService = app(RecencyReport::class);
        $recency = $recencyService->for($person);

        return view('part66.print.log', [
            'person' => $person,
            'entries' => $entries,
            'span' => $log->span($entries),
            'hours' => round($entries->sum(fn ($e): int => $e->minutes) / 60, 2),
            'byActivity' => $log->hoursByActivity($entries),
            'byModel' => $log->hoursByModel($entries),
            'byParticipation' => $log->hoursByParticipation($entries),
            'certifications' => $log->certificationCountBy($person, $from, $to),
            'releases' => $log->releasesBy($person, $from, $to)->count(),

            // Listed rather than just counted: an overview of ARCs is more use
            // with the registrations and dates on it than as a bare number.
            'reviews' => $log->reviewsBy($person, $from, $to),
            'recency' => $recency,
            'notes' => $recencyService->observations($recency),
            'qualifications' => $person->validQualifications()->get(),
        ]);
    }
}
