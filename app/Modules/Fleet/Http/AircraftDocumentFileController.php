<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Http;

use App\Modules\Fleet\Models\AircraftDocument;
use App\Modules\Fleet\Permissions;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Die Datei eines Luftfahrzeug-Dokuments -- auth-geprueft, wie jeder Nachweis.
 *
 * Private Disk, nie im Webroot; dies ist der einzige Weg an die Datei heran.
 * Die Pruefung steht im Controller, damit sie in der Routendefinition nicht
 * uebersehen werden kann (dasselbe Muster wie beim Warehouse-DocumentController).
 */
final class AircraftDocumentFileController
{
    public function __invoke(Request $request, AircraftDocument $document): BinaryFileResponse
    {
        abort_unless($request->user()?->can(Permissions::FLEET_VIEW) ?? false, 403);

        $datei = $document->getFirstMedia(AircraftDocument::FILE);

        abort_if($datei === null, 404);

        return response()->file($datei->getPath(), [
            'Content-Disposition' => 'inline; filename="'.addslashes($datei->file_name).'"',
        ]);
    }
}
