<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Warehouse\Actions\IssueStock;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Filament\Resources\PartTypes\PartTypeResource;
use App\Modules\Warehouse\Filament\Resources\StockLots\StockLotResource;
use App\Modules\Warehouse\Filament\Resources\StorageLocations\StorageLocationResource;
use App\Modules\Warehouse\Filament\Resources\Suppliers\SupplierResource;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The module's screens, and above all who cannot reach them.
 *
 * Two layers are checked here, both from D3: a module that is switched off
 * contributes no screens at all, and a screen a person lacks the permission for
 * is not merely hidden but unreachable. "Hidden in the interface" counts as not
 * protected.
 */
final class WarehouseInterfaceTest extends TestCase
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
    public function a_module_that_was_off_at_boot_has_no_routes_at_all(): void
    {
        // The strongest form of the first D3 layer, and worth stating outright:
        // a disabled module is not hidden, it is absent. Its screens have no
        // route to guess at, because the panel was built without them.
        //
        // The other side of that coin is an operational property: switching a
        // module on takes effect from the NEXT request, since the panel is built
        // during boot. The module management page clears the component cache and
        // redirects, so an administrator sees the new entries straight away.
        $this->assertFalse(
            Route::has('filament.admin.resources.stock-lots.index'),
            'The warehouse was not active when this process booted, so it must have no routes.',
        );
    }

    #[Test]
    public function without_the_permission_the_screens_do_not_exist(): void
    {
        $this->actingAs($this->userWith());

        $this->assertFalse(StockLotResource::canViewAny());
        $this->assertFalse(PartTypeResource::canViewAny());
        $this->assertFalse(SupplierResource::canViewAny());
        $this->assertFalse(StorageLocationResource::canViewAny());
    }

    #[Test]
    public function with_the_permission_they_do(): void
    {
        $this->actingAs($this->userWith(
            Permissions::STOCK_VIEW,
            Permissions::PART_TYPES_MANAGE,
            Permissions::SUPPLIERS_MANAGE,
            Permissions::LOCATIONS_MANAGE,
        ));

        $this->assertTrue(StockLotResource::canViewAny());
        $this->assertTrue(PartTypeResource::canViewAny());
        $this->assertTrue(SupplierResource::canViewAny());
        $this->assertTrue(StorageLocationResource::canViewAny());
    }

    #[Test]
    public function a_deactivated_account_reaches_nothing(): void
    {
        $user = $this->userWith(Permissions::STOCK_VIEW);
        $user->update(['is_active' => false]);

        $this->actingAs($user->fresh());

        $this->assertFalse(
            $user->fresh()->canAccessPanel(Filament::getPanel('admin')),
            'A deactivated account must not reach the panel at all.',
        );
    }

    #[Test]
    public function managing_part_types_is_separate_from_handling_stock(): void
    {
        // Decision E5: booking goods in is a routine act; deciding which class a
        // part belongs to is a master-data judgement with regulatory weight.
        $storeman = $this->userWith(Permissions::STOCK_VIEW, Permissions::STOCK_RECEIVE);
        $this->actingAs($storeman);

        $this->assertTrue(StockLotResource::canViewAny());
        $this->assertFalse(
            PartTypeResource::canViewAny(),
            'Someone who books goods in must not thereby be able to create part types.',
        );
    }

    #[Test]
    public function a_lot_cannot_be_created_or_edited_through_a_form(): void
    {
        // Quantity is the sum of the movements. A form that could type over it
        // would put the ledger and the record at odds.
        $this->actingAs($this->userWith(Permissions::STOCK_VIEW));

        $this->assertFalse(StockLotResource::canCreate());
        $this->assertArrayNotHasKey('create', StockLotResource::getPages());
        $this->assertArrayNotHasKey('edit', StockLotResource::getPages());
    }

    #[Test]
    public function a_disabled_module_contributes_no_screens(): void
    {
        app(ModuleManager::class)->disable('warehouse');
        app(ModuleManager::class)->forgetCache();

        $this->assertSame([], app(ModuleManager::class)->enabledModules());

        // The permissions survive -- the assignment belongs to the role, and
        // deactivating is not uninstalling.
        $this->assertDatabaseHas('permissions', ['name' => Permissions::STOCK_VIEW]);
    }

    #[Test]
    public function enabling_the_module_created_its_permissions(): void
    {
        foreach ([
            Permissions::STOCK_VIEW,
            Permissions::STOCK_RECEIVE,
            Permissions::STOCK_ISSUE,
            Permissions::STOCK_QUARANTINE,
            Permissions::STOCK_QUARANTINE_CERTIFY,
            Permissions::STOCK_SCRAP,
            Permissions::PART_TYPES_MANAGE,
            Permissions::LOCATIONS_MANAGE,
            Permissions::SUPPLIERS_MANAGE,
        ] as $permission) {
            $this->assertDatabaseHas('permissions', ['name' => $permission]);
        }
    }

    #[Test]
    public function the_whole_chain_is_readable_from_a_lot(): void
    {
        // What the detail view puts on screen: from the certificate a batch
        // arrived on, through every piece of it that left, to the aircraft it
        // went into. The rendering itself is covered by the smoke test against
        // a running instance -- in this process the module was off at boot, so
        // the resource has no routes for the page to link back to.
        $part = PartType::create([
            'name' => 'Ölfilter',
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'St',
            'requires_form_one' => true,
        ]);

        app(ReceiveStock::class)->handle($part, 4, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-8842',
            'document_issuer' => 'Rotax Service Center',
        ]);

        $lot = StockLot::sole();

        app(IssueStock::class)->handle(
            $part->fresh(), 1, $lot,
            aircraftReference: 'D-KABC',
            workOrderReference: 'AK-2026-014',
        );

        $lot = $lot->fresh();

        // certificate -> lot -> movement -> aircraft, in one hop each
        $this->assertSame('F1-2026-8842', $lot->document_reference);
        $this->assertSame(3.0, $lot->remainingQuantity());

        $issue = $lot->movements()->where('quantity', '<', 0)->sole();
        $this->assertSame('D-KABC', $issue->aircraft_reference);
        $this->assertSame('AK-2026-014', $issue->work_order_reference);

        // ...and back again, which is the direction an audit asks in
        $this->assertSame('F1-2026-8842', $issue->lot->document_reference);
        $this->assertSame('Rotax Service Center', $issue->lot->document_issuer);
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
