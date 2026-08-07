<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Welche Fassung von Aeronance läuft hier?
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE FRAGE KONNTE DIE ANWENDUNG BISHER NICHT BEANTWORTEN. Der `pack`-Job legt
 * seit jeher eine `VERSION`-Datei ins Release — gelesen hat sie niemand. Für
 * eine Aktualisierungsprüfung ist das die Hälfte der Aufgabe: „Was ist neu?"
 * lässt sich ohne „Was bin ich?" nicht beantworten.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE DATEI IST DIE EINZIGE QUELLE, und das ist Absicht.
 *
 * Naheliegend wäre, in einem Git-Verzeichnis `git describe` aufzurufen. Drei
 * Gründe dagegen: Im Docker-Image gibt es kein Git, im Release-Tarball kein
 * `.git`, und ein Prozessaufruf bei jeder Seitenanzeige ist der falsche Preis
 * für eine Zeichenkette, die sich nur beim Update ändert.
 *
 * Stattdessen schreibt jeder Weg, der eine Fassung installiert, die Datei:
 * der `pack`-Job für Tarball und Docker, `deploy/update.sh` nach dem Auschecken.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * OHNE DATEI IST DIE ANTWORT `null` UND NICHT „0.0.0".
 *
 * Das ist der Entwicklungsstand oder ein von Hand ausgechecktes Repo. Eine
 * erfundene Nummer wäre schlimmer als keine: Die Aktualisierungsprüfung würde
 * jede Veröffentlichung als „neuer" melden und jeden Entwickler zum Update
 * auffordern.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class Version
{
    private static ?string $zwischengespeichert = null;

    private static bool $gelesen = false;

    /**
     * Die laufende Fassung, etwa `v1.2.3` — oder null im Entwicklungsstand.
     */
    public static function current(): ?string
    {
        if (self::$gelesen) {
            return self::$zwischengespeichert;
        }

        self::$gelesen = true;
        self::$zwischengespeichert = self::read();

        return self::$zwischengespeichert;
    }

    /**
     * Zum Anzeigen: die Fassung oder ein ehrliches Wort dafür, dass es keine gibt.
     */
    public static function label(): string
    {
        return self::current() ?? __('updates.development_build');
    }

    /**
     * Nur für Tests — der Zwischenspeicher lebt sonst über die ganze Anfrage.
     */
    public static function forget(): void
    {
        self::$gelesen = false;
        self::$zwischengespeichert = null;
    }

    private static function read(): ?string
    {
        $pfad = base_path('VERSION');

        if (! is_file($pfad)) {
            return null;
        }

        $inhalt = trim((string) file_get_contents($pfad));

        /*
         * Eine leere oder unsinnige Datei ist wie keine. Erwartet wird ein
         * SemVer-Tag, wie ihn die Release-Pipeline vergibt -- alles andere
         * waere geraten, und geraten wird bei Versionsnummern nicht.
         */
        return preg_match('/^v?\d+\.\d+\.\d+/', $inhalt) === 1 ? $inhalt : null;
    }
}
