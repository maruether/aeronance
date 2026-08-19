<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\DemoServiceProvider;
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

    /*
     * VOR dem Panel: Der Demomodus legt den Mailer um, und das Panel
     * entscheidet in seinem eigenen Aufbau, ob es „Passwort vergessen"
     * ueberhaupt anbietet (Postman::canSend). Danach waere die Entscheidung
     * schon gefallen -- mit einem Link, der ins Leere fuehrt.
     */
    DemoServiceProvider::class,

    AdminPanelProvider::class,
];
