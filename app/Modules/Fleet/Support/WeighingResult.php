<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Support;

/**
 * What a weighing works out to.
 *
 * A value object rather than columns handed around loose, so a caller cannot
 * pick up the empty mass and quietly forget that the centre of gravity came out
 * of range.
 */
final readonly class WeighingResult
{
    /**
     * @param  list<string>  $findings  reasons the result is not acceptable
     */
    public function __construct(
        public float $emptyMassKg,
        public ?float $emptyCgMm,
        public ?float $nonLiftingMassKg,
        public ?float $usefulLoadKg,
        public array $findings = [],
    ) {}

    public function isAcceptable(): bool
    {
        return $this->findings === [];
    }
}
