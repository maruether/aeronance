<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StorageCompartment;
use App\Modules\Warehouse\Models\StorageLocation;
use App\Modules\Warehouse\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The counting list -- the sheet somebody walks the store with.
 */
final class CountingListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();
        app(ModuleManager::class)->enable('warehouse');
        app(ModuleManager::class)->forgetCache();
    }

    #[Test]
    public function it_lists_parts_with_the_recorded_quantity(): void
    {
        $part = $this->partInStore('Mutter M6', 500);

        $this->actingAs($this->userWith(Permissions::STOCK_REPORT))
            ->get(route('warehouse.counting-list'))
            ->assertSuccessful()
            ->assertSee('Mutter M6')
            ->assertSee('500')
            ->assertSee('Werkstatt')
            ->assertSee('Regal A');
    }

    #[Test]
    public function lot_tracked_parts_are_broken_down_by_lot(): void
    {
        // Counted per lot, because a surplus later must NOT be booked onto an
        // existing lot -- it does not belong to that lot's certificate.
        $part = PartType::create([
            'name' => 'Ölfilter Rotax 912',
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'St',
            'requires_form_one' => true,
        ]);

        app(ReceiveStock::class)->handle($part, 4, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-8842',
        ]);
        app(ReceiveStock::class)->handle($part, 2, '2026-07-15', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-9001',
        ]);

        $response = $this->actingAs($this->userWith(Permissions::STOCK_REPORT))
            ->get(route('warehouse.counting-list'));

        $response->assertSuccessful()
            ->assertSee('F1-2026-8842')
            ->assertSee('F1-2026-9001');
    }

    #[Test]
    public function it_can_be_limited_to_one_location(): void
    {
        $this->partInStore('Mutter M6', 500, 'Werkstatt');
        $other = $this->partInStore('Schraube M8', 200, 'Halle');

        $werkstatt = StorageLocation::where('name', 'Werkstatt')->sole();

        $this->actingAs($this->userWith(Permissions::STOCK_REPORT))
            ->get(route('warehouse.counting-list', ['location' => $werkstatt->id]))
            ->assertSuccessful()
            ->assertSee('Mutter M6')
            ->assertDontSee('Schraube M8');
    }

    #[Test]
    public function it_needs_the_report_permission(): void
    {
        $this->partInStore('Mutter M6', 500);

        $this->actingAs($this->userWith(Permissions::STOCK_VIEW))
            ->get(route('warehouse.counting-list'))
            ->assertForbidden();
    }

    #[Test]
    public function nothing_is_printed_while_the_module_is_off(): void
    {
        app(ModuleManager::class)->disable('warehouse');
        app(ModuleManager::class)->forgetCache();

        $this->actingAs($this->userWith(Permissions::STOCK_REPORT))
            ->get(route('warehouse.counting-list'))
            ->assertNotFound();
    }

    private function partInStore(string $name, float $quantity, string $location = 'Werkstatt'): PartType
    {
        $storageLocation = StorageLocation::firstOrCreate(['name' => $location]);
        $compartment = StorageCompartment::firstOrCreate([
            'storage_location_id' => $storageLocation->id,
            'name' => 'Regal A',
        ]);

        $part = PartType::create([
            'name' => $name,
            'classification' => PartClassification::StandardPart,
            'unit_of_measure' => 'St',
            'storage_compartment_id' => $compartment->id,
        ]);

        app(ReceiveStock::class)->handle($part, $quantity, '2026-07-01');

        return $part;
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }
}
