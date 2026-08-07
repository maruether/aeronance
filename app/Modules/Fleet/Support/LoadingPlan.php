<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Support;

/**
 * What may sit in the seats.
 *
 * @param  list<array{seat: string, min: float, max: float, limited_by: string}>  $seats
 * @param  list<array{rear: float, front_min: float, front_max: float}>  $combinations
 */
final readonly class LoadingPlan
{
    /**
     * @param  list<array<string, mixed>>  $seats
     * @param  list<array<string, mixed>>  $combinations
     * @param  list<string>  $notes
     */
    public function __construct(
        public array $seats = [],
        public array $combinations = [],
        public array $notes = [],
        public bool $computable = false,
    ) {}
}
