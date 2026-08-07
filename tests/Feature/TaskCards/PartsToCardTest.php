<?php

declare(strict_types=1);

namespace Tests\Feature\TaskCards;

use App\Core\Models\Qualification;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Actions\CertifyTaskCard;
use App\Modules\TaskCards\Actions\IssuePartToCard;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Enums\ParticipationKind;
use App\Modules\TaskCards\Models\TaskCard;
use App\Modules\TaskCards\Permissions;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Actions\RemovePartFromAircraft;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions as WarehousePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Taking a part out of the store for a card.
 *
 * CLAUDE.md: "Teileentnahme nur, wenn das Lagermodul aktiv ist." The point of
 * these tests is not that it books -- it is that every rule the warehouse
 * already enforces still applies on this path, because this is exactly where
 * somebody would be tempted to restate them.
 */
final class PartsToCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            Permissions::CARDS_WORK,
            Permissions::CARDS_CERTIFY,
            Permissions::WORK_ORDERS_MANAGE,
            WarehousePermissions::STOCK_ISSUE,
            WarehousePermissions::STOCK_QUARANTINE_CERTIFY,
        ] as $p) {
            Permission::findOrCreate($p, 'web');
        }

        app(ModuleManager::class)->enable('warehouse');
        app(ModuleManager::class)->enable('taskcards');
        app(ModuleManager::class)->forgetCache();
    }

    #[Test]
    public function a_part_goes_from_the_store_to_the_card(): void
    {
        $filters = $this->part();
        app(ReceiveStock::class)->handle($filters, 4, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-1',
        ]);

        $card = $this->card();

        $movement = app(IssuePartToCard::class)->handle(
            $card, $filters->fresh(), 1, $this->storeman(), StockLot::sole(),
        );

        $this->assertSame($card->number, $movement->work_order_reference);
        $this->assertSame('D-KABC', $movement->aircraft_reference);
        $this->assertSame(3.0, $filters->fresh()->currentStock());
    }

    #[Test]
    public function what_went_to_a_card_is_read_back_out_of_the_ledger(): void
    {
        // Not kept a second time here. The warehouse owns that record, and a
        // copy would be a second truth that drifts.
        $filters = $this->part();
        app(ReceiveStock::class)->handle($filters, 4, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-1',
        ]);

        $card = $this->card();
        app(IssuePartToCard::class)->handle($card, $filters->fresh(), 2, $this->storeman(), StockLot::sole());

        $issued = app(IssuePartToCard::class)->issuedTo($card);

        $this->assertCount(1, $issued);
        $this->assertSame(-2.0, (float) $issued->first()->quantity);
    }

    #[Test]
    public function the_warehouses_aircraft_rule_still_bites_here(): void
    {
        // The rule easiest to lose on this path: a removal lot without a Form 1
        // goes back only into the aircraft it came out of. Passing the card's
        // registration is the difference between that working and being quietly
        // bypassed.
        $instrument = $this->part('Variometer');
        $mechanic = $this->qualifiedMechanic();

        $removed = app(RemovePartFromAircraft::class)->handle(
            $instrument, 1, 'D-KXYZ', $mechanic, 'Umbau', determinedServiceable: true,
        );

        $card = $this->card();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/may only go back into that aircraft/');

        app(IssuePartToCard::class)->handle(
            $card, $instrument->fresh(), 1, $mechanic, $removed->fresh(),
        );
    }

    #[Test]
    public function and_allows_it_for_the_right_aircraft(): void
    {
        $instrument = $this->part('Variometer');
        $mechanic = $this->qualifiedMechanic();

        $removed = app(RemovePartFromAircraft::class)->handle(
            $instrument, 1, 'D-KABC', $mechanic, 'Umbau', determinedServiceable: true,
        );

        $card = $this->card();

        $movement = app(IssuePartToCard::class)->handle(
            $card, $instrument->fresh(), 1, $mechanic, $removed->fresh(),
        );

        $this->assertSame('D-KABC', $movement->aircraft_reference);
    }

    #[Test]
    public function nothing_can_be_booked_to_a_signed_card(): void
    {
        // It would change what somebody put their name to.
        $filters = $this->part();
        app(ReceiveStock::class)->handle($filters, 4, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-1',
        ]);

        $card = $this->card();
        $mechanic = $this->storeman();

        app(ManageWorkOrder::class)->recordTime($card, $mechanic, 60, ParticipationKind::Executed);
        app(CertifyTaskCard::class)->complete($card->fresh(), $mechanic, 'Gemacht');
        app(CertifyTaskCard::class)->certify($card->fresh(), $this->qualifiedMechanic());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/signed off/');

        app(IssuePartToCard::class)->handle(
            $card->fresh(), $filters->fresh(), 1, $mechanic, StockLot::sole(),
        );
    }

    #[Test]
    public function without_a_warehouse_the_cards_still_work(): void
    {
        // "Teileentnahme nur, wenn das Lagermodul aktiv ist" -- so the module
        // asks rather than requires, and a club with cards and no store is a
        // real arrangement.
        app(ModuleManager::class)->disable('warehouse');
        app(ModuleManager::class)->forgetCache();

        $this->assertFalse(app(IssuePartToCard::class)->isAvailable());

        $card = $this->card();

        $this->assertSame($card->number, $card->fresh()->number, 'The card is unaffected.');
        $this->assertCount(0, app(IssuePartToCard::class)->issuedTo($card));
    }

    private function aircraft(): Aircraft
    {
        return Aircraft::firstOrCreate(
            ['registration' => 'D-KABC'],
            ['model' => 'ASK 21'],
        );
    }

    private function card(): TaskCard
    {
        $order = app(ManageWorkOrder::class)->open(
            $this->aircraft(),
            'Jahresnachprüfung',
            $this->userWith(Permissions::WORK_ORDERS_MANAGE),
        );

        return app(ManageWorkOrder::class)->addCard($order, 'Ölwechsel');
    }

    private function part(string $name = 'Ölfilter Rotax 912'): PartType
    {
        return PartType::create([
            'name' => $name,
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'St',

            // Lot-tracked, which is what makes these tests about lots. Without
            // it the warehouse keeps a plain quantity and there is no lot to
            // pass -- which is how the first version of this file quietly tested
            // nothing.
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
        return $this->userWith(WarehousePermissions::STOCK_ISSUE, Permissions::CARDS_WORK);
    }

    private function qualifiedMechanic(): User
    {
        $user = $this->userWith(
            WarehousePermissions::STOCK_ISSUE,
            WarehousePermissions::STOCK_QUARANTINE_CERTIFY,
            Permissions::CARDS_CERTIFY,
        );

        Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.12345',
            'category' => 'B1',
            'valid_from' => now()->subYear()->toDateString(),
        ]);

        return $user->fresh();
    }
}
