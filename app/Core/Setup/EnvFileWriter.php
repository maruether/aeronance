<?php

declare(strict_types=1);

namespace App\Core\Setup;

use InvalidArgumentException;

/**
 * Rewrites values in a .env file without touching anything else.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE EINE GEFÄHRLICHE STELLE IST DER ZEILENUMBRUCH. Wer in ein Passwortfeld
 * "geheim\nAPP_DEBUG=true" tippt, hätte ohne Prüfung eine eigene Zeile in der
 * Konfiguration -- ein Webformular, das die .env schreibt, ist genau dann ein
 * Einfallstor, wenn es Werte ungeprüft übernimmt. Deshalb: Steuerzeichen
 * werden abgelehnt (nicht bereinigt -- ein stillschweigend veränderter Wert
 * wäre ein anderes Passwort), und geschrieben wird immer in doppelten
 * Anführungszeichen mit escapten Sonderzeichen.
 *
 * Kommentare und alle nicht genannten Zeilen bleiben unangetastet: Die .env
 * einer Installation trägt handgeschriebene Notizen, und ein Schreiber, der
 * die Datei "neu ordnet", wirft sie weg.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class EnvFileWriter
{
    /**
     * @param  array<string, string>  $values  Schlüssel => neuer Wert
     */
    public static function withValues(string $contents, array $values): string
    {
        foreach ($values as $key => $value) {
            self::assertSafe($key, $value);

            $line = $key.'='.self::quote($value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            if (preg_match($pattern, $contents)) {
                $contents = (string) preg_replace($pattern, $line, $contents, 1);
            } else {
                $contents = rtrim($contents, "\n")."\n".$line."\n";
            }
        }

        return $contents;
    }

    private static function assertSafe(string $key, string $value): void
    {
        if (! preg_match('/\A[A-Z][A-Z0-9_]*\z/', $key)) {
            throw new InvalidArgumentException(sprintf('"%s" ist kein zulässiger Schlüssel.', $key));
        }

        // \r, \n und NUL sind die Injektionsvektoren; alle übrigen
        // Steuerzeichen haben in Zugangsdaten ebenfalls nichts verloren.
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException(
                'Der Wert enthält Steuerzeichen (Zeilenumbruch o. Ä.) und wird nicht übernommen.'
            );
        }
    }

    private static function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }
}
