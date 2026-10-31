<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Support;

/**
 * A part that went onto a card -- as plain data, for whoever asks.
 *
 * The parts trail of a card lives in the warehouse (stock movements keyed by
 * the card number), and the warehouse is optional. A module that wants to know
 * what was built in -- the billing module, to price it -- must not read the
 * warehouse's tables, and it must not even know that the warehouse exists. So
 * the card answers in its own words: what, how many, which lot. Nothing in
 * here is a model of another module.
 */
final readonly class PartUsed
{
    public function __construct(
        public string $name,
        public ?string $partNumber,
        public ?string $lotNumber,
        public float $quantity,
        public string $unit,
        /** The movement that recorded it -- an opaque reference, good for a line. */
        public string $reference,
    ) {}

    /** What a price list would be keyed by: the part number, or the name if there is none. */
    public function priceKey(): string
    {
        return $this->partNumber !== null && $this->partNumber !== '' ? $this->partNumber : $this->name;
    }
}
