<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Airworthiness;

/**
 * One reason somebody should look before the aircraft flies.
 */
final readonly class OpenItem
{
    public function __construct(
        public string $source,
        public string $what,
        public string $detail = '',
        public bool $blocking = true,

        /**
         * Whether this also stands in the way of a RELEASE TO SERVICE.
         *
         * A separate question from grounding the aircraft, and the distinction
         * matters: an aircraft whose ARC has expired is not airworthy, but the
         * maintenance carried out on it can still be released -- the CRS says the
         * work was done properly, not that the aircraft may fly.
         *
         * Defaults to false so a contributor has to mean it. What earns a true is
         * something that makes the RELEASE ITSELF unsound -- an unassessed
         * directive, for instance: signing while nobody has read a line of the
         * manufacturer's list is signing over an unknown.
         */
        public bool $blocksRelease = false,
    ) {}
}
