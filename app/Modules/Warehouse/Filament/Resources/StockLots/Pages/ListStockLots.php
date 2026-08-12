<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\StockLots\Pages;

use App\Modules\Warehouse\Filament\Resources\StockLots\StockLotResource;
use Filament\Resources\Pages\ListRecords;

/**
 * KEIN Anlegen-Knopf, und das ist der Fix, nicht das Versaeumnis.
 *
 * Hier stand eine CreateAction -- Copy-Paste vom Muster der anderen
 * Lager-Listen. Nur: Lose entstehen ausschliesslich ueber die Buchungen
 * (Einbuchen, Ruecklauf aus Reparatur, Ausbau); die Resource hat deshalb
 * bewusst weder form() noch Create-Seite. Filament 5 oeffnete trotzdem ein
 * Modal und loeste das Schema aus der leeren Basisklasse auf: ein leerer
 * Dialog, gemessen im Feldtest ("erstellen Formular ist leer"). Ein Knopf,
 * der in ein leeres Formular fuehrt, ist schlimmer als keiner.
 */
class ListStockLots extends ListRecords
{
    protected static string $resource = StockLotResource::class;
}
