<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Listeners;

use App\Core\Modules\ModuleManager;
use App\Modules\Fleet\Enums\DocumentType;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AircraftDocument;
use App\Modules\TaskCards\Events\ReleaseIssued;

/**
 * Eine Freigabebescheinigung wird eine Zeile in der Lebenslaufakte.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Feldtest: "Freigaben landen nicht als pdf in den dokumenten." Sie landen
 * jetzt als VERWEIS: Das Projekt druckt per Browser, die Bescheinigung lebt
 * in der Werkstatt (unveraenderlich, mit SUPERSEDED-Banner bei Korrektur) --
 * eine abgelegte Zweitdatei koennte von ihr abweichen, der Verweis nie.
 *
 * JE BESCHEINIGUNG EINE ZEILE, auch bei einer Korrektur: Die Akte zeigt, was
 * es gab, nicht nur, was gilt. Der Ausdruck der abgeloesten traegt ohnehin
 * das Banner.
 *
 * Leise Ablehnungen wie beim Vorbild RecordIssuedPartAsInstallation: kein
 * Flottenmodul oder kein bekanntes Luftfahrzeug heisst "nichts zu tun",
 * nie "Fehler" -- dieser Code laeuft hinter fremder Arbeit.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class FileReleaseAsAircraftDocument
{
    public function __construct(private ModuleManager $modules) {}

    public function handle(ReleaseIssued $event): void
    {
        if (! $this->modules->isEnabled('fleet')) {
            return;
        }

        if (Aircraft::query()->whereKey($event->aircraftId)->doesntExist()) {
            return;
        }

        AircraftDocument::create([
            'aircraft_id' => $event->aircraftId,
            'type' => DocumentType::Crs,
            'title' => __('fleet.document.crs_title', ['number' => $event->releaseNumber]),
            'reference' => $event->releaseNumber,
            'issued_at' => $event->releasedAt,
            'link' => $event->printUrl,
        ]);
    }
}
