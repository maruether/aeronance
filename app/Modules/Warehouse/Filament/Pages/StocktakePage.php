<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Pages;

use App\Modules\Warehouse\Actions\RecordStocktake;
use App\Modules\Warehouse\Actions\ResolveScanCode;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StorageLocation;
use App\Modules\Warehouse\Permissions;
use App\Modules\Warehouse\Support\ScanCode;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Entering what the stocktake found.
 *
 * Deliberately laid out like the printed counting list -- location by location,
 * lot by lot -- because that is the sheet in the person's hand. Anything else
 * makes transcribing an exercise in searching.
 *
 * The rule that shapes this screen is the, and it is the reason the surplus
 * field is separate from the counted field rather than being the same box with a
 * bigger number in it:
 *
 *   A surplus on a lot-tracked part is NOT booked onto the lot. Doing so would
 *   claim the extra part came with that delivery and is covered by its Form 1,
 *   and nobody counting a shelf knows that.
 *
 * So a lot can only be corrected downwards here. Parts found beyond what any lot
 * accounts for are entered once, at the bottom, and open a lot of their own
 * without a certificate -- in quarantine, because a part of unknown origin has
 * no evidence behind it.
 */
final class StocktakePage extends Page
{
    protected string $view = 'warehouse.filament.pages.stocktake';

    protected static ?string $slug = 'inventur';

    protected static ?int $navigationSort = 20;

    public ?string $location = null;

    public ?string $countedAt = null;

    /** @var array<int, string> counted quantity per lot, keyed by lot id */
    public array $lotCounts = [];

    /** @var array<int, string> counted quantity per bulk part type */
    public array $bulkCounts = [];

