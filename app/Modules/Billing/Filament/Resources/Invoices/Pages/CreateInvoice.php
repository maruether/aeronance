<?php

declare(strict_types=1);

namespace App\Modules\Billing\Filament\Resources\Invoices\Pages;

use App\Modules\Billing\Actions\BuildInvoiceFromWorkOrder;
use App\Modules\Billing\Actions\InvoiceCalculator;
use App\Modules\Billing\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Billing\Models\Customer;
use Filament\Resources\Pages\CreateRecord;

/**
 * Eine freie Rechnung -- ohne Vorgang. Die Werft verkauft auch mal ein Teil
 * über den Tresen.
 */
final class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $customer = Customer::query()->find($data['customer_id'] ?? null);

        if ($customer !== null) {
            $data = [...$data, ...BuildInvoiceFromWorkOrder::snapshotOf($customer)];
            $data['tax_treatment'] = $data['tax_treatment'] ?? $customer->effectiveTaxTreatment()->value;
        }

        return EditInvoice::normalise($data);
    }

    protected function afterCreate(): void
    {
        EditInvoice::clearPriceGapsWherePriced($this->getRecord());
        app(InvoiceCalculator::class)->recalculate($this->getRecord()->fresh());
    }

    protected function getRedirectUrl(): string
    {
        return InvoiceResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
