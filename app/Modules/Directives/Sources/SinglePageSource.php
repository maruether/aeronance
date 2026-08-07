<?php

declare(strict_types=1);

namespace App\Modules\Directives\Sources;

/**
 * A source that answers once, not once per type.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT IT PREVENTS. The refresh asks a per-type source about every type the club
 * flies -- correct for a manufacturer, who publishes a sheet per model. A
 * configured source says which it is through its spec (isSinglePage), but a
 * class-based one had no way to say it at all, so the gazette was fetched once
 * for the ASK 21, once for the DR400, once for the Duo Discus: the same national
 * list, downloaded and parsed as many times as the club has types.
 *
 * Nothing broke -- the rows are identical and the second import updates what the
 * first created -- which is exactly why it went unnoticed. It is somebody else's
 * server being asked the same question five times.
 * ─────────────────────────────────────────────────────────────────────────────
 */
interface SinglePageSource {}
