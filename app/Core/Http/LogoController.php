<?php

declare(strict_types=1);

namespace App\Core\Http;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Das Logo der Organisation ausliefern.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * UEBER EINE ROUTE UND NICHT AUS public/. Der uebliche Weg waere
 * storage:link -- ein Symlink, den im Docker-Kanal jeder neue Container neu
 * braucht und der im Webserver-Kanal gern vergessen wird. Eine Route
 * funktioniert in allen drei Kanaelen gleich.
 *
 * OHNE ANMELDUNG, und das ist Absicht: Das Logo steht auf der Anmeldeseite, die
 * naturgemaess niemand angemeldet aufruft. Es ist das Wappen einer Organisation,
 * kein Geheimnis -- wer es sehen darf, sieht es ohnehin am Hangar.
 *
 * Der Inhaltstyp kommt aus der GESPEICHERTEN Datei und nicht aus dem
 * Dateinamen: Beim Hochladen ist der Typ geprueft worden, der Name ist es nie.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class LogoController
{
    public function __invoke(): Response
    {
        $pfad = (string) config('aeronance.organisation.logo', '');

        if ($pfad === '' || ! Storage::disk('local')->exists($pfad)) {
            return response('', 404);
        }

        $typ = (string) (Storage::disk('local')->mimeType($pfad) ?: 'application/octet-stream');

        // Nur Bilder, auch wenn irgendwie etwas anderes in die Einstellung
        // geraten sein sollte. Ein ausgeliefertes SVG mit Skript darin waere
        // genau die Luecke, die die CSP sonst schliesst.
        if (! in_array($typ, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return response('', 404);
        }

        return response(Storage::disk('local')->get($pfad), 200, [
            'Content-Type' => $typ,
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
