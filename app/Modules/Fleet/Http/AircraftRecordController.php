<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Http;

use App\Core\Modules\ModuleManager;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\Weighing;
use App\Modules\Fleet\Permissions;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The two sheets a club actually hands over.
 *
 * The BWLV keeps them apart -- Ausrüstungsverzeichnis and
 * Betriebszeitenübersicht -- because paper cannot show one set of rows two ways.
 * Here they are two views of the same table, which is the whole reason for
 * folding the equipment list into the installations rather than keeping a second
 * list beside it.
 *
 * Printed as HTML with millimetre geometry, the same choice as the quarantine
 * tags and for the same reasons: the usual PDF library's first two major
 * versions carry a long list of advisories and the third does not run on
 * PHP 8.5. The browser prints, with a preview and no server dependency.
 */
final class AircraftRecordController
{
    /**
     * Ausrüstungsverzeichnis -- what is fitted, and where.
     *
     * Carries the lever arm, because this sheet is part of the weight and
     * balance record: the BWLV column reads "Einbauort, oder Hebelarm in mm vom
     * Bezugspunkt (+/- Vorzeichen beachten)".
     */
    public function equipment(Request $request, Aircraft $aircraft): View
    {
        $this->authorise($request);

        return view('fleet.print.equipment-list', [
            'aircraft' => $aircraft->load('holder'),
            'rows' => $aircraft->installations()
                ->whereNull('removed_at')
                ->orderByDesc('is_minimum_equipment')
                ->orderBy('part_name')
                ->get(),
        ]);
    }

    /**
     * Betriebszeitenübersicht -- the same things, with their times against them.
     *
     * Removed components are included rather than filtered out: the sheet is a
     * history, and the "beim Ausbau" columns exist precisely so that what came
     * off can still be read years later.
     */
    public function operatingTimes(Request $request, Aircraft $aircraft): View
    {
        $this->authorise($request);

        return view('fleet.print.operating-times', [
            'aircraft' => $aircraft->load('holder'),
            'rows' => $aircraft->installations()
                ->with('limits')
                ->orderByRaw('removed_at IS NOT NULL')
                ->orderBy('part_name')
                ->get(),
        ]);
    }

    /**
     * Massenübersicht -- the weighing report as the BWLV prints it.
     *
     * Two layouts from one record, because the glider sheet weighs component by
     * component while the powered one weighs on supports and deducts what can be
     * flown off. Same document, different arithmetic, different columns.
     */
    public function weighing(Request $request, Weighing $weighing): View
    {
        $this->authorise($request);

        $weighing->load(['aircraft', 'entries']);

        return view('fleet.print.weighing', [
            'weighing' => $weighing,
            'aircraft' => $weighing->aircraft,
            'result' => $weighing->result(),
        ]);
    }

    private function authorise(Request $request): void
    {
        abort_unless(app(ModuleManager::class)->isEnabled('fleet'), 404);
        abort_unless($request->user()?->can(Permissions::FLEET_VIEW) ?? false, 403);
    }
}
