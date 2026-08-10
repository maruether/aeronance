<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Models\User;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Profilbilder ausliefern -- auth-geprueft, wie jedes Dokument.
 *
 * Die Bilder liegen auf der privaten documents-Disk und nie im Webroot
 * (Leitplanke Datei-Uploads). Sehen darf sie jede angemeldete Person: Das
 * Bild haengt neben dem Namen an Arbeitskarten und Freigaben, und wer die
 * sehen darf, darf auch das Gesicht dazu sehen. Ohne Anmeldung: nichts.
 */
final class AvatarController
{
    public function __invoke(User $user): BinaryFileResponse
    {
        $bild = $user->getFirstMedia(User::AVATAR);

        abort_if($bild === null, 404);

        return response()->file($bild->getPath(), [
            // Privat und unveraenderlich: Die Adresse traegt die uuid des
            // Bildes (siehe getFilamentAvatarUrl) -- ein neues Bild ist eine
            // neue Adresse, also darf diese hier lange im Cache liegen.
            'Cache-Control' => 'private, max-age=604800, immutable',
        ]);
    }
}
