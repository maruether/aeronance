<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Access\AccessSetup;
use App\Core\Models\Qualification;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Warehouse\Enums\LifeLimitType;
use App\Modules\Warehouse\Enums\LotOrigin;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Filament\Pages\IssueStockPage;
use App\Modules\Warehouse\Filament\Pages\RemovalPage;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Booking a removal through the screen.
 *
 * What only exists here rather than in the action: the two things people are
 * surprised by afterwards -- that a part of undetermined condition lands in
 * quarantine, and that without a Form 1 it may only go back into the aircraft
 * it came from -- are said on screen and stay there rather than sliding away.
 */
final class RemovalPageTest extends TestCase
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
    public function a_removal_is_booked_from_the_screen(): void
    {
        $instrument = $this->part(serialTracked: true);
        $this->actingAs($this->qualifiedMechanic());

        Livewire::test(RemovalPage::class)
            ->set('data.part_type_id', $instrument->id)
            ->set('data.quantity', '1')
            ->set('data.serial_number', 'VAR-8891')
            ->set('data.aircraft', 'D-KABC')
            ->set('data.aircraft_type', 'ASK 21')
            ->set('data.reason', 'Umbau Instrumentenbrett')
            ->set('data.determined_serviceable', true)
            ->call('submit')
            ->assertHasNoFormErrors();

        $lot = StockLot::sole();
        $this->assertSame(LotOrigin::Removal, $lot->origin);
        $this->assertSame('D-KABC', $lot->removed_from_aircraft);
        $this->assertSame('VAR-8891', $lot->serial_number);
        $this->assertSame(LotState::Serviceable, $lot->state);
    }

    #[Test]
    public function the_restriction_is_stated_on_screen(): void
    {
        // Not a footnote in the documentation: the person who just booked it in
        // is the one who will try to fit it elsewhere.
        $instrument = $this->part();
        $this->actingAs($this->qualifiedMechanic());

        Livewire::test(RemovalPage::class)
            ->set('data.part_type_id', $instrument->id)
            ->set('data.quantity', '1')
            ->set('data.aircraft', 'D-KABC')
            ->set('data.reason', 'Umbau')
            ->set('data.determined_serviceable', true)
            ->call('submit')
            ->assertNotified(__('warehouse.removal.notification.restricted', ['aircraft' => 'D-KABC']));
    }

    #[Test]
    public function without_a_determination_it_says_so(): void
    {
        $instrument = $this->part();
        $this->actingAs($this->userWith(Permissions::STOCK_RECEIVE));

        Livewire::test(RemovalPage::class)
            ->set('data.part_type_id', $instrument->id)
            ->set('data.quantity', '1')
            ->set('data.aircraft', 'D-KABC')
            ->set('data.reason', 'Ausgebaut, noch nicht geprüft')
            ->call('submit')
            ->assertNotified(__('warehouse.removal.notification.quarantined'));

        $this->assertSame(LotState::Quarantined, StockLot::sole()->state);
    }

    #[Test]
    public function a_refusal_explains_itself_and_books_nothing(): void
    {
        // The message says why -- "on a replacement interval, scrap it instead"
        // -- rather than that something went wrong.
        $plugs = $this->part(lifeLimit: LifeLimitType::Tbr);
        $this->actingAs($this->qualifiedMechanic());

        Livewire::test(RemovalPage::class)
            ->set('data.part_type_id', $plugs->id)
            ->set('data.quantity', '4')
            ->set('data.aircraft', 'D-KABC')
            ->set('data.reason', 'Zündkerzenwechsel')
            ->set('data.determined_serviceable', true)
            ->call('submit')
            ->assertNotified(__('warehouse.removal.notification.refused'));

        $this->assertSame(0, StockLot::count());
    }

    #[Test]
    public function a_restricted_lot_is_not_offered_for_another_aircraft(): void
    {
        // Being refused at the moment of booking is correct but late. By then
        // the lot has been picked, the quantity typed, and the wall comes as a
        // surprise -- so the lot is kept out of the list to begin with.
        $instrument = $this->part();
        $this->actingAs($this->qualifiedMechanic());

        Livewire::test(RemovalPage::class)
            ->set('data.part_type_id', $instrument->id)
            ->set('data.quantity', '1')
            ->set('data.aircraft', 'D-KABC')
            ->set('data.reason', 'Umbau')
            ->set('data.determined_serviceable', true)
            ->call('submit');

        $lot = StockLot::sole();

        Livewire::test(IssueStockPage::class)
            ->set('data.aircraft_reference', 'D-KXYZ')
            ->set('data.part_type_id', $instrument->id)
            ->assertSchemaComponentExists(
                'stock_lot_id',
                checkComponentUsing: fn (Select $field): bool => ! array_key_exists($lot->id, $field->getOptions()),
            )
            ->set('data.aircraft_reference', 'D-KABC')
            ->assertSchemaComponentExists(
                'stock_lot_id',
                checkComponentUsing: fn (Select $field): bool => array_key_exists($lot->id, $field->getOptions()),
            );
    }

    #[Test]
    public function entering_the_aircraft_late_drops_a_lot_that_no_longer_fits(): void
    {
        // The destination field sits below the lot field, so this order of
        // events is the normal one, not an edge case.
        $instrument = $this->part();
        $this->actingAs($this->qualifiedMechanic());

        Livewire::test(RemovalPage::class)
            ->set('data.part_type_id', $instrument->id)
            ->set('data.quantity', '1')
            ->set('data.aircraft', 'D-KABC')
            ->set('data.reason', 'Umbau')
            ->set('data.determined_serviceable', true)
            ->call('submit');

        $lot = StockLot::sole();

        $page = Livewire::test(IssueStockPage::class)
            ->set('data.part_type_id', $instrument->id)
            ->set('data.stock_lot_id', $lot->id)
            ->set('data.aircraft_reference', 'D-KXYZ');

        $page->assertNotified(__('warehouse.issue.notification.lot_dropped', ['lot' => $lot->lot_number]));
        $this->assertNull($page->get('data.stock_lot_id'));
    }

    #[Test]
    public function the_screen_needs_the_goods_in_permission(): void
    {
        $this->actingAs($this->userWith(Permissions::STOCK_VIEW));
        $this->assertFalse(RemovalPage::canAccess());

        $this->actingAs($this->userWith(Permissions::STOCK_RECEIVE));
        $this->assertTrue(RemovalPage::canAccess());
    }

    private function part(
        ?LifeLimitType $lifeLimit = null,
        bool $serialTracked = false,
    ): PartType {
        return PartType::create([
            'name' => 'Variometer',
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'St',
            'serial_tracked' => $serialTracked,
            'life_limit_type' => $lifeLimit ?? LifeLimitType::None,
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

    private function qualifiedMechanic(): User
    {
        $user = $this->userWith(
            Permissions::STOCK_RECEIVE,
            Permissions::STOCK_ISSUE,
            Permissions::STOCK_QUARANTINE_CERTIFY,
        );

        Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.00000',
            'category' => 'B1',
            'valid_from' => now()->subYear()->toDateString(),
        ]);

        return $user->fresh();
    }
}
