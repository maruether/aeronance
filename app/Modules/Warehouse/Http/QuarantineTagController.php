<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Http;

use App\Core\Modules\ModuleManager;
use App\Modules\Warehouse\Models\LotStateChange;
use App\Modules\Warehouse\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The printable quarantine tags.
 *
 * Rendered as HTML with millimetre geometry rather than as a PDF. That was not
 * the first choice: the usual PDF library is blocked here on two counts -- its
 * first two major versions carry a long list of security advisories, and the
 * third does not yet run on PHP 8.5. Neither is a reason to disable the audit,
 * so the browser does the printing.
 *
 * It turns out to suit this job. The user sees the sheet before it is printed,
 * nothing has to be installed on the server, and the one real risk -- a printer
 * quietly scaling the page -- is answered by the calibration sheet, which
 * carries a box that must measure exactly 90 by 50 millimetres.
 *
 * Three layouts:
 *
 *   sheet       ten tags on a die-cut A4 sheet, with the option to skip
 *               positions already used -- otherwise printing one tag wastes
 *               nine, which is the practical objection to sheet formats
 *   labels      the same content on ordinary laser labels, to be stuck onto
 *               ready-made coloured tags with a metal eyelet and wire. Sturdier
 *               than thread in an oily workshop, and the colour is in the card
 *               rather than in a layer of toner
 *   single      one tag, for plain card cut by hand
 *   calibration a measuring sheet, printed once per printer
 */
final class QuarantineTagController
{
    public function sheet(Request $request): View
    {
        $this->authorise($request);

        $tags = $this->requestedTags($request);

        // Positions already used on the sheet being fed back in. One-based, as
        // they are counted on the paper.
        $skip = collect(explode(',', (string) $request->query('skip', '')))
            ->map(fn (string $value): int => (int) trim($value))
            ->filter(fn (int $value): bool => $value > 0)
            ->all();

        $this->markPrinted($tags);

        // "labels" druckt auf gewoehnliche Laseretiketten zum Aufkleben auf
        // vorgefertigte farbige Anhaenger -- siehe die Erlaeuterung in der
        // Konfiguration.
        $variant = $request->query('layout') === 'labels' ? 'label' : 'sheet';

        return view('warehouse.tags.sheet', [
            'tags' => $tags,
            'skip' => $skip,
            'layout' => config('aeronance.quarantine_tag.'.$variant),
            'template' => config('aeronance.quarantine_tag.template'),
            'variant' => $variant,
        ]);
    }

    public function single(Request $request, LotStateChange $change): View
    {
        $this->authorise($request);

        abort_unless($change->needsTag(), 404);

        $this->markPrinted(collect([$change]));

        return view('warehouse.tags.single', [
            'tag' => $change,
            'layout' => config('aeronance.quarantine_tag.sheet'),
        ]);
    }

    /**
     * A sheet for checking that the printer is not scaling the page.
     *
     * Printed once per printer. Without it, a misaligned run wastes a whole
     * sheet of card at a time and the cause is not obvious.
     */
    public function calibration(Request $request): View
    {
        $this->authorise($request);

        return view('warehouse.tags.calibration', [
            'layout' => config('aeronance.quarantine_tag.sheet'),
            'template' => config('aeronance.quarantine_tag.template'),
        ]);
    }

    private function authorise(Request $request): void
    {
        abort_unless(app(ModuleManager::class)->isEnabled('warehouse'), 404);

        abort_unless(
            $request->user()?->canAny([
                Permissions::STOCK_QUARANTINE,
                Permissions::STOCK_QUARANTINE_CERTIFY,
                Permissions::STOCK_SCRAP,
            ]) ?? false,
            403,
        );
    }

    /**
     * @return Collection<int, LotStateChange>
     */
    private function requestedTags(Request $request): Collection
    {
        $ids = collect(explode(',', (string) $request->query('tags', '')))
            ->map(fn (string $value): int => (int) trim($value))
            ->filter()
            ->all();

        $query = LotStateChange::query()
            ->with(['lot.partType'])
            ->whereNotNull('quarantine_tag');

        // Without an explicit list: everything not yet printed. That is the
        // usual case -- somebody blocked a few parts and now goes to the printer.
        $ids === []
            ? $query->whereNull('tag_printed_at')
            : $query->whereIn('id', $ids);

        return $query->orderBy('quarantine_tag')->get();
    }

    /**
     * @param  Collection<int, LotStateChange>  $tags
     */
    private function markPrinted(Collection $tags): void
    {
        foreach ($tags as $tag) {
            if ($tag->tag_printed_at === null) {
                // Written straight to the table: the model refuses ordinary
                // updates because a determination is frozen, and whether its
                // slip has been through a printer is not part of the
                // determination.
                LotStateChange::query()
                    ->whereKey($tag->getKey())
                    ->update(['tag_printed_at' => now()]);
            }
        }
    }
}
