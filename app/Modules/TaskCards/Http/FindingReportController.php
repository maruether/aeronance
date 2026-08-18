<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Http;

use App\Core\Modules\ModuleManager;
use App\Modules\TaskCards\Actions\ManageFindingReport;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Permissions;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Der Befundbericht eines Vorgangs als Blatt Papier.
 *
 * Er wird gedruckt, weil er unterschrieben wird: „Bericht erstellt" und
 * „Abschließend geprüft" stehen unter dem Blatt, und die eine Unterschrift, die
 * das System schon kennt -- die Freigabe --, ist eingedruckt statt ein zweites
 * Mal einzuholen.
 */
final class FindingReportController
{
    public function __invoke(Request $request, WorkOrder $workOrder): View
    {
        abort_unless(app(ModuleManager::class)->isEnabled('taskcards'), 404);

        /*
         * Dieselbe Prüfung wie an der Freigabebescheinigung: Werkstatt-Sicht
         * ODER Flotten-Sicht. Wer die Akte eines Luftfahrzeugs lesen darf, darf
         * den Befundbericht darin lesen. Der String statt der Fleet-Konstante
         * ist Absicht -- kein Klassenname über die Modulgrenze.
         */
        abort_unless(
            ($request->user()?->canAny([Permissions::WORK_ORDERS_VIEW, 'fleet.view'])) ?? false,
            403,
        );

        return view('taskcards.print.finding-report', [
            'order' => $workOrder->load(['aircraft', 'taskCards']),
            'points' => app(ManageFindingReport::class)->points($workOrder),
            'release' => $workOrder->currentRelease(),
        ]);
    }
}
