<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Events;

/**
 * Ein neues Muster ist im Katalog.
 *
 * Skalare Nutzlast wie bei allen Naht-Ereignissen: Wer zuhoert (heute das
 * Directives-Modul, das passende Herstellerlisten anzieht), braucht keine
 * Flotten-Klassen. userId ist nullable -- ein Import oder eine Konsole legt
 * Muster ohne angemeldete Person an, und dann gibt es niemanden, dem man
 * einen Hinweis schicken koennte.
 */
final readonly class AircraftTypeCreated
{
    public function __construct(
        public int $typeId,
        public string $designation,
        public ?string $manufacturer,
        public ?int $userId,
    ) {}
}
