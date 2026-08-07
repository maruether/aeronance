<?php

declare(strict_types=1);

namespace App\Modules\Fleet\TypeCertificates;

/**
 * What is being looked up.
 *
 * The authorities keep aircraft and the things fitted to them in separate
 * volumes, and a club looking up an engine has no use for 157 gliders in the
 * result. Passed explicitly rather than guessed from the search term: "Solo
 * 2350" and "ASK 21" look alike to a pattern, and the caller always knows which
 * screen it is on.
 */
enum CertificateSubject
{
    case Aircraft;

    case Component;
}
