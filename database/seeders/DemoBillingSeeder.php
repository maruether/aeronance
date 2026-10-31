<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Billing\Actions\BuildInvoiceFromWorkOrder;
use App\Modules\Billing\Actions\InvoiceCalculator;
use App\Modules\Billing\Actions\IssueInvoice;
use App\Modules\Billing\Actions\RecordPayment;
use App\Modules\Billing\Enums\LineOrigin;
use App\Modules\Billing\Models\Customer;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\LabourRate;
use App\Modules\Billing\Models\PartPrice;
use App\Modules\Fleet\Models\Holder;
use App\Modules\TaskCards\Enums\ActivityKind;
use App\Modules\TaskCards\Models\WorkOrder;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Der Beispielbestand der Abrechnung: zwei Kunden, Preise, drei Rechnungen.
 *
 * Eine bezahlte aus dem abgeschlossenen Vorgang der Demo, eine überfällige
 * freie, ein Entwurf mit einer Position ohne Preis -- damit jeder Zustand
 * einmal vorkommt und die Sperre beim Stellen zu sehen ist. Alle Beträge
 * sind erfunden.
 */
final class DemoBillingSeeder extends Seeder
{
    /** @param  array<string, User>  $konten */
    public function run(array $konten = []): void
    {
        $leitung = $konten['werkstattleiter'] ?? null;

        if ($leitung === null) {
            return;
        }

        $this->prices();
        [$verein, $flugschule] = $this->customers();

        try {
            $this->paidInvoiceFromTheFinishedVisit($verein, $leitung);
        } catch (Throwable $e) {
            $this->command?->warn('Demo: die bezahlte Rechnung fehlt -- '.$e->getMessage());
        }

        try {
            $this->overdueInvoice($flugschule, $leitung);
        } catch (Throwable $e) {
            $this->command?->warn('Demo: die überfällige Rechnung fehlt -- '.$e->getMessage());
        }

        $this->draftWithPriceGap($flugschule);
    }

    private function prices(): void
    {
        $seit = now()->subYear()->toDateString();

        foreach ([
            ActivityKind::Inspection->value => '95.00',
            ActivityKind::Maintenance->value => '78.00',
            ActivityKind::Repair->value => '85.00',
            ActivityKind::Modification->value => '85.00',
            ActivityKind::AdCompliance->value => '78.00',
            ActivityKind::Other->value => '70.00',
        ] as $art => $satz) {
            LabourRate::create(['activity_kind' => $art, 'net_rate_per_hour' => $satz, 'valid_from' => $seit]);
        }

        foreach ([
            ['825000', 'Ölfilter Rotax 825 000', '24.90', 'Stk'],
            ['66-105', 'Bremsbelag Cleveland 66-105', '68.00', 'Stk'],
            ['Sicherungsdraht 0,8 mm', 'Sicherungsdraht 0,8 mm, Rolle', '12.50', 'Stk'],
            ['Schleppkupplung Tost E 85', 'Schleppkupplung Tost E 85', '1450.00', 'Stk'],
        ] as [$nummer, $text, $preis, $einheit]) {
            PartPrice::create([
                'part_number' => $nummer,
                'description' => $text,
                'net_price' => $preis,
                'unit' => $einheit,
                'valid_from' => $seit,
            ]);
        }
    }

    /** @return array{0: Customer, 1: Customer} */
    private function customers(): array
    {
        $halter = Holder::query()->where('type', Holder::TYPE_CLUB)->first();

        $verein = Customer::create([
            'customer_number' => 'K-1001',
            'name' => 'Segelfluggemeinschaft Musterhausen e.V.',
            'address' => "Am Flugplatz 1\n79999 Musterhausen",
            'contact_name' => 'Kassenwart',
            'email' => 'kasse@sfg-musterhausen.example',
            'payment_terms_days' => 21,
            'default_discount_percent' => '5.00',
            'discount_visible' => false,
            'holder_id' => $halter?->getKey(),
            'note' => 'Beispielkunde der Demo. Vereinsrabatt wird nicht ausgewiesen.',
        ]);

        $flugschule = Customer::create([
            'customer_number' => 'K-1002',
            'name' => 'Flugschule Oberrhein GmbH',
            'address' => "Hangar 3\n79100 Freiburg",
            'vat_id' => 'DE999999999',
            'email' => 'buchhaltung@flugschule-oberrhein.example',
            'default_discount_percent' => '10.00',
            'discount_visible' => true,
            'note' => 'Beispielkunde der Demo. Rabatt wird ausgewiesen.',
        ]);

        return [$verein, $flugschule];
    }

