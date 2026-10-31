<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

/**
 * Geldbeträge als Zeichenketten mit zwei Nachkommastellen, gerechnet mit bcmath.
 *
 * Kein float. 0.1 + 0.2 ist in float nicht 0.3, und eine Rechnung, deren
 * Summe um einen Cent neben der Summe ihrer Positionen liegt, ist genau die
 * Art Fehler, die ein Kunde findet und ein Prüfer notiert. Die Datenbank
 * hält DECIMAL(12,2), Eloquent liefert das als String -- hier bleibt es einer.
 *
 * Gerundet wird kaufmännisch (half up) auf zwei Stellen, und zwar erst am
 * Ende jeder Rechnung: Zwischenschritte laufen mit vier Stellen.
 */
final class Money
{
    private const SCALE = 4;

    /** Normalisiert jede Eingabe auf "123.45". */
    public static function of(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        $raw = is_string($value) ? str_replace(',', '.', trim($value)) : (string) $value;

        if (! is_numeric($raw)) {
            return '0.00';
        }

        return self::round(bcadd($raw, '0', self::SCALE));
    }

    public static function add(string $a, string $b): string
    {
        return self::round(bcadd($a, $b, self::SCALE));
    }

    public static function sub(string $a, string $b): string
    {
        return self::round(bcsub($a, $b, self::SCALE));
    }

    /** Menge mal Preis -- die Menge darf drei Nachkommastellen haben. */
    public static function mul(string $amount, string|float $factor): string
    {
        return self::round(bcmul($amount, self::number($factor), self::SCALE));
    }

    /** `percent` von `amount`, z. B. der Rabatt oder die Steuer. */
    public static function percent(string $amount, string|float|null $percent): string
    {
        if ($percent === null || $percent === '') {
            return '0.00';
        }

        return self::round(bcdiv(bcmul($amount, self::number($percent), self::SCALE), '100', self::SCALE));
    }

    public static function negate(string $amount): string
    {
        return self::round(bcmul($amount, '-1', self::SCALE));
    }

    public static function isZero(string $amount): bool
    {
        return bccomp($amount, '0', 2) === 0;
    }

    /** -1, 0 oder 1, wie bccomp. */
    public static function compare(string $a, string $b): int
    {
        return bccomp($a, $b, 2);
    }

    public static function isNegative(string $amount): bool
    {
        return self::compare($amount, '0.00') < 0;
    }

    /** Kaufmännisch auf zwei Stellen. */
    public static function round(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $abs = ltrim($value, '-');
        // + 0.005, dann abschneiden: half up auf der Betragsseite.
        $rounded = bcadd($abs, '0.005', 2);

        return ($negative && ! self::isZeroLiteral($rounded) ? '-' : '').$rounded;
    }

    /** Deutsche Schreibweise für Dokumente: 1.234,56 */
    public static function format(?string $amount): string
    {
        $amount = self::of($amount);

        return number_format((float) $amount, 2, ',', '.');
    }

    private static function number(string|float|int $value): string
    {
        $raw = is_string($value) ? str_replace(',', '.', trim($value)) : (string) $value;

        return is_numeric($raw) ? $raw : '0';
    }

    private static function isZeroLiteral(string $value): bool
    {
        return bccomp($value, '0', 2) === 0;
    }
}
