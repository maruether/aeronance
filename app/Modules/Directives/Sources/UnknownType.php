<?php

declare(strict_types=1);

namespace App\Modules\Directives\Sources;

use RuntimeException;

/**
 * This manufacturer does not build that aircraft.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Its own type so a scheduled run can tell two things apart that both arrive as
 * "no list for this model":
 *
 *   - Schleicher genuinely has nothing to say about a DG-300. Every source is
 *     asked about every type in the fleet, so this happens on most combinations
 *     of the two, every week. It is not a fault and must not read like one.
 *   - A model somebody typed wrong, or a manufacturer who renamed a type. That
 *     one needs saying, because the directives for a real aircraft would
 *     silently stop arriving.
 *
 * The difference between them is whether the manufacturer's OWN index of types
 * contains the name -- which is why this is only thrown where such an index
 * exists. Where it does not, the doubt stays, and so does the warning.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class UnknownType extends RuntimeException {}
