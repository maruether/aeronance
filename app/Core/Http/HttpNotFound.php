<?php

declare(strict_types=1);

namespace App\Core\Http;

use RuntimeException;

/**
 * The page is not there.
 *
 * Its own type because 404 is the only HTTP status that carries meaning for the
 * driver rather than just being a failure: a paged list ends with one. WordPress
 * does not serve an empty feed past the last page, it serves a 404.
 *
 * The distinction that makes this worth a class: a 404 on the FIRST page means
 * the URL or the type slug is wrong, and reading that as "the list ended" would
 * report an empty catalogue for a manufacturer who publishes hundreds. Only a
 * later page may end a list.
 */
final class HttpNotFound extends RuntimeException {}