    /**
     * Was ausserhalb aller Lose gefunden wurde -- EINMAL am Ende, mit Auswahl.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Feldtest: "es wird schnell unübersichtlich wenn das bei jedem möglichen
     * teil auftaucht. ich denke es wäre besser das am ende der inventur einmal
     * anzuzeigen mit auswahl des bauteiltypen."
     *
     * Und das trifft die Häufigkeit: Gezählt wird jedes Teil, GEFUNDEN wird
     * fast nie eins. Ein Feld je Kachel kostete an jeder Zeile Aufmerksamkeit
     * für den seltenen Fall -- jetzt steht es einmal unten, und wer nichts
     * gefunden hat, sieht nur eine leere Zeile.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @var list<array{part_type_id?: string|int|null, quantity?: string|null, note?: string|null}>
     */
    public array $foundRows = [];

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.warehouse');
    }

    public static function getNavigationLabel(): string
    {
        return __('warehouse.stocktake.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('warehouse.stocktake.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('warehouse.stocktake.subheading');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedClipboardDocumentCheck;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permissions::STOCK_RECEIVE) ?? false;
    }

    public function mount(): void
    {
        $this->countedAt = now()->toDateString();
        $this->foundRows = [self::emptyFoundRow()];
    }

    /** @return array{part_type_id: null, quantity: null, note: null} */
    private static function emptyFoundRow(): array
    {
        return ['part_type_id' => null, 'quantity' => null, 'note' => null];
    }

    public function addFoundRow(): void
    {
        $this->foundRows[] = self::emptyFoundRow();
    }

    public function removeFoundRow(int $index): void
    {
        unset($this->foundRows[$index]);

        $this->foundRows = array_values($this->foundRows);

        if ($this->foundRows === []) {
            $this->foundRows = [self::emptyFoundRow()];
        }
    }

    /**
     * Die Teile, die überhaupt als Fund in Frage kommen.
     *
     * Nur losgeführte: Bei Sammelbestand ist Mehrbestand schlicht eine
     * Zahlkorrektur oben -- ein zweiter Weg dafür wäre eine zweite Wahrheit.
     *
     * @return array<int, string>
     */
    public function foundCandidates(): array
    {
        return PartType::query()
            ->orderBy('name')
            ->get()
            ->filter(fn (PartType $p): bool => $p->isLotTracked())
            ->mapWithKeys(fn (PartType $p): array => [$p->id => $p->name])
            ->all();
    }

    /**
     * Ein gescanntes Regalschild wählt den Ort.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Vorgabe: „wenn dann eher was das sich mit der handy kamera scannen lässt
     * zwecks inventur."
     *
     * Dieser Bildschirm arbeitet ORTSWEISE, aufgebaut wie die gedruckte
     * Zählliste. Der langsame Schritt ist deshalb nicht das Zählen, sondern das
     * Heraussuchen des Ortes aus einer Liste — während man vor genau diesem
     * Regal steht. Der Scan überspringt ihn.
     *
     * WAS EIN CODE BEDEUTET, ENTSCHEIDET DER SERVER, nicht der Browser. Und ein
     * LOSAUFKLEBER wählt hier ausdrücklich nichts: Er ist ein gültiger Code,
     * nur nicht für diese Frage. Still den Ort zu wechseln, weil jemand aufs
     * falsche Etikett gehalten hat, hiesse mitten in einer Zählung die Liste
     * unter den Händen zu tauschen.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function applyScan(string $code): void
    {
        $ergebnis = app(ResolveScanCode::class)->handle($code);

        if ($ergebnis['status'] === ResolveScanCode::FOREIGN) {
            Notification::make()->warning()->title(__('warehouse.scan.foreign'))->send();

            return;
        }

        if ($ergebnis['status'] === ResolveScanCode::UNKNOWN) {
            Notification::make()->warning()->title(__('warehouse.scan.unknown_location'))->send();

            return;
        }

        if ($ergebnis['kind'] !== ScanCode::KIND_LOCATION) {
            Notification::make()->warning()->title(__('warehouse.scan.not_a_location'))->send();

            return;
        }

        /** @var StorageLocation $ort */
        $ort = $ergebnis['record'];

        $this->location = (string) $ort->getKey();

        Notification::make()
            ->success()
            ->title(__('warehouse.scan.location_applied', ['location' => $ort->name]))
            ->send();
    }

    /** @return Collection<int, StorageLocation> */
    public function locations(): Collection
    {
        return StorageLocation::orderBy('name')->get();
    }

    /**
     * The parts to count, in the order one walks the store.
     *
     * @return Collection<int, PartType>
     */
    public function parts(): Collection
    {
        return PartType::query()
            ->with(['storageCompartment.location', 'lots.movements'])
            ->when($this->location, fn ($q) => $q->whereHas(
                'storageCompartment',
                fn ($c) => $c->where('storage_location_id', $this->location),
            ))
            ->get()
            ->sortBy([
                fn (PartType $p) => $p->storageCompartment?->location?->name ?? 'zzz',
                fn (PartType $p) => $p->storageCompartment?->name ?? 'zzz',
                fn (PartType $p) => $p->name,
            ]);
    }

    /**
     * Lots of a part that still hold something.
     *
     * @return Collection<int, StockLot>
     */
    public function lotsOf(PartType $part): Collection
    {
        return $part->lots
            ->filter(fn (StockLot $lot): bool => $lot->remainingQuantity() > 0)
            ->sortBy('lot_number')
            ->values();
    }

    public function submit(): void
    {
        $action = app(RecordStocktake::class);
        $user = auth()->user();
        $booked = 0;
        $foundLots = [];
        $refused = [];

        foreach ($this->bulkCounts as $partId => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            $part = PartType::find($partId);

            if ($part === null) {
                continue;
            }

            try {
                if ($action->correctBulk($part, (float) $value, $user, __('warehouse.stocktake.note'), $this->countedAt) !== null) {
                    $booked++;
                }
            } catch (Throwable $e) {
                $refused[] = $part->name.': '.$e->getMessage();
            }
        }

        foreach ($this->lotCounts as $lotId => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            $lot = StockLot::find($lotId);

            if ($lot === null) {
                continue;
            }

            try {
                if ($action->correctLotShortfall($lot, (float) $value, $user, __('warehouse.stocktake.note'), $this->countedAt) !== null) {
                    $booked++;
                }
            } catch (Throwable $e) {
                // The surplus case lands here, with the explanation from the
                // action -- which says why, not merely that it failed.
                $refused[] = $lot->label().': '.$e->getMessage();
            }
        }

        foreach ($this->foundRows as $row) {
            $menge = (float) ($row['quantity'] ?? 0);
            $part = PartType::find($row['part_type_id'] ?? null);

            // Eine leere Zeile ist der Normalfall, kein Fehler: Sie steht da,
            // weil sie angeboten wird, nicht weil jemand etwas gefunden hat.
            if ($part === null || $menge <= 0) {
                continue;
            }

            try {
                $lot = $action->recordFound(
                    $part,
                    $menge,
                    $user,
                    trim((string) ($row['note'] ?? '')) !== ''
                        ? (string) $row['note']
                        : __('warehouse.stocktake.found_default_note'),
                    $this->countedAt,
                );
                $foundLots[] = $lot->lot_number;
            } catch (Throwable $e) {
                $refused[] = $part->name.': '.$e->getMessage();
            }
        }

        $this->report($booked, $foundLots, $refused);

        $this->lotCounts = [];
        $this->bulkCounts = [];
        $this->foundRows = [self::emptyFoundRow()];
    }

    /**
     * @param  list<string>  $foundLots
     * @param  list<string>  $refused
     */
    private function report(int $booked, array $foundLots, array $refused): void
    {
        if ($booked === 0 && $foundLots === [] && $refused === []) {
            Notification::make()
                ->info()
                ->title(__('warehouse.stocktake.nothing_to_book'))
                ->send();

            return;
        }

        if ($booked > 0) {
            Notification::make()
                ->success()
                ->title(__('warehouse.stocktake.booked', ['n' => $booked]))
                ->send();
        }

        if ($foundLots !== []) {
            // Worth a persistent notice: somebody now has to establish where
            // these came from, and until then they are not usable.
            Notification::make()
                ->warning()
                ->title(__('warehouse.stocktake.found_title', ['n' => count($foundLots)]))
                ->body(__('warehouse.stocktake.found_body', ['lots' => implode(', ', $foundLots)]))
                ->persistent()
                ->send();
        }

        foreach ($refused as $reason) {
            Notification::make()
                ->danger()
                ->title(__('warehouse.stocktake.refused'))
                ->body($reason)
                ->persistent()
                ->send();
        }
    }
}
