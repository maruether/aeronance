<?php

declare(strict_types=1);

namespace App\Core\Identity;

/**
 * Ein Provider, der sagen kann, welche Gruppen es bei ihm gibt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * GETRENNT VON IdentityProvider, WEIL ES NICHT JEDER KANN. Ein OIDC-Provider
 * erfaehrt Gruppen erst aus dem Token des Anmeldenden -- er kann sie nicht
 * vorher aufzaehlen. Die Faehigkeit in die Hauptschnittstelle zu ziehen wuerde
 * ihn zwingen, eine leere Liste zu liefern, und eine leere Auswahl sieht in der
 * Oberflaeche aus wie „dieser Verein hat keine Funktionen" statt wie „das laesst
 * sich hier nicht abfragen".
 *
 * Ein Connector ohne diese Schnittstelle bekommt deshalb ein FREIES Feld mit
 * dem Hinweis, woher der Wert kommt -- nicht eine leere Liste.
 * ─────────────────────────────────────────────────────────────────────────────
 */
interface DiscoversGroups
{
    /**
     * Alle Gruppen des Providers.
     *
     * Darf teuer sein: Aufgerufen wird ausdruecklich, auf Knopfdruck oder im
     * Abgleich -- nie beim Aufbau eines Formulars.
     *
     * @return iterable<DiscoveredGroup>
     */
    public function groups(): iterable;
}
