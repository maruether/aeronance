<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Support;

use App\Core\Contracts\AircraftDirectory;
use App\Core\Modules\ModuleManager;
use App\Modules\Fleet\Models\Aircraft;

/**
 * Die Flotte beantwortet die Kennzeichen-Frage des Kerns.
 *
 * Prüft das Modul selbst, wie jeder Beitrag an einer Naht (D3): Die Bindung
 * im ServiceProvider ist bedingungslos, und ein abgeschaltetes Flottenmodul
 * antwortet hier leer statt mit fremden Tabellen.
 */
final readonly class FleetAircraftDirectory implements AircraftDirectory
{
    public function __construct(private ModuleManager $modules) {}

    public function registrations(): array
    {
        if (! $this->modules->isEnabled('fleet')) {
            return [];
        }

        return Aircraft::query()
            ->orderBy('registration')
            ->pluck('registration')
            ->all();
    }
}
