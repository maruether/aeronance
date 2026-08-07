<?php

declare(strict_types=1);

namespace App\Core\Identity;

/**
 * Eine Gruppe, wie ein Provider sie meldet.
 *
 * Getrennt von ExternalGroup (dem Datensatz), weil ein Connector nichts von der
 * Tabelle wissen soll -- er meldet, was er gefunden hat, und der Kern
 * entscheidet, was damit geschieht. Dasselbe Muster wie ExternalSubject.
 */
final readonly class DiscoveredGroup
{
    public function __construct(
        /** Der Vergleichswert -- muss zu ExternalSubject::$groups passen. */
        public string $value,

        /** Anzeigename, falls er sich vom Vergleichswert unterscheidet. */
        public ?string $label = null,

        /** Wie viele Mitglieder der Provider darin gesehen hat. */
        public ?int $memberCount = null,
    ) {}
}
