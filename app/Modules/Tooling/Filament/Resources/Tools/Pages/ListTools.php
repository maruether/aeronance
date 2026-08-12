<?php

declare(strict_types=1);

namespace App\Modules\Tooling\Filament\Resources\Tools\Pages;

use App\Modules\Tooling\Filament\Resources\Tools\ToolResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListTools extends ListRecords
{
    protected static string $resource = ToolResource::class;

    /**
     * Ohne diese Zeilen gab es KEINEN Weg, ein Werkzeug anzulegen: Formular,
     * Create-Seite und Route existierten ("/werkzeuge/neu" haette
     * funktioniert), aber keine Oberflaeche fuehrte hin -- Feldtest: "nix
     * kann angelegt werden." Eine Seite verspricht nur, was sie zeigt.
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
