<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Modules\Billing\Models\LabourRate;
use App\Modules\Billing\Models\PartPrice;
use App\Modules\TaskCards\Enums\ActivityKind;
use Illuminate\Support\Carbon;

/**
 * Welcher Preis an einem Tag gilt.
 *
 * Immer gegen das LEISTUNGSDATUM gefragt, nicht gegen heute: Eine Rechnung
 * für Arbeit im März trägt die Preise vom März, auch wenn sie im Mai
 * geschrieben wird.
 */
final class PriceList
{
    public function partPrice(string $key, Carbon $on): ?PartPrice
    {
        return PartPrice::query()->validFor($key, $on)->first();
    }

    public function labourRate(ActivityKind $kind, Carbon $on): ?LabourRate
    {
        return LabourRate::query()->validFor($kind, $on)->first();
    }

    public static function defaultTaxRate(): string
    {
        $rate = str_replace(',', '.', (string) config('aeronance.billing.tax_rate', '19'));

        return is_numeric($rate) ? $rate : '19';
    }

    public static function roundingStep(): int
    {
        return max(1, (int) config('aeronance.billing.labour_rounding_minutes', 15));
    }
}
