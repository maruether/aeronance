<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Filament\Pages\StocktakePage;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StockMovement;
use App\Modules\Warehouse\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Entering a stocktake through the screen.
 *
 * The behaviour worth testing here is the one that only exists in the screen:
 * that a surplus and a shortfall are different fields, and that entering a
 * surplus does not quietly attach a part to somebody's certificate.
 */
final class StocktakePageTest extends TestCase
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
    public function a_bulk_difference_is_booked(): void
    {
        $nuts = $this->bulkPart();
        app(ReceiveStock::class)->handle($nuts, 500, '2025-07-01');

        $this->actingAs($this->userWith(Permissions::STOCK_RECEIVE));

        Livewire::test(StocktakePage::class)
            ->set('countedAt', now()->toDateString())
            ->set('bulkCounts.'.$nuts->id, '480')
            ->call('submit');

        $this->assertSame(480.0, $nuts->fresh()->currentStock());
    }

    #[Test]
    public function a_lot_shortfall_is_booked(): void
    {
        $filters = $this->lotPart();
        app(ReceiveStock::class)->handle($filters, 4, '2025-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2025-8842',
        ]);

        $lot = StockLot::sole();
        $this->actingAs($this->userWith(Permissions::STOCK_RECEIVE));

        Livewire::test(StocktakePage::class)
            ->set('countedAt', now()->toDateString())
            ->set('lotCounts.'.$lot->id, '3')
            ->call('submit');

        $this->assertSame(3.0, $lot->fresh()->remainingQuantity());
    }

    #[Test]
    public function a_surplus_opens_its_own_lot_and_leaves_the_certified_one_alone(): void
    {
        // the rule, exercised through the screen: the extra filter must not
        // end up covered by F1-2025-8842.
        $filters = $this->lotPart();
        app(ReceiveStock::class)->handle($filters, 4, '2025-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2025-8842',
        ]);

        $certified = StockLot::sole();
        $this->actingAs($this->userWith(Permissions::STOCK_RECEIVE));

        Livewire::test(StocktakePage::class)
            ->set('countedAt', now()->toDateString())
            // Seit dem Feldtest EINE Fundzeile am Ende mit Auswahl statt eines
            // Feldes je Kachel: "es wird schnell unübersichtlich wenn das bei
            // jedem möglichen teil auftaucht."
            ->set('foundRows.0.part_type_id', $filters->id)
            ->set('foundRows.0.quantity', '1')
            ->set('foundRows.0.note', 'Im Regal daneben gefunden')
            ->call('submit');

        $this->assertSame(4.0, $certified->fresh()->remainingQuantity(), 'The certified lot is untouched.');
        $this->assertSame(2, StockLot::count());

        $found = StockLot::where('id', '!=', $certified->id)->sole();
        $this->assertSame(StockLot::DOCUMENT_NONE, $found->document_type);
        $this->assertSame(LotState::Quarantined, $found->state);
        $this->assertNull($found->expires_at);
    }

    #[Test]
    public function the_screen_offers_a_surplus_field_rather_than_a_bigger_number(): void
    {
        // The separation is the safeguard: there is no box on a lot that would
        // accept more than the lot holds.
        $filters = $this->lotPart();
        app(ReceiveStock::class)->handle($filters, 4, '2025-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2025-8842',
        ]);

        $this->actingAs($this->userWith(Permissions::STOCK_RECEIVE));

        Livewire::test(StocktakePage::class)
            ->assertSee(__('warehouse.stocktake.found_label'))
            ->assertSee('max="4"', false);
    }

    #[Test]
    public function empty_fields_book_nothing(): void
    {
        $nuts = $this->bulkPart();
        app(ReceiveStock::class)->handle($nuts, 500, '2025-07-01');

        $this->actingAs($this->userWith(Permissions::STOCK_RECEIVE));

        Livewire::test(StocktakePage::class)->call('submit');

        $this->assertSame(500.0, $nuts->fresh()->currentStock());
        $this->assertSame(1, StockMovement::count());
    }

    #[Test]
    public function it_needs_the_receive_permission(): void
    {
        $this->actingAs($this->userWith(Permissions::STOCK_VIEW));

        $this->assertFalse(StocktakePage::canAccess());
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
}
