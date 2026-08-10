<?php

declare(strict_types=1);

namespace App\Core\Settings;

use Closure;
use Throwable;

/**
 * Auswahllisten fuer Einstellungen, die ein Modul beisteuert.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER KERN KENNT KEINE MODULDATEN -- Leitplanke: nie direkt auf fremde
 * Tabellen. Trotzdem soll die Einstellung "Kategorie" die Arbeitsstunden-
 * Kategorien anbieten, die das Vereinsflieger-Modul aus dem Dienst gelesen
 * hat, statt nach einer nackten Nummer zu fragen ("kann nicht ohne
 * auswahlliste nach der nummer der kategorie gefragt werden").
 *
 * Also dreht sich die Richtung um: Ein Modul MELDET seine Auswahlliste beim
 * Kern an (in register()), und die Einstellungsseite fragt hier nach. Ist das
 * Modul aus, hat sich nie etwas gemeldet, und das Feld faellt auf seine
 * gewoehnliche Form zurueck -- der Kern laeuft ohne jedes Modul.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SettingOptions
{
    /** @var array<string, Closure(): array<string, string>> */
    private array $anbieter = [];

    /**
     * @param  Closure(): array<string, string>  $options  Wert => Beschriftung
     */
    public function provide(string $settingKey, Closure $options): void
    {
        $this->anbieter[$settingKey] = $options;
    }

    /**
     * Die gemeldete Auswahlliste -- oder null, wenn niemand eine beisteuert.
     *
     * Ein scheiternder Anbieter (Tabelle noch nicht migriert, Modul halb
     * eingerichtet) macht daraus ein "keine Liste", keinen Fehler: Die Seite
     * zeigt dann das gewoehnliche Feld, und der Wert bleibt eintragbar.
     *
     * @return array<string, string>|null
     */
    public function for(string $settingKey): ?array
    {
        $anbieter = $this->anbieter[$settingKey] ?? null;

        if ($anbieter === null) {
            return null;
        }

        try {
            return $anbieter();
        } catch (Throwable) {
            return null;
        }
    }
}
