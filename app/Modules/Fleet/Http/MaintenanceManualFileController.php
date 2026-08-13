<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Http;

use App\Modules\Fleet\Models\MaintenanceManual;
use App\Modules\Fleet\Permissions;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Die Datei einer Wartungsunterlage -- auth-geprüft, wie jeder Nachweis.
 *
 * Private Disk, nie im Webroot; dies ist der einzige Weg an die Datei heran.
 * Dasselbe Muster wie beim Luftfahrzeug-Dokument: Die Prüfung steht im
 * Controller, damit sie in der Routendefinition nicht übersehen werden kann.
 */
final class MaintenanceManualFileController
{
    public function __invoke(Request $request, MaintenanceManual $manual): BinaryFileResponse
    {
        abort_unless($request->user()?->can(Permissions::FLEET_VIEW) ?? false, 403);

        $datei = $manual->getFirstMedia(MaintenanceManual::DOCUMENTS);

        abort_if($datei === null, 404);

        return response()->file($datei->getPath(), [
            'Content-Disposition' => 'inline; filename="'.addslashes($datei->file_name).'"',
        ]);
    }
}
