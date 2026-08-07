<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Actions;

use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StorageLocation;
use App\Modules\Warehouse\Support\ScanCode;

/**
 * Was ein gescannter Code bedeutet.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DREI ANTWORTEN, UND SIE MÜSSEN AUSEINANDERGEHALTEN WERDEN. Wer sie zu einem
 * „geht nicht" zusammenfasst, lässt jemanden im Lager stehen, der nicht weiß,
 * ob er falsch gescannt hat oder ob etwas fehlt:
 *
 *   foreign   Kein Code von hier. Ein Paketaufkleber, ein WLAN-Code, das
 *             Etikett eines anderen Systems. → „Das ist kein Aeronance-Code."
 *
 *   unknown   Unser Code, aber der Datensatz existiert nicht (mehr). Ein
 *             Etikett von einem Los, das ausgebucht und gelöscht wurde, oder
 *             ein Schild an einem entfernten Regal.
 *             → „Kennen wir nicht (mehr)." Das ist ein Befund, kein Fehler.
 *
 *   ok        Treffer.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DAS LOS WIRD ÜBER SEINE NUMMER GESUCHT, nicht über eine ID. Die Losnummer
 * steht im Klartext auf demselben Etikett — wer den Code nicht lesen kann, tippt
 * die Nummer ab und landet an derselben Stelle. Ein Etikett, dessen Code kaputt
 * ist, bleibt damit benutzbar, und das ist bei Thermodruck kein Randfall.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ResolveScanCode
{
    public const FOREIGN = 'foreign';

    public const UNKNOWN = 'unknown';

    public const OK = 'ok';

    /**
     * @return array{status: string, kind: ?string, record: StockLot|StorageLocation|null}
     */
    public function handle(string $raw): array
    {
        $code = ScanCode::parse($raw);

        if ($code === null) {
            /*
             * Auch das ABTIPPEN einer Losnummer landet hier -- jemand hat den
             * Code nicht lesen koennen und "EASA-12345" eingegeben. Das ist
             * kein fremder Code, sondern der haeufigste Rueckfallweg, und ihn
             * abzuweisen waere die unfreundlichste denkbare Antwort.
             */
            $lot = $this->findLot(trim($raw));

            if ($lot !== null) {
                return $this->hit(ScanCode::KIND_LOT, $lot);
            }

            return ['status' => self::FOREIGN, 'kind' => null, 'record' => null];
        }

        $record = match ($code['kind']) {
            ScanCode::KIND_LOT => $this->findLot($code['reference']),
            ScanCode::KIND_LOCATION => StorageLocation::query()->find((int) $code['reference']),
            default => null,
        };

        if ($record === null) {
            return ['status' => self::UNKNOWN, 'kind' => $code['kind'], 'record' => null];
        }

        return $this->hit($code['kind'], $record);
    }

    private function findLot(string $lotNumber): ?StockLot
    {
        if ($lotNumber === '') {
            return null;
        }

        return StockLot::query()
            ->with(['partType'])
            ->where('lot_number', $lotNumber)
            ->first();
    }

    /**
     * @return array{status: string, kind: string, record: StockLot|StorageLocation}
     */
    private function hit(string $kind, StockLot|StorageLocation $record): array
    {
        return ['status' => self::OK, 'kind' => $kind, 'record' => $record];
    }
}
