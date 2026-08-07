<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Http;

use App\Core\Modules\ModuleManager;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StorageLocation;
use App\Modules\Warehouse\Permissions;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The counting list -- the sheet somebody actually walks the store with.
 *
 * Deliberately shows the recorded quantity next to an empty box rather than
 * hiding it. A blind count is more rigorous in theory, but in a club store it
 * mostly produces differences that turn out to be transcription errors, and
 * chasing those wastes the evening. Seeing the expected figure means the person
 * counting notices a real discrepancy and writes it down.
 *
 * Sorted by location and compartment, because that is the order one walks in.
 */
final class CountingListController
{
    public function __invoke(Request $request): View
    {
        abort_unless(app(ModuleManager::class)->isEnabled('warehouse'), 404);
        abort_unless($request->user()?->can(Permissions::STOCK_REPORT) ?? false, 403);

        $locationId = $request->query('location');

        $parts = PartType::query()
            ->with(['storageCompartment.location', 'lots' => fn ($q) => $q->whereNot('state', 'disposed')])
            ->when($locationId, fn ($q) => $q->whereHas(
                'storageCompartment',
                fn ($c) => $c->where('storage_location_id', $locationId),
            ))
            ->get()
            ->sortBy([
                fn (PartType $p) => $p->storageCompartment?->location?->name ?? 'zzz',
                fn (PartType $p) => $p->storageCompartment?->name ?? 'zzz',
                fn (PartType $p) => $p->name,
            ]);

        return view('warehouse.reports.counting-list', [
            'parts' => $parts,
            'location' => $locationId !== null ? StorageLocation::find($locationId) : null,
            'locations' => StorageLocation::orderBy('name')->get(),
            'club' => config('aeronance.organisation.name'),
        ]);
    }
}
