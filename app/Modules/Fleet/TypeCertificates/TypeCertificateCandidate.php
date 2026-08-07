<?php

declare(strict_types=1);

namespace App\Modules\Fleet\TypeCertificates;

/**
 * One search hit, before anybody decides it is the right one.
 *
 * A value object rather than a record, because a search that wrote rows would
 * fill the type list with everything anybody ever mistyped. Nothing is stored
 * until a person picks a candidate.
 */
final readonly class TypeCertificateCandidate
{
    public function __construct(
        public string $designation,
        public string $certificate,
        public string $authority,
        public ?string $manufacturer = null,
        public ?string $dataSheetUrl = null,
        public ?string $pageUrl = null,

        /**
         * The SAME aircraft's number at another authority.
         *
         * ─────────────────────────────────────────────────────────────────────
         * The Blaues Buch prints two in one row -- "339/SP … EASA.A.221" -- and
         * both are real: Germany quotes the national Kennblatt for an Annex-I
         * type and the EASA reference for a European one. A type that stored
         * only the number it was looked up under matched only half of what is
         * published about it.
         *
         * It used to travel in dataSheetUrl, with a note saying a field of its
         * own was not worth it for the one authority that fills it. That was
         * true while a type could hold a single number; it stopped being true
         * the moment the type could hold several, and a URL field holding a
         * certificate number was going to be read as a URL sooner or later.
         *
         * @var list<array{number: string, authority: string}>
         */
        public array $alsoFiledAs = [],
    ) {}

    public function label(): string
    {
        return sprintf(
            '%s — %s%s',
            $this->certificate,
            $this->designation,
            $this->manufacturer !== null ? ' ('.$this->manufacturer.')' : '',
        );
    }
}
