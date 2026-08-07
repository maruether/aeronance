<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Warehouse\Actions\ChangeLotState;
use App\Modules\Warehouse\Actions\DispatchForRepair;
use App\Modules\Warehouse\Actions\ReceiveFromRepair;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Enums\RepairState;
use App\Modules\Warehouse\Filament\Pages\RepairPage;
use App\Modules\Warehouse\Filament\Resources\RepairDispatches\RepairDispatchResource;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\RepairDispatch;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The repair screens.
 *
 * What only exists here: the lot list offers exactly what an issue screen would
 * hide, and the return form's outcome is announced rather than left to be
 * discovered on the lot afterwards.
 */
final class RepairPageTest extends TestCase
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
    public function a_quarantined_lot_is_offered_here_unlike_on_the_issue_screen(): void
    {
        // The screen would be useless otherwise: parts that need repairing are
        // precisely the ones an issue screen refuses to show.
        $release = $this->part();
        app(ReceiveStock::class)->handle($release, 1, '2025-07-01', lotData: $this->certified());

        $lot = $this->quarantine(StockLot::sole());
        $this->assertSame(LotState::Quarantined, $lot->state);

        $this->actingAs($this->storeman());

        Livewire::test(RepairPage::class)
            ->set('data.part_type_id', $release->id)
            ->assertSchemaComponentExists(
                'stock_lot_id',
                checkComponentUsing: fn (Select $f): bool => array_key_exists($lot->id, $f->getOptions()),
            );
    }

    #[Test]
    public function a_lot_determined_beyond_repair_is_not_offered(): void
    {
        $release = $this->part();
        app(ReceiveStock::class)->handle($release, 1, '2025-07-01', lotData: $this->certified());

        $lot = StockLot::sole();
        $lot->update(['state' => LotState::Unsalvageable]);

        $this->actingAs($this->storeman());

        Livewire::test(RepairPage::class)
            ->set('data.part_type_id', $release->id)
            ->assertSchemaComponentExists(
                'stock_lot_id',
                checkComponentUsing: fn (Select $f): bool => ! array_key_exists($lot->id, $f->getOptions()),
            );
    }

    #[Test]
    public function a_dispatch_is_booked_from_the_screen(): void
    {
        $release = $this->part();
        app(ReceiveStock::class)->handle($release, 1, '2025-07-01', lotData: $this->certified());

        $this->actingAs($this->storeman());

        Livewire::test(RepairPage::class)
            ->set('data.part_type_id', $release->id)
            ->set('data.stock_lot_id', StockLot::sole()->id)
            ->set('data.quantity', '1')
            ->set('data.reason', 'Überholung fällig')
            ->set('data.shop_name', 'Musterwerft GmbH')
            ->set('data.shop_approval', 'DE.145.0123')
            ->call('submit');

        $dispatch = RepairDispatch::sole();
        $this->assertSame('Musterwerft GmbH', $dispatch->shop_name);
        $this->assertSame(RepairState::Dispatched, $dispatch->state);
        $this->assertSame(0.0, $release->fresh()->currentStock());
    }

    #[Test]
    public function a_refusal_explains_itself_and_books_nothing(): void
    {
        $release = $this->part();
        app(ReceiveStock::class)->handle($release, 1, '2025-07-01', lotData: $this->certified());

        $this->actingAs($this->storeman());

        Livewire::test(RepairPage::class)
            ->set('data.part_type_id', $release->id)
            ->set('data.stock_lot_id', StockLot::sole()->id)
            ->set('data.quantity', '9')
            ->set('data.reason', 'Zu viel')
            ->set('data.shop_name', 'Musterwerft GmbH')
            ->call('submit')
            ->assertNotified(__('warehouse.repair.notification.refused'));

        $this->assertSame(0, RepairDispatch::count());
        $this->assertSame(1.0, $release->fresh()->currentStock());
    }

    #[Test]
    public function the_list_counts_only_what_is_still_away(): void
    {
        // The badge is there to be noticed when it grows. A total that included
        // everything ever returned would stop meaning anything after a year.
        $release = $this->part();
        $user = $this->storeman();
        app(ReceiveStock::class)->handle($release, 2, '2025-07-01', lotData: $this->certified());

        $dispatch = app(DispatchForRepair::class)->handle(
            $release, 1, StockLot::sole(), $user, 'Überholung', shopName: 'Musterwerft GmbH',
        );

        $this->actingAs($user);
        $this->assertSame('1', RepairDispatchResource::getNavigationBadge());
        $this->assertNull(RepairDispatchResource::getNavigationBadgeColor(), 'Not overdue.');

        app(ReceiveFromRepair::class)->handle($dispatch, $user, lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2025-9911',
        ]);

        $this->assertNull(RepairDispatchResource::getNavigationBadge(), 'Back on the shelf.');
    }

    #[Test]
    public function an_overdue_dispatch_colours_the_badge(): void
    {
        $release = $this->part();
        $user = $this->storeman();
        app(ReceiveStock::class)->handle($release, 1, '2025-07-01', lotData: $this->certified());

        app(DispatchForRepair::class)->handle(
            $release, 1, StockLot::sole(), $user, 'Überholung',
            shopName: 'Musterwerft GmbH',
            expectedBackAt: now()->subMonth()->toDateString(),
        );

        $this->actingAs($user);
        $this->assertSame('warning', RepairDispatchResource::getNavigationBadgeColor());
    }

    #[Test]
    public function a_dispatch_cannot_be_typed_straight_into_the_list(): void
    {
        // It would claim a part left the shelf without the shelf knowing. A
        // dispatch comes into being on the repair screen, where the stock
        // booking happens with it.
        $this->actingAs($this->storeman());

        $this->assertFalse(RepairDispatchResource::canCreate());
        $this->assertTrue(RepairDispatchResource::canViewAny());

        $this->actingAs($this->userWith());
        $this->assertFalse(RepairDispatchResource::canViewAny());
    }

    #[Test]
    public function the_screens_need_the_repair_permission(): void
    {
        $this->actingAs($this->userWith(Permissions::STOCK_ISSUE));
        $this->assertFalse(RepairPage::canAccess());

        $this->actingAs($this->storeman());
        $this->assertTrue(RepairPage::canAccess());
    }

    private function part(): PartType
    {
        return PartType::create([
            'name' => 'Schleppkupplung',
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'St',
            'requires_form_one' => true,
        ]);
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function storeman(): User
    {
        return $this->userWith(Permissions::STOCK_REPAIR, Permissions::STOCK_VIEW);
    }

    /**
     * Wareneingang mit Nachweis.
     *
     * Seit „ein los geht erst dann ins lager wenn das form1 da ist" verweigert
     * ReceiveStock die Einbuchung eines Teils, das ein Form 1 verlangt, ohne
     * eines. Diese Tests sind nicht darueber -- sie brauchen nur Bestand.
     */
    private function certified(?string $reference = null): array
    {
        return [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => $reference ?? 'F1-'.mb_substr(md5((string) mt_rand()), 0, 8),
        ];
    }

    /**
     * Ein Los sperren -- ausdruecklich, nicht als Nebenwirkung.
     *
     * Frueher entstand ein gesperrtes Los beim Wareneingang ohne Papier. Das
     * geht nicht mehr: Ohne Form 1 wird gar nicht erst eingebucht ("vorher
     * liegt es im wareneingang und ist noch nicht verbucht"). Gesperrt wird
     * jetzt, was IM LAGER ist -- und dafuer braucht es einen Grund und
     * jemanden, der ihn nennt.
     */
    private function quarantine(StockLot $lot, ?string $grund = null): StockLot
    {
        Permission::findOrCreate(Permissions::STOCK_QUARANTINE, 'web');

        app(ChangeLotState::class)->handle(
            $lot,
            LotState::Quarantined,
            $grund ?? 'Verdacht auf Transportschaden',
            $this->userWith(Permissions::STOCK_QUARANTINE),
        );

        return $lot->fresh();
    }
}
