<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Billing\Enums\InvoiceState;
use App\Modules\Billing\Enums\LineOrigin;
use App\Modules\Billing\Models\Customer;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceLine;
use App\Modules\Billing\Permissions;
use App\Modules\TaskCards\Actions\IssuePartToCard;
use App\Modules\TaskCards\Models\TaskCard;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Support\PartUsed;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Aus einem Vorgang einen Rechnungsentwurf machen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER NORMALWEG. Vorgang freigegeben → „Rechnung anlegen" → hier entstehen
 * die Positionen: je Karte die Arbeitszeit (auf das Raster AUFgerundet, mal
 * Stundensatz der Tätigkeitsart), je verbautem Teil eine Zeile aus der
 * Preisliste, bei Fremdvergabe eine Zeile ohne Preis.
 *
 * FEHLT EIN PREIS, ENTSTEHT DIE POSITION TROTZDEM -- mit Preis 0 und der
 * Markierung needs_price, die das Stellen blockiert. Die Alternative, die
 * Position wegzulassen, wäre Geld, das niemand wiederholt.
 *
 * Was die Rechnung vom Vorgang braucht, wird KOPIERT: Nummer, Kennzeichen,
 * Zeitraum. Die Verknüpfung (work_order_id) ist für die Oberfläche, nicht
 * für den Beleg.
 *
 * Teile kommen über die Karte (PartUsed), nicht aus dem Lager: Dieses Modul
 * weiß nicht, dass es ein Lager gibt.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class BuildInvoiceFromWorkOrder
{
    public function __construct(
        private PriceList $prices,
        private InvoiceCalculator $calculator,
        private IssuePartToCard $parts,
    ) {}

    public function handle(WorkOrder $order, Customer $customer, User $user): Invoice
    {
        if (! $user->can(Permissions::MANAGE)) {
            throw new RuntimeException(sprintf('Drafting an invoice requires the "%s" permission.', Permissions::MANAGE));
        }

        $order->loadMissing(['taskCards.times', 'aircraft', 'externalWorkOrder']);

        if ($order->isOpen() && ! $order->isReleased()) {
            throw new RuntimeException(sprintf(
                'Visit %s is still open. An invoice is written for finished work -- close or release the visit first.',
                $order->number,
            ));
        }

        $alreadyInvoiced = Invoice::query()
            ->where('work_order_id', $order->getKey())
            ->whereIn('state', [InvoiceState::Issued->value, InvoiceState::Paid->value])
            ->where('kind', 'invoice')
            ->first();

        if ($alreadyInvoiced !== null) {
            throw new RuntimeException(sprintf(
                'Visit %s has already been invoiced as %s. A second invoice for the same work '
                .'is written by hand, on purpose, not generated.',
                $order->number,
                $alreadyInvoiced->number,
            ));
        }

        $serviceTo = $order->released_at?->copy()->startOfDay() ?? $order->closed_at?->copy() ?? now()->startOfDay();
        $serviceFrom = $order->opened_at?->copy() ?? $serviceTo;

        return DB::transaction(function () use ($order, $customer, $serviceFrom, $serviceTo): Invoice {
            $invoice = Invoice::create([
                'customer_id' => $customer->getKey(),
                ...self::snapshotOf($customer),
                'work_order_id' => $order->getKey(),
                'work_order_number' => $order->number,
                'aircraft_registration' => $order->aircraft?->registration,
                'service_from' => $serviceFrom->toDateString(),
                'service_to' => $serviceTo->toDateString(),
                'tax_treatment' => $customer->effectiveTaxTreatment(),
                'discount_percent' => $customer->default_discount_percent,
                'discount_visible' => $customer->effectiveDiscountVisible(),
                'intro' => __('billing.invoice.intro_from_visit', [
                    'number' => $order->number,
                    'title' => $order->title,
                    'aircraft' => $order->aircraft?->registration ?? '—',
                ]),
            ]);

            $position = 0;
            $visible = $customer->effectiveDiscountVisible();
            $discount = $customer->default_discount_percent;

            foreach ($order->taskCards->filter(fn (TaskCard $card): bool => $card->state->value !== 'cancelled')->sortBy('number') as $card) {
                $minutes = $card->totalMinutes();

                if ($minutes > 0) {
                    $rate = $this->prices->labourRate($card->activity_kind, $serviceTo);
                    $this->line($invoice, ++$position, [
                        'origin' => LineOrigin::Labour,
                        'source_reference' => $card->number,
                        'description' => __('billing.invoice.line.labour', [
                            'kind' => $card->activity_kind->label(),
                            'card' => $card->number,
                            'title' => $card->title,
                        ]),
                        'quantity' => self::hours($minutes),
                        'unit' => 'h',
                        'list_unit_price' => $rate?->net_rate_per_hour ?? '0.00',
                        'needs_price' => $rate === null,
                    ], $discount, $visible);
                }

                foreach ($this->parts->usedOn($card) as $part) {
                    $price = $this->prices->partPrice($part->priceKey(), $serviceTo);
                    $this->line($invoice, ++$position, [
                        'origin' => LineOrigin::Part,
                        'source_reference' => $card->number,
                        'description' => self::partDescription($part),
                        'quantity' => $part->quantity,
                        'unit' => $part->unit,
                        'list_unit_price' => $price?->net_price ?? '0.00',
                        'needs_price' => $price === null,
                    ], $discount, $visible);
                }
            }

            if ($order->externalWorkOrder !== null) {
                $this->line($invoice, ++$position, [
                    'origin' => LineOrigin::External,
                    'source_reference' => $order->externalWorkOrder->order_reference,
                    'description' => __('billing.invoice.line.external', [
                        'shop' => $order->externalWorkOrder->shop_name,
                        'scope' => $order->externalWorkOrder->scope,
                    ]),
                    'quantity' => 1,
                    'unit' => __('billing.unit.lump_sum'),
                    'list_unit_price' => '0.00',
                    'needs_price' => true,
                ], null, $visible);
            }

            return $this->calculator->recalculate($invoice->fresh());
        });
    }

    /**
     * Der Empfänger, wie er auf dem Beleg stehen wird.
     *
     * @return array<string, mixed>
     */
    public static function snapshotOf(Customer $customer): array
    {
        return [
            'customer_name' => $customer->name,
            'customer_address' => $customer->address,
            'customer_country' => $customer->country,
            'customer_vat_id' => $customer->vat_id,
            'customer_number' => $customer->customer_number,
            'buyer_reference' => $customer->buyer_reference,
        ];
    }

    /** Minuten auf das Raster aufrunden und als Stunden mit drei Stellen. */
    public static function hours(int $minutes): string
    {
        $step = PriceList::roundingStep();
        $rounded = (int) ceil($minutes / $step) * $step;

        return number_format($rounded / 60, 3, '.', '');
    }

    private static function partDescription(PartUsed $part): string
    {
        $text = $part->name;

        if ($part->partNumber !== null && $part->partNumber !== '') {
            $text .= ' ('.$part->partNumber.')';
        }

        if ($part->lotNumber !== null && $part->lotNumber !== '') {
            $text .= ', '.__('billing.invoice.line.lot', ['lot' => $part->lotNumber]);
        }

        return $text;
    }

    /** @param  array<string, mixed>  $attributes */
    private function line(Invoice $invoice, int $position, array $attributes, ?string $discount, bool $visible): InvoiceLine
    {
        return $invoice->lines()->create([
            'position' => $position,
            'discount_percent' => $discount,
            'discount_visible' => $visible,
            'tax_rate' => PriceList::defaultTaxRate(),
            ...$attributes,
        ]);
    }
}
