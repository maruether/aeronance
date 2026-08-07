<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Access\AccessSetup;
use App\Models\User;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Filament\Pages\RepairPage;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\RepairDispatch;
use App\Modules\Warehouse\Models\Supplier;
use App\Modules\Warehouse\Permissions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RendersModulePages;
use Tests\TestCase;

/**
 * Der Reparaturversand, wie er tatsächlich bedient wird.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIESER TEST EXISTIERT WEGEN EINES FEHLERS, DEN ICH SELBST EINGEBAUT HABE.
 *
 * DispatchForRepair bekam einen neuen Parameter in der MITTE der Signatur. Die
 * Seite rief die Aktion der Reihe nach auf — damit landete die Versandnummer
 * plötzlich im Betriebs-Parameter, und der Versand wäre aus der Oberfläche
 * heraus geflogen. Kein einziger Test hat es gemerkt, weil alle anderen die
 * Aktion mit BENANNTEN Argumenten aufrufen.
 *
 * „Der Bildschirm baut" reicht dafür nicht: Der Fehler lag nicht im Aufbau,
 * sondern im Absenden. Deshalb löst dieser Test die Aktion wirklich aus.
 * ─────────────────────────────────────────────────────────────────────────────
 */
#[Group('rendering')]
final class RepairPageDispatchTest extends TestCase
{
    use RendersModulePages;

    /** @return list<string> */
    protected function modulesUnderTest(): array
    {
        return ['warehouse'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootWithModules();

        app(AccessSetup::class)->run();
    }

    /**
     * DER TEST, UM DEN ES GEHT: der Versand geht durch das Formular durch.
     */
    #[Test]
    public function the_form_actually_dispatches(): void
    {
        $teil = $this->partWithStock();
        $betrieb = Supplier::create([
            'name' => 'Lange Aviation',
            'approval_number' => 'EASA.145.1234',
            'approval_expires_at' => now()->addYear()->toDateString(),
        ]);

        $this->actingAs($this->storekeeper());

        Livewire::test(RepairPage::class)
            ->fillForm([
                'part_type_id' => $teil->getKey(),
                'quantity' => 1,
                'reason' => 'Undicht',
                'supplier_id' => $betrieb->getKey(),
                'dispatch_reference' => 'VS-2026-3',
                'dispatched_at' => now()->toDateString(),
            ])
            ->call('submit');

        $versand = RepairDispatch::query()->latest('id')->first();

        $this->assertNotNull($versand, 'Der Versand muss angelegt worden sein.');
        $this->assertSame($betrieb->id, $versand->supplier_id);

        // Und die Angaben stehen dort, wo sie hingehoeren -- nicht um einen
        // Parameter verschoben.
        $this->assertSame('Lange Aviation', $versand->shop_name);
        $this->assertSame('EASA.145.1234', $versand->shop_approval);
        $this->assertSame('VS-2026-3', $versand->dispatch_reference);
    }

    /**
     * Und eine abgelaufene Zulassung kommt aus dem Formular heraus nicht durch.
     */
    #[Test]
    public function a_lapsed_approval_is_refused_from_the_screen(): void
    {
        $teil = $this->partWithStock();
        $betrieb = Supplier::create([
            'name' => 'Alte Werft',
            'approval_number' => 'EASA.145.9999',
            'approval_expires_at' => now()->subMonth()->toDateString(),
        ]);

        $this->actingAs($this->storekeeper());

        Livewire::test(RepairPage::class)
            ->fillForm([
                'part_type_id' => $teil->getKey(),
                'quantity' => 1,
                'reason' => 'Undicht',
                'supplier_id' => $betrieb->getKey(),
                'dispatched_at' => now()->toDateString(),
            ])
            ->call('submit');

        $this->assertSame(0, RepairDispatch::query()->count());
    }

    private function partWithStock(): PartType
    {
        $teil = PartType::create([
            'name' => 'Bremszylinder',
            'classification' => PartClassification::StandardPart,
            'unit' => 'Stk',
        ]);

        app(ReceiveStock::class)->handle($teil, 5, now()->toDateString());

        return $teil->fresh();
    }

    private function storekeeper(): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ([Permissions::STOCK_VIEW, Permissions::STOCK_ISSUE, Permissions::STOCK_REPAIR] as $recht) {
            $user->givePermissionTo($recht);
        }

        return $user->fresh();
    }
}
