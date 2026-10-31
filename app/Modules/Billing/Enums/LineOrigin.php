<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

/**
 * Woher eine Position kommt.
 *
 * Gespeichert, nicht abgeleitet: Nach dem Stellen ist die Position ein
 * Snapshot, und ob sie einmal eine Arbeitszeit oder ein Teil war, steht dann
 * nur noch hier.
 */
enum LineOrigin: string
{
    case Labour = 'labour';
    case Part = 'part';
    case External = 'external';
    case Manual = 'manual';

    public function label(): string
    {
        return __('billing.origin.'.$this->value);
    }
}
