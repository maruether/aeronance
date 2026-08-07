<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\ModuleServiceProvider;

return [
    AppServiceProvider::class,
    /*
     * REIHENFOLGE IST HIER BEDEUTUNG, nicht Geschmack.
     *
     * ModuleServiceProvider legt in seinem register() die Einstellungen des
     * Vereins ueber die Konfiguration. Filament baut sein Panel samt Routen
     * ebenfalls im register() -- und Laravel arbeitet die Provider der Reihe
     * nach ab. Steht das Panel zuerst, sieht es die Einstellungen nie und
     * entscheidet auf der rohen .env.
     */
    ModuleServiceProvider::class,
    AdminPanelProvider::class,
];
