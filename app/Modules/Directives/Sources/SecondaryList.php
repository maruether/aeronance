<?php

declare(strict_types=1);

namespace App\Modules\Directives\Sources;

/**
 * A source that lists documents somebody else owns.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The same statement `secondary_list: true` makes in a manufacturer file, for
 * the sources that are classes rather than specs. The gazette is one: it
 * publishes EASA, UK CAA, FAA and Transport Canada directives under German
 * numbers, and where the issuing authority's own list already holds the
 * document, there is no reason to file it twice.
 *
 * What is unique to it is still filed -- and for an Annex-I type the gazette is
 * the only source there is.
 * ─────────────────────────────────────────────────────────────────────────────
 */
interface SecondaryList {}
