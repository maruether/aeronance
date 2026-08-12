<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Http;

use App\Core\Modules\ModuleManager;
use App\Modules\TaskCards\Models\ReleaseToService;
use App\Modules\TaskCards\Permissions;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The certificate as a sheet of paper, for the aircraft file.
 *
 * Two things the printed version says that the screen does not have to:
 *
 *  - A SUPERSEDED certificate prints with an unmissable banner naming its
 *    replacement. The paper in the folder outlives the person who filed it, and
 *    the one thing a stale printout must not do is look current.
 *  - A correction prints with what was wrong with the original, because the
 *    paper trail has to explain itself without access to the database.
 */
final class ReleaseController
{
    public function __invoke(Request $request, ReleaseToService $release): View
    {
        abort_unless(app(ModuleManager::class)->isEnabled('taskcards'), 404);

        /*
         * Werkstatt-Sicht ODER Flotten-Sicht: Seit die Bescheinigung als
         * Dokumentverweis in der Lebenslaufakte haengt, fuehrt auch die
         * Flotten-Seite hierher -- und wer die Akte lesen darf, darf die
         * Bescheinigung darin lesen. Der String statt der Fleet-Konstante
         * ist Absicht: kein Klassenname ueber die Modulgrenze.
         */
        abort_unless(
            ($request->user()?->canAny([Permissions::WORK_ORDERS_VIEW, 'fleet.view'])) ?? false,
            403,
        );

        return view('taskcards.print.release', [
            'release' => $release,
            'supersededBy' => ReleaseToService::query()
                ->where('supersedes_release_id', $release->id)
                ->first(),
            'workOrder' => $release->workOrder,
            'cards' => $release->workOrder?->taskCards()
                ->whereNot('state', 'cancelled')
                ->orderBy('number')
                ->get() ?? collect(),
        ]);
    }
}
