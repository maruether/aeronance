<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Events;

/**
 * Eine Freigabebescheinigung (CRS) ist entstanden.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Die Nutzlast sind SKALARE, keine Models -- dieselbe Naht wie
 * PartIssuedToAircraft: Wer zuhoert, soll nichts von den Tabellen dieses
 * Moduls wissen muessen. Die Druck-URL wird HIER gebaut (dieses Modul kennt
 * seine Route), damit die Flotte sie nur ablegt und nie einen fremden
 * Routennamen traegt.
 *
 * Feldtest-Anlass: "Freigaben landen nicht als pdf in den dokumenten." Das
 * Projekt druckt bewusst per Browser -- also landet in der Lebenslaufakte
 * der VERWEIS auf die Bescheinigung, nicht eine zweite Datei, die von der
 * ersten abweichen koennte.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class ReleaseIssued
{
    public function __construct(
        public int $aircraftId,
        public string $releaseNumber,
        public string $releasedAt,
        public string $printUrl,
    ) {}
}
