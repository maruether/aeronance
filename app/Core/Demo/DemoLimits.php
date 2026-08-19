<?php

declare(strict_types=1);

namespace App\Core\Demo;

use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Was die Demo nur in kleinen Mengen tut.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „manuelles abrufen von TM's wird stark rate limited" — und auf die
 * Rückfrage, ob je Sitzung oder je Instanz: „pro instanz".
 *
 * Das ist die richtige Antwort, und sie ist nicht bequem: Ein Besucher kann dem
 * nächsten das Kontingent wegnehmen. Der Schutz gilt aber nicht dem Besucher,
 * sondern dem Hersteller, dessen Server die Demo sonst für jeden Neugierigen
 * einzeln befragt. Eine Zählung je Sitzung wäre keine Grenze, sondern eine
 * Formalität: Neue Sitzung, neues Kontingent.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class DemoLimits
{
    private const KEY = 'demo:directive-fetch';

    public function __construct(private readonly DemoMode $demo) {}

    /**
     * Einen Handabruf zählen -- oder ablehnen.
     *
     * Quellen, die gar nicht ins Netz gehen (Handeingabe, CSV), zählen nicht
     * mit: Sie belasten niemanden, und sie sind in einer Demo genau das, was
     * man ausprobieren soll.
     */
    public function guardDirectiveFetch(bool $reachesOut = true): void
    {
        if (! $this->demo->isActive() || ! $reachesOut) {
            return;
        }

        $grenze = max(1, (int) config('aeronance.demo.fetch_per_hour', 5));

        if (RateLimiter::tooManyAttempts(self::KEY, $grenze)) {
            throw new RuntimeException(__('demo.fetch_limited', [
                'limit' => $grenze,
                'minutes' => (int) ceil(RateLimiter::availableIn(self::KEY) / 60),
            ]));
        }

        RateLimiter::hit(self::KEY, 3600);
    }
}
