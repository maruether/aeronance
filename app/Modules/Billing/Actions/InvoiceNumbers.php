<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Models\InvoiceNumberRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Vergibt die nächste Nummer eines Kreises: RE-202610-0001.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * JAHR UND MONAT STEHEN IN DER NUMMER, DER ZÄHLER LÄUFT JE JAHR (Entscheidung
 * 2026-10-30). Ein Prüfer will innerhalb eines Jahres eine lückenlos
 * aufsteigende Folge sehen; der Monat ist Orientierung für den Menschen, der
 * eine Nummer liest. Beides zusammen: RE-202601-0001, RE-202602-0017.
 *
 * NUR INNERHALB EINER TRANSAKTION aufrufen. Die Sperre auf der Zählerzeile
 * hält nur bis zum Commit; ohne Transaktion lesen zwei gleichzeitige Aufrufe
 * denselben Stand und verbrauchen dieselbe Nummer. Der UNIQUE-Index auf
 * invoices.number fängt das, aber ein Fehler beim Stellen ist der falsche
 * Moment dafür.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class InvoiceNumbers
{
    public function next(InvoiceKind $kind, Carbon $on): string
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('InvoiceNumbers::next() must run inside a transaction -- the lock needs one.');
        }

        $year = (int) $on->format('Y');

        $range = InvoiceNumberRange::query()
            ->where('kind', $kind->value)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($range === null) {
            InvoiceNumberRange::query()->firstOrCreate(
                ['kind' => $kind->value, 'year' => $year],
                ['last_number' => 0],
            );

            $range = InvoiceNumberRange::query()
                ->where('kind', $kind->value)
                ->where('year', $year)
                ->lockForUpdate()
                ->firstOrFail();
        }

        $next = $range->last_number + 1;
        $range->update(['last_number' => $next]);

        return self::format($kind->prefix(), $on, $next);
    }

    public static function format(string $prefix, Carbon $on, int $number): string
    {
        return sprintf('%s-%s-%04d', $prefix, $on->format('Ym'), $number);
    }
}
