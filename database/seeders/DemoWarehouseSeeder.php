<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\PurchaseOrder;
use App\Modules\Warehouse\Models\PurchaseOrderLine;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StorageCompartment;
use App\Modules\Warehouse\Models\StorageLocation;
use App\Modules\Warehouse\Models\Supplier;
use Illuminate\Database\Seeder;

/**
 * Das Lager der Demo.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER BESTAND SOLL NICHT NUR DA SEIN, SONDERN ETWAS ZEIGEN: ein Teil unter dem
 * Mindestbestand, ein Los mit ablaufender Haltbarkeit, ein losgeführtes Teil
 * mit Form-1-Pflicht, Schrauben als Sammelbestand ohne Los -- und eine
 * überfällige Bestellung, damit der Erinnerer einen Grund hat.
 *
 * Eingebucht wird über die Aktion und nicht über Model::create: Nur so
 * entstehen Bewegungen, und der Bestand IST die Summe der Bewegungen. Ein von
 * Hand gesetzter Bestand ohne Bewegungen wäre ein Zustand, den es im Betrieb
 * nicht gibt.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class DemoWarehouseSeeder extends Seeder
{
    /** @param  array<string, User>  $konten */
    public function run(array $konten = []): void
    {
        $lagerist = $konten['werkstattleiter'] ?? null;

        $lager = StorageLocation::create([
            'name' => 'Werkstatt',
            'description' => 'Hauptlager in der Werkstatt',
        ]);

        $quarantaene = StorageLocation::create([
            'name' => 'Quarantäne',
            'description' => 'Gesperrte Teile — nicht einbauen',
            'is_quarantine' => true,
        ]);

        $regal = StorageCompartment::create([
            'storage_location_id' => $lager->id,
            'name' => 'A1',
            'description' => 'Kleinteile',
        ]);

        StorageCompartment::create([
            'storage_location_id' => $lager->id,
            'name' => 'B2',
            'description' => 'Filter und Betriebsstoffe',
        ]);

        StorageCompartment::create([
            'storage_location_id' => $quarantaene->id,
            'name' => 'Q1',
            'description' => 'Gesperrt',
        ]);

        $lieferant = Supplier::create([
            'name' => 'Beispiel Luftfahrtbedarf GmbH',
            'approval_number' => 'DE.145.DEMO',
            'approval_scope' => 'Vertrieb von Luftfahrtgerät (Beispiel)',
            'approval_expires_at' => now()->addYears(2)->toDateString(),
            'contact' => 'vertrieb@beispiel.example',
            'description' => 'Beispiellieferant der Demo.',
        ]);

        $zweiter = Supplier::create([
            'name' => 'Musterhausener Schraubenhandel',
            'contact' => 'info@schrauben.example',
            'description' => 'Beispiellieferant der Demo — ohne Zulassung, wie es bei '
                .'Standard Parts auch sein darf.',
        ]);

        /*
         * Vier Teile, vier verschiedene Aussagen:
         *   Ölfilter        -- Haltbarkeit, also losgeführt
         *   Bremsbelag      -- Mindestbestand unterschritten
         *   Sicherungsdraht -- Verbrauchsmaterial, Sammelbestand
         *   Schleppkupplung -- Form-1-Pflicht und Seriennummer
         */
        $filter = PartType::create([
            'name' => 'Ölfilter Rotax 825 000',
            'description' => 'Beispielteil der Demo.',
            'classification' => PartClassification::Component,
            'supplier_id' => $lieferant->id,
            'storage_compartment_id' => $regal->id,
            'order_code' => '825000',
            'unit_of_measure' => 'Stk',
            'minimum_stock' => 2,
            'shelf_life_days' => 1095,
            'net_purchase_price' => 18.90,
        ]);

        $belag = PartType::create([
            'name' => 'Bremsbelag Cleveland 66-105',
            'description' => 'Beispielteil der Demo.',
            'classification' => PartClassification::Component,
            'supplier_id' => $lieferant->id,
            'storage_compartment_id' => $regal->id,
            'order_code' => '66-105',
            'unit_of_measure' => 'Stk',
            'minimum_stock' => 4,
            'net_purchase_price' => 24.50,
        ]);

        $draht = PartType::create([
            'name' => 'Sicherungsdraht 0,8 mm',
            'description' => 'Beispielteil der Demo.',
            'classification' => PartClassification::ConsumableMaterial,
            'supplier_id' => $zweiter->id,
            'storage_compartment_id' => $regal->id,
            'unit_of_measure' => 'm',
            'minimum_stock' => 20,
            'net_purchase_price' => 0.35,
        ]);

        $kupplung = PartType::create([
            'name' => 'Schleppkupplung Tost E 85',
            'description' => 'Beispielteil der Demo.',
            'classification' => PartClassification::Component,
            'supplier_id' => $lieferant->id,
            'storage_compartment_id' => $regal->id,
            'unit_of_measure' => 'Stk',
            'minimum_stock' => 0,
            'requires_form_one' => true,
            'serial_tracked' => true,
            'net_purchase_price' => 890.00,
        ]);

        $einbuchen = app(ReceiveStock::class);

        $einbuchen->handle(
            partType: $filter,
            quantity: 4,
            receivedAt: now()->subMonths(4)->toDateString(),
            user: $lagerist,
            lotData: ['batch_number' => 'CH-2026-118', 'expires_at' => now()->addMonths(2)->toDateString()],
        );

        // Unter dem Mindestbestand: Das soll man auf der Startseite sehen.
        $einbuchen->handle(
            partType: $belag,
            quantity: 1,
            receivedAt: now()->subMonths(2)->toDateString(),
            user: $lagerist,
        );

        $einbuchen->handle(
            partType: $draht,
            quantity: 50,
            receivedAt: now()->subMonths(6)->toDateString(),
            user: $lagerist,
        );

        /*
         * MIT NACHWEIS, sonst geht es gar nicht erst ins Lager -- und genau
         * das soll man in der Demo sehen: Ein Form-1-pflichtiges Teil ohne
         * Nachweis bleibt im Wareneingang. Die Beispiel-Form-1 haengt als
         * Datei am Los (DemoDocuments), damit auch der Weg zum Dokument
         * vorfuehrbar ist -- hochladen kann in der Demo niemand.
         */
        $bewegung = $einbuchen->handle(
            partType: $kupplung,
            quantity: 1,
            receivedAt: now()->subYear()->toDateString(),
            user: $lagerist,
            lotData: [
                'serial_number' => 'E85-DEMO-4711',
                'document_type' => StockLot::DOCUMENT_FORM_ONE,
                'document_reference' => 'EASA Form 1 DEMO-2025-0042',
            ],
        );

        DemoDocuments::attachFormOne($bewegung->lot);

        $this->overdueOrder($lieferant, $belag, $konten);
    }

    /**
     * Eine Bestellung, deren zugesagtes Lieferdatum verstrichen ist.
     *
     * Der Erinnerer hat damit etwas zu erinnern -- und weil in der Demo keine
     * Mail hinausgeht, sieht man ihn genau dort, wo er auch im Betrieb steht:
     * in der Liste der offenen Bestellungen.
     *
     * @param  array<string, User>  $konten
     */
    private function overdueOrder(Supplier $lieferant, PartType $teil, array $konten): void
    {
        $bestellung = PurchaseOrder::create([
            'order_number' => 'B-'.now()->format('Y').'-001',
            'supplier_id' => $lieferant->id,
            'ordered_at' => now()->subWeeks(5)->toDateString(),
            'expected_at' => now()->subWeeks(1)->toDateString(),
            'created_by_id' => $konten['werkstattleiter']->id ?? null,
            'note' => 'Beispielbestellung der Demo.',
        ]);

        PurchaseOrderLine::create([
            'purchase_order_id' => $bestellung->id,
            'part_type_id' => $teil->id,
            'quantity_ordered' => 8,
            'note' => 'Bremsbeläge für die Nachprüfung',
        ]);

        $bestellung->refreshState();
    }
}
