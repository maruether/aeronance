<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\MaintenanceManuals\Pages;

use App\Modules\Fleet\Filament\Resources\MaintenanceManuals\MaintenanceManualResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * Die Wartungsunterlagen -- mit einem Weg, die erste anzulegen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIESE SEITE HATTE KEINEN ANLEGEN-KNOPF, und das war eine Sackgasse mit
 * Ansage: Filament stellt ihn nicht von selbst hin, die Anlegeseite war zwar
 * geroutet, aber nichts verlinkte sie -- und alle Zeilen-Aktionen brauchen
 * einen Datensatz, den es ohne diesen Knopf nie gab. Wer die Liste öffnete,
 * sah "Keine Wartungsunterlagen" und keine Möglichkeit, das zu ändern.
 *
 * Feldtest, zweimal gemeldet: "ich kann immer noch keine Wartungsunterlagen
 * hochladen." Der Upload war da; die Tür davor fehlte.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ListMaintenanceManuals extends ListRecords
{
    protected static string $resource = MaintenanceManualResource::class;

    /** @return list<CreateAction> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
