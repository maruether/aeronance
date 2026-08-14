<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Pages;

use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * What needs attention.
 *
 * Separate from the inventory report on purpose: shortfalls and expiry dates are
 * everyday questions, not annual ones. The report answers "what did we have on
 * the 31st"; this screen answers "what should somebody deal with today".
 *
 * Four lists, in the order they matter. Expired stock first, because it is
 * sitting on the shelf looking usable and is not.
 */
final class StockAttention extends Page
{
    protected string $view = 'warehouse.filament.pages.attention';

    protected static ?string $slug = 'was-liegt-an';

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.warehouse');
    }

    public static function getNavigationLabel(): string
    {
        return __('warehouse.attention.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('warehouse.attention.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('warehouse.attention.subheading');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedBell;
    }

    /**
     * A count in the navigation, so the screen is worth opening only when it is.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = self::expiredLots()->count()
            + self::belowMinimum()->count()
            + self::blockedLots()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return self::expiredLots()->count() > 0 ? 'danger' : 'warning';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permissions::STOCK_VIEW) ?? false;
    }

    /** Already past their date and still on the shelf. */
    public static function expiredLots()
    {
        return StockLot::query()
            ->with('partType')
            ->where('state', 'serviceable')
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', now()->toDateString())
            ->whereHas('movements')
            ->orderBy('expires_at')
            ->get()
            ->filter(fn (StockLot $lot): bool => $lot->remainingQuantity() > 0);
    }

    /** Running out within the next three months. */
    public static function expiringLots()
    {
        return StockLot::query()
            ->with('partType')
            ->where('state', 'serviceable')
            ->expiringWithin(90)
            ->orderBy('expires_at')
            ->get()
            ->filter(fn (StockLot $lot): bool => $lot->remainingQuantity() > 0);
    }

    /** What needs ordering. */
    public static function belowMinimum()
    {
        return PartType::query()
            ->belowMinimum()
            ->with('supplier')
            ->orderBy('name')
            ->get();
    }

    /** Anything set aside -- and how long it has been sitting there. */
    public static function blockedLots()
    {
        return StockLot::query()
            ->with(['partType', 'stateChanges'])
            ->whereIn('state', ['quarantined', 'unserviceable', 'unsalvageable'])
            ->get();
    }

    /**
     * Lots whose certificate number was recorded but whose document never was.
     *
     * Enough to work with, not enough for an audit -- and much better found here
     * than by somebody else.
     *
     * NUR die mit Nummer: Wo gar kein Nachweis erfasst ist, ist das kein
     * Ablagethema, sondern eine Sperre -- siehe withoutCertificate(). Die
     * Überschrift „Nachweis erfasst, Dokument fehlt" stand vorher auch über
     * Losen, für die nie einer erfasst wurde, und das las sich wie eine
     * Beruhigung (Feldtest).
     */
    public static function missingDocuments()
    {
        return StockLot::query()
            ->with('partType')
            ->whereHas('partType', fn ($q) => $q->where('requires_form_one', true))
            ->whereNotIn('state', ['disposed'])
            ->get()
            ->filter(fn (StockLot $lot): bool => $lot->hasRequiredDocument() && ! $lot->hasDocumentFile());
    }

    /**
     * Form-1-Ware GANZ ohne Nachweis -- das ist keine Ablagelücke.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Feldtest: "Das system hat mir bei einem seriennummer geführten form 1
     * teil erlaubt dieses ohne nummer des form 1 und ohne scan anzulegen und
     * als verwendbar freizuschreiben. das darf nicht sein."
     *
     * Ausgeben lässt sich solche Ware seit dieser Fassung nicht mehr
     * (IssueStock::assertIssuable). Sie muss aber auch SICHTBAR sein: Sonst
     * steht sie als „verwendbar" im Regal, und erst beim Einbauen erfährt
     * jemand, dass sie es nicht ist.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public static function withoutCertificate()
    {
        return StockLot::query()
            ->with('partType')
            ->whereHas('partType', fn ($q) => $q->where('requires_form_one', true))
            ->whereNotIn('state', ['disposed', 'unsalvageable'])
            ->get()
            /*
             * Ausbau-Lose sind KEIN Nachweisproblem: Ihr Nachweis ist die
             * Feststellung beim Ausbau, und sie duerfen nur zurueck in ihr
             * eigenes Luftfahrzeug (StockLot::isRestrictedToItsAircraft --
             * durchgesetzt beim Buchen). Sie hier zu listen waere ein
             * Fehlalarm, und ein Alarm, der oft falsch ist, wird ignoriert.
             */
            ->filter(fn (StockLot $lot): bool => ! $lot->hasRequiredDocument()
                && ! $lot->isRestrictedToItsAircraft());
    }
}
