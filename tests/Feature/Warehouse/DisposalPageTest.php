<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Access\AccessSetup;
use App\Core\Models\Qualification;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Warehouse\Actions\ChangeLotState;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Filament\Pages\DisposalPage;
use App\Modules\Warehouse\Filament\Resources\StockMovements\StockMovementResource;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StockMovement;
use App\Modules\Warehouse\Permissions;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The disposal screen and the ledger.
 *
 * What only exists here: expired stock is put in front of the person rather
 * than waited for, and the lot list deliberately offers what an issue screen
 * hides.
 */
final class DisposalPageTest extends TestCase
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
    public function expired_stock_is_put_in_front_of_the_person(): void
    {
        $resin = $this->resin();
        app(ReceiveStock::class)->handle($resin, 5, now()->subYears(2)->toDateString(), lotData: $this->certified());

        $this->actingAs($this->scrapper());

        $page = Livewire::test(DisposalPage::class);

        $this->assertCount(1, $page->instance()->expiredLots());
    }

    #[Test]
    public function picking_an_expired_lot_fills_the_form(): void
    {
        // The commonest case in two clicks, with the reason already written.
        $resin = $this->resin();
        app(ReceiveStock::class)->handle($resin, 5, now()->subYears(2)->toDateString(), lotData: $this->certified());
        $lot = StockLot::sole();

        $this->actingAs($this->scrapper());

        $page = Livewire::test(DisposalPage::class)->call('pick', $lot->id);

        // Filament renders select state as a string; the identity is what matters.
        $this->assertSame($lot->id, (int) $page->get('data.stock_lot_id'));
        $this->assertSame(5.0, (float) $page->get('data.quantity'));
        $this->assertStringContainsString('Verfallsdatum', (string) $page->get('data.reason'));
    }

    #[Test]
    public function a_quarantined_lot_is_offered_here(): void
    {
        // Most of what gets destroyed. A screen that hid them would be a
        // disposal screen for usable parts.
        $filters = $this->lotPart();
        app(ReceiveStock::class)->handle($filters, 4, '2025-07-01', lotData: $this->certified());

        $lot = $this->quarantine(StockLot::sole());
        $this->assertSame(LotState::Quarantined, $lot->state);

        $this->actingAs($this->scrapper());

        Livewire::test(DisposalPage::class)
            ->set('data.part_type_id', $filters->id)
            ->assertSchemaComponentExists(
                'stock_lot_id',
                checkComponentUsing: fn (Select $f): bool => array_key_exists($lot->id, $f->getOptions()),
            );
    }

    #[Test]
    public function a_disposal_is_booked_from_the_screen(): void
    {
        $nuts = $this->bulkPart();
        app(ReceiveStock::class)->handle($nuts, 500, '2025-07-01', lotData: $this->certified());

        $this->actingAs($this->scrapper());

        Livewire::test(DisposalPage::class)
            ->set('data.part_type_id', $nuts->id)
            ->set('data.quantity', '120')
            ->set('data.reason', 'Korrodiert')
            ->call('submit')
            ->assertNotified(__('warehouse.disposal.notification.done'));

        $this->assertSame(380.0, $nuts->fresh()->currentStock());
    }

    #[Test]
    public function a_refusal_explains_itself_and_books_nothing(): void
    {
        $nuts = $this->bulkPart();
        app(ReceiveStock::class)->handle($nuts, 10, '2025-07-01', lotData: $this->certified());

        $this->actingAs($this->scrapper());

        Livewire::test(DisposalPage::class)
            ->set('data.part_type_id', $nuts->id)
            ->set('data.quantity', '99')
            ->set('data.reason', 'Zu viel')
            ->call('submit')
            ->assertNotified(__('warehouse.disposal.notification.refused'));

        $this->assertSame(10.0, $nuts->fresh()->currentStock());
    }

    #[Test]
    public function the_screens_are_gated(): void
    {
        $this->actingAs($this->userWith(Permissions::STOCK_ISSUE));
        $this->assertFalse(DisposalPage::canAccess());
        $this->assertFalse(StockMovementResource::canViewAny());

        $this->actingAs($this->scrapper());
        $this->assertTrue(DisposalPage::canAccess());

        $this->actingAs($this->userWith(Permissions::STOCK_VIEW));
        $this->assertTrue(StockMovementResource::canViewAny());
    }

    #[Test]
    public function the_ledger_cannot_be_written_into_by_hand(): void
    {
        // A movement comes into being by booking something. Creating one here
        // would be stock appearing without an event.
        $this->actingAs($this->userWith(Permissions::STOCK_VIEW));

        $this->assertFalse(StockMovementResource::canCreate());
        $this->assertFalse(StockMovementResource::canEdit(new StockMovement));
        $this->assertFalse(StockMovementResource::canDelete(new StockMovement));
    }

    private function resin(): PartType
    {
        return PartType::create([
            'name' => 'Harz L285',
            'classification' => PartClassification::ConsumableMaterial,
            'unit_of_measure' => 'kg',
            'shelf_life_days' => 365,
        ]);
    }

    private function bulkPart(): PartType
    {
        return PartType::create([
            'name' => 'Mutter M6',
            'classification' => PartClassification::StandardPart,
            'unit_of_measure' => 'St',
        ]);
    }

    private function lotPart(): PartType
    {
        return PartType::create([
            'name' => 'Ölfilter Rotax 912',
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

    private function scrapper(): User
    {
        $user = $this->userWith(Permissions::STOCK_SCRAP, Permissions::STOCK_VIEW);

        Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.00000',
            'category' => 'B1',
            'valid_from' => now()->subYear()->toDateString(),
        ]);

        return $user->fresh();
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
