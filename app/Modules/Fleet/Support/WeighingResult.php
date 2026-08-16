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

        /**
         * Was die Grenze der nicht tragenden Teile noch hergibt.
         *
         * ─────────────────────────────────────────────────────────────────────
         * Auf dem Blatt steht „Zuladung" als eigene Zeile in der M.N.T.-Spalte,
         * und die Kennblattgrenze heisst „Hoechstmasse der N.T. EINSCHLIESSLICH
         * Zuladung". Fachlich: „Die Zuladung ist im Flug Teil der M.N.T. Bei
         * der Waegung ist der Flieger natuerlich leer (bis auf evtl. Sprit).
         * Die zulaessige Zuladung berechnet sich dann daraus."
         *
         * Also ist das kein Eingabewert, sondern ein Rest: Grenze minus
         * gewogene M.N.T. Und er ist der Grund, warum usefulLoadKg zwei
         * Grenzen kennt statt einer -- die Hoechstmasse ist oft nicht die
         * kleinere.
         * ─────────────────────────────────────────────────────────────────────
         */
        public ?float $nonLiftingHeadroomKg = null,
    ) {}

    public function isAcceptable(): bool
    {
        return $this->findings === [];
    }
}