    private function paidInvoiceFromTheFinishedVisit(Customer $kunde, User $leitung): void
    {
        $vorgang = WorkOrder::query()
            ->whereNotNull('released_at')
            ->orderBy('released_at')
            ->first();

        if ($vorgang === null) {
            return;
        }

        $entwurf = app(BuildInvoiceFromWorkOrder::class)->handle($vorgang, $kunde, $leitung);
        $entwurf->forceFill(['note' => 'Vielen Dank für Ihren Auftrag.'])->save();

        $gestellt = app(IssueInvoice::class)->handle(
            $entwurf->fresh(['lines', 'customer']),
            $leitung,
            $vorgang->released_at?->copy()->addDays(3)->toDateString(),
        );

        app(RecordPayment::class)->handle(
            $gestellt,
            (string) $gestellt->gross_total,
            $gestellt->issued_at->copy()->addDays(12)->toDateString(),
            $leitung,
            'transfer',
            'Überweisung '.$gestellt->number,
        );
    }

    private function overdueInvoice(Customer $kunde, User $leitung): void
    {
        $entwurf = Invoice::create([
            'customer_id' => $kunde->getKey(),
            ...BuildInvoiceFromWorkOrder::snapshotOf($kunde),
            'tax_treatment' => $kunde->effectiveTaxTreatment(),
            'discount_percent' => $kunde->default_discount_percent,
            'discount_visible' => $kunde->effectiveDiscountVisible(),
            'service_to' => now()->subDays(45)->toDateString(),
            'aircraft_registration' => 'D-EICC',
            'intro' => 'Nachprüfung nach Hangarlagerung, Beispiel der Demo.',
        ]);

        $entwurf->lines()->createMany([
            ['position' => 1, 'origin' => LineOrigin::Labour, 'description' => 'Arbeitszeit Prüfung — Sichtkontrolle und Probelauf', 'quantity' => '2.500', 'unit' => 'h', 'list_unit_price' => '95.00', 'discount_percent' => '10.00', 'tax_rate' => '19'],
            ['position' => 2, 'origin' => LineOrigin::Part, 'description' => 'Ölfilter Rotax 825 000 (825000)', 'quantity' => '1.000', 'unit' => 'Stk', 'list_unit_price' => '24.90', 'discount_percent' => '10.00', 'tax_rate' => '19'],
        ]);

        app(InvoiceCalculator::class)->recalculate($entwurf->fresh());

        app(IssueInvoice::class)->handle(
            $entwurf->fresh(['lines', 'customer']),
            $leitung,
            now()->subDays(40)->toDateString(),
        );
    }

    private function draftWithPriceGap(Customer $kunde): void
    {
        $entwurf = Invoice::create([
            'customer_id' => $kunde->getKey(),
            ...BuildInvoiceFromWorkOrder::snapshotOf($kunde),
            'tax_treatment' => $kunde->effectiveTaxTreatment(),
            'discount_visible' => true,
            'intro' => 'Entwurf der Demo: Die zweite Position hat noch keinen Preis und blockiert das Stellen.',
        ]);

        $entwurf->lines()->createMany([
            ['position' => 1, 'origin' => LineOrigin::Labour, 'description' => 'Arbeitszeit Reparatur — Haubenverriegelung', 'quantity' => '1.250', 'unit' => 'h', 'list_unit_price' => '85.00', 'tax_rate' => '19'],
            ['position' => 2, 'origin' => LineOrigin::External, 'description' => 'Fremdleistung Lackiererei: Haube lackieren', 'quantity' => '1.000', 'unit' => 'pausch.', 'list_unit_price' => '0.00', 'tax_rate' => '19', 'needs_price' => true],
        ]);

        app(InvoiceCalculator::class)->recalculate($entwurf->fresh());
    }
}
