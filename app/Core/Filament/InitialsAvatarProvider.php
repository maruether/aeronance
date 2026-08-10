<?php

declare(strict_types=1);

namespace App\Core\Filament;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * Initialen statt Fremddienst.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Filaments Vorgabe laedt die Platzhalter-Avatare von ui-avatars.com -- einem
 * externen Dienst. Die eigene CSP laesst das zu Recht nicht zu (img-src
 * 'self' data:), und so stand neben jedem Konto ein "nicht gefunden"-Bild.
 * Dazu kommt, was der Aufruf bedeutet haette: Jeder Seitenaufbau meldet
 * Namen von Vereinsmitgliedern an einen Dritten.
 *
 * Also entsteht das Bild hier: ein SVG mit den Initialen, als data-URI,
 * eingefaerbt aus dem Namen -- dieselbe Person bekommt immer dieselbe Farbe.
 * Kein Netz, kein Dritter, nichts zu laden.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $name = trim((string) ($record->getAttribute('name') ?? ''));
        $initialen = $this->initialsOf($name !== '' ? $name : '?');

        // Farbton aus dem Namen: stabil, ohne Zustand, und crc32 reicht --
        // hier wird nichts geschuetzt, nur gestreut.
        $farbton = crc32($name) % 360;

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            .'<rect width="100" height="100" fill="hsl(%d 45%% 42%%)"/>'
            .'<text x="50" y="50" dy=".36em" text-anchor="middle" '
            .'font-family="sans-serif" font-size="44" fill="#fff">%s</text>'
            .'</svg>',
            $farbton,
            htmlspecialchars($initialen, ENT_QUOTES | ENT_XML1),
        );

        return 'data:image/svg+xml;charset=UTF-8,'.rawurlencode($svg);
    }

    private function initialsOf(string $name): string
    {
        $woerter = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $erstes = mb_substr($woerter[0] ?? '?', 0, 1);
        $letztes = count($woerter) > 1 ? mb_substr(end($woerter), 0, 1) : '';

        return mb_strtoupper($erstes.$letztes);
    }
}
