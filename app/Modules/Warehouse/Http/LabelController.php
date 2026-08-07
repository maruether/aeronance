<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Http;

use App\Core\Modules\ModuleManager;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StorageLocation;
use App\Modules\Warehouse\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Die Etiketten des Lagers — für Lose und für Lagerorte.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „wir brauchen losaufkleber für die Teile. kommen aus dem
 * thermodrucker" — gedacht an Brother DK-Folie.
 *
 * Wie die Sperrzettel als HTML mit Millimetergeometrie und nicht als PDF: Die
 * übliche PDF-Bibliothek scheidet hier aus zwei Gründen aus (Sicherheitslage
 * der ersten beiden Hauptversionen, die dritte läuft noch nicht auf PHP 8.5).
 * Der Browser druckt, und das passt zur Sache — nichts muss auf dem Server
 * installiert werden, und das Ergebnis ist vor dem Druck zu sehen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ZWEI BETRIEBSARTEN, EINE RECHNUNG:
 *
 *   roll   Etikettendrucker mit Rolle — die SEITE IST DAS ETIKETT. So arbeitet
 *          ein Brother QL, ein Zebra, ein Dymo.
 *   sheet  A4-Bogen mit Raster, für Vereine ohne Etikettendrucker.
 *
 * Die Rolle ist dabei nichts Eigenes, sondern ein Raster mit einer Spalte und
 * einer Zeile. Kein zweiter Codepfad, der auseinanderlaufen kann.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WARUM KEIN „ALLES, WAS NOCH NICHT GEDRUCKT IST".
 *
 * Bei den Sperrzetteln ist das der Normalfall: Ein Zettel gehört zu einem
 * Vorgang, wird einmal gedruckt und hängt dann am Teil. Ein Los dagegen wird
 * nachgedruckt — das Etikett geht kaputt, das Teil wird umgepackt, ein Los
 * wird geteilt. Ein Merker „gedruckt" führte dazu, dass der zweite Ausdruck
 * still nichts liefert, und das ist genau dann ärgerlich, wenn man vor dem
 * Drucker steht.
 *
 * Deshalb wird immer ausdrücklich gesagt, welche Lose gedruckt werden sollen.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class LabelController
{
    /**
     * Die Losaufkleber — das Etikett am Teil.
     */
    public function lots(Request $request): View
    {
        $this->authorise($request);

        $variant = $request->query('layout') === 'sheet' ? 'sheet' : 'roll';
        $layout = $this->layout($variant);

        // Belegte Positionen eines angebrochenen A4-Bogens. Einsdurchgezaehlt,
        // wie man sie auf dem Papier zaehlt. Bei der Rolle sinnlos und deshalb
        // dort nicht ausgewertet.
        $skip = $variant === 'sheet' ? $this->skippedPositions($request) : [];

        return view('warehouse.labels.sheet', [
            'lots' => $this->requestedLots($request),
            'layout' => $layout,
            'variant' => $variant,
            'skip' => $skip,
            'withQr' => (bool) config('aeronance.lot_label.qr', true),
            'qrSize' => (float) config('aeronance.lot_label.qr_size', 16.0),
        ]);
    }

    /**
     * Die Lagerortschilder — das Etikett am Regal.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * DER EIGENTLICHE ZWECK IST DIE INVENTUR. Vorgabe: „wenn dann eher was das
     * sich mit der handy kamera scannen lässt zwecks inventur."
     *
     * Der Inventurbildschirm arbeitet ORTSWEISE — aufgebaut wie die gedruckte
     * Zählliste, Regal für Regal. Der Code am Regal trifft damit genau den
     * langsamen Schritt: Statt den Ort aus einer Liste zu suchen, scannt man
     * das Schild, vor dem man ohnehin steht.
     *
     * Deshalb sitzt der Code hier und nicht nur am Teil. Am Teil beantwortet er
     * „was ist das", am Regal beschleunigt er das Zählen.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function locations(Request $request): View
    {
        $this->authorise($request);

        $variant = $request->query('layout') === 'sheet' ? 'sheet' : 'roll';

        return view('warehouse.labels.locations', [
            'locations' => $this->requestedLocations($request),
            'layout' => $this->layout($variant),
            'variant' => $variant,
            'skip' => $variant === 'sheet' ? $this->skippedPositions($request) : [],
            'withQr' => (bool) config('aeronance.lot_label.qr', true),
            'qrSize' => (float) config('aeronance.lot_label.qr_size', 16.0),
        ]);
    }

    /**
     * Ein Bogen zum Nachmessen, ob der Drucker skaliert.
     *
     * Einmal je Drucker. Bei der Rolle ist das der eigentliche Prüfschritt:
     * Etikettendrucker haben einen nicht bedruckbaren Rand, und ob aus den
     * 62 mm der Rolle wirklich 62 mm werden, sieht man erst am Etikett.
     */
    public function calibration(Request $request): View
    {
        $this->authorise($request);

        $variant = $request->query('layout') === 'sheet' ? 'sheet' : 'roll';
        $layout = $this->layout($variant);

        /*
         * Das Lineal ist so lang wie das Etikett breit, hoechstens 100 mm --
         * und es entfaellt ganz, wenn dafuer kein Platz ist. Ein Lineal, das
         * ueber den Seitenrand hinausragt, misst nichts.
         */
        $rulerLength = (int) min(100, floor($layout['width'] - $layout['margin_left'] - 2));

        return view('warehouse.labels.calibration', [
            'layout' => $layout,
            'qrSize' => (float) config('aeronance.lot_label.qr_size', 16.0),
            'rulerLength' => max(10, $rulerLength),
            'rulerFits' => $rulerLength >= 20,
        ]);
    }

    /**
     * @return array<string, float|int>
     */
    private function layout(string $variant): array
    {
        /** @var array<string, float|int> $layout */
        $layout = config('aeronance.lot_label.'.$variant);

        return $layout;
    }

    /**
     * Die angeforderten Lose — ausdrücklich benannt.
     *
     * Ohne Angabe kommt nichts. Das ist Absicht: „drucke alles" hieße bei
     * einem gewachsenen Lager einige hundert Etiketten, und der Rollendrucker
     * fängt sofort an.
     *
     * @return Collection<int, StockLot>
     */
    private function requestedLots(Request $request): Collection
    {
        $ids = collect(explode(',', (string) $request->query('lots', '')))
            ->map(fn (string $value): int => (int) trim($value))
            ->filter()
            ->unique()
            ->all();

        if ($ids === []) {
            /** @var Collection<int, StockLot> $leer */
            $leer = collect();

            return $leer;
        }

        return StockLot::query()
            ->with(['partType'])
            ->whereIn('id', $ids)
            ->orderBy('lot_number')
            ->get();
    }

    /**
     * Die angeforderten Lagerorte.
     *
     * Anders als bei den Losen ist „alle" hier der Normalfall: Ein Verein hat
     * ein paar Dutzend Regale, und Schilder druckt man einmal für alle. Ohne
     * Angabe kommen deshalb alle.
     *
     * @return Collection<int, StorageLocation>
     */
    private function requestedLocations(Request $request): Collection
    {
        $ids = collect(explode(',', (string) $request->query('locations', '')))
            ->map(fn (string $value): int => (int) trim($value))
            ->filter()
            ->unique()
            ->all();

        return StorageLocation::query()
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return list<int>
     */
    private function skippedPositions(Request $request): array
    {
        return collect(explode(',', (string) $request->query('skip', '')))
            ->map(fn (string $value): int => (int) trim($value))
            ->filter(fn (int $value): bool => $value > 0)
            ->values()
            ->all();
    }

    private function authorise(Request $request): void
    {
        abort_unless(app(ModuleManager::class)->isEnabled('warehouse'), 404);

        /*
         * Wer Bestand sehen darf, darf ein Etikett drucken. Das Etikett trägt
         * nichts, was nicht ohnehin in der Bestandsliste steht -- und ein
         * eigenes Recht dafür wäre eines, das niemand vergibt und das dann
         * jemanden vor dem Drucker stehen lässt.
         */
        abort_unless(
            $request->user()?->can(Permissions::STOCK_VIEW) ?? false,
            403,
        );
    }
}
