<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\Warehouse\Actions\ChangeLotState;
use App\Modules\Warehouse\Actions\DisposeStock;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\MovementType;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\LotStateChange;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Destroying stock.
 *
 * the cases: expired resin, and parts finally disposed of out of the
 * quarantine store. Neither had a path -- disposal existed only as the last link
 * of the lot state chain, which left bulk stock with no way out at all, whole
 * lots as the only granularity, and three qualified acts between a tin of resin
 * and the bin.
 */
final class DisposalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([Permissions::STOCK_SCRAP, Permissions::STOCK_QUARANTINE_CERTIFY] as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    #[Test]
    public function bulk_stock_can_be_destroyed_at_all(): void
    {
        // It could not before. The only way out was a "stocktake difference" --
        // filing destruction under counting error.
        $nuts = $this->bulkPart();
        $user = $this->qualifiedUser();

        app(ReceiveStock::class)->handle($nuts, 500, '2025-07-01', lotData: $this->certified());

        $movement = app(DisposeStock::class)->handle($nuts->fresh(), 120, null, $user, 'Korrodiert');

        $this->assertSame(MovementType::Disposal, $movement->type);
        $this->assertSame(-120.0, (float) $movement->quantity);
        $this->assertSame(380.0, $nuts->fresh()->currentStock());
        $this->assertSame('Korrodiert', $movement->note);
    }

    #[Test]
    public function part_of_a_lot_can_be_destroyed_and_the_rest_stays_as_it_was(): void
    {
        // Three damaged filters out of ten had no path before.
        $filters = $this->lotPart();
        $user = $this->qualifiedUser();

        app(ReceiveStock::class)->handle($filters, 10, '2025-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2025-1',
        ]);

        $lot = StockLot::sole();
        $this->assertSame(LotState::Serviceable, $lot->state);

        app(DisposeStock::class)->handle($filters->fresh(), 3, $lot, $user, 'Beim Transport beschädigt');

        $this->assertSame(7.0, $lot->fresh()->remainingQuantity());
        $this->assertSame(LotState::Serviceable, $lot->fresh()->state, 'The rest is unchanged.');
        $this->assertTrue($lot->fresh()->isIssuable());
    }

    #[Test]
    public function a_lot_emptied_by_destruction_becomes_disposed(): void
    {
        // The state follows the quantity rather than leading it.
        $filters = $this->lotPart();
        $user = $this->qualifiedUser();

        app(ReceiveStock::class)->handle($filters, 4, '2025-07-01', lotData: $this->certified());
        $lot = StockLot::sole();

        app(DisposeStock::class)->handle($filters->fresh(), 4, $lot, $user, 'Wasserschaden');

        $this->assertSame(LotState::Disposed, $lot->fresh()->state);
        $this->assertSame(0.0, $lot->fresh()->remainingQuantity());
    }

    #[Test]
    public function expired_resin_goes_straight_in_the_bin(): void
    {
        // the example, and the reason the state chain had to give. Before,
        // this took three qualified acts: unserviceable, unsalvageable,
        // disposed -- for something the system already knew was expired.
        $resin = PartType::create([
            'name' => 'Harz L285',
            'classification' => PartClassification::ConsumableMaterial,
            'unit_of_measure' => 'kg',
            'shelf_life_days' => 365,
        ]);
        $user = $this->qualifiedUser();

        app(ReceiveStock::class)->handle($resin, 5, now()->subYears(2)->toDateString(), lotData: $this->certified());
        $lot = StockLot::sole();

        $this->assertTrue($lot->hasExpired());
        $this->assertSame(LotState::Serviceable, $lot->state, 'Expired, but never declared anything.');

        app(DisposeStock::class)->handle($resin->fresh(), 5, $lot, $user, 'Verfallsdatum überschritten');

        $this->assertSame(LotState::Disposed, $lot->fresh()->state);
        $this->assertSame(0.0, $resin->fresh()->currentStock());
    }

    #[Test]
    public function the_record_survives_the_rubbish(): void
    {
        // Otherwise the evidence that the part ever existed goes out with it,
        // and that is precisely what an audit asks about.
        $filters = $this->lotPart();
        $user = $this->qualifiedUser();

        app(ReceiveStock::class)->handle($filters, 4, '2025-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2025-8842',
        ]);

        $lot = StockLot::sole();
        app(DisposeStock::class)->handle($filters->fresh(), 4, $lot, $user, 'Wasserschaden');

        $kept = StockLot::find($lot->id);

        $this->assertNotNull($kept);
        $this->assertSame('F1-2025-8842', $kept->document_reference);
        $this->assertSame(4.0, $kept->remainingQuantityAsOf('2025-08-01'), 'History intact.');
        $this->assertSame(2, $kept->movements()->count());
    }

    #[Test]
    public function the_determination_is_frozen_with_the_credential(): void
    {
        $filters = $this->lotPart();
        $user = $this->qualifiedUser('DE.66.12345', 'B1.2');

        app(ReceiveStock::class)->handle($filters, 1, '2025-07-01', lotData: $this->certified());
        $lot = StockLot::sole();

        app(DisposeStock::class)->handle($filters->fresh(), 1, $lot, $user, 'Riss im Gehäuse');

        $change = LotStateChange::where('stock_lot_id', $lot->id)
            ->where('to_state', LotState::Disposed->value)
            ->sole();

        $this->assertSame('DE.66.12345', $change->qualification_reference);
        $this->assertSame('B1.2', $change->qualification_category);
        $this->assertSame($user->name, $change->determined_by_name);
    }

    #[Test]
    public function it_needs_the_permission_and_the_qualification(): void
    {
        $filters = $this->lotPart();
        app(ReceiveStock::class)->handle($filters, 1, '2025-07-01', lotData: $this->certified());
        $lot = StockLot::sole();

        try {
            app(DisposeStock::class)->handle($filters->fresh(), 1, $lot, $this->userWith(), 'Weg');
            $this->fail('Without the permission this must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('stock.scrap', $e->getMessage());
        }

        // Permission but no licence: two different refusals, two different
        // messages -- one is administrative, the other is about the person.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/qualified staff/');

        app(DisposeStock::class)->handle(
            $filters->fresh(), 1, $lot, $this->userWith(Permissions::STOCK_SCRAP), 'Weg',
        );
    }

    #[Test]
    public function more_cannot_be_destroyed_than_exists(): void
    {
        $filters = $this->lotPart();
        $user = $this->qualifiedUser();

        app(ReceiveStock::class)->handle($filters, 2, '2025-07-01', lotData: $this->certified());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/holds only 2/');

        app(DisposeStock::class)->handle($filters->fresh(), 4, StockLot::sole(), $user, 'Weg');
    }

    #[Test]
    public function a_reason_is_required(): void
    {
        // "Destroyed" without one is a quantity that vanished.
        $nuts = $this->bulkPart();
        $user = $this->qualifiedUser();

        app(ReceiveStock::class)->handle($nuts, 500, '2025-07-01', lotData: $this->certified());

        $this->expectException(InvalidArgumentException::class);
        app(DisposeStock::class)->handle($nuts->fresh(), 10, null, $user, '   ');
    }

    #[Test]
    public function a_lot_tracked_part_has_to_name_its_lot(): void
    {
        $filters = $this->lotPart();
        $user = $this->qualifiedUser();

        app(ReceiveStock::class)->handle($filters, 4, '2025-07-01', lotData: $this->certified());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/which lot was destroyed/');

        app(DisposeStock::class)->handle($filters->fresh(), 1, null, $user, 'Weg');
    }

    #[Test]
    public function quarantined_stock_can_be_disposed_of_without_the_detour(): void
    {
        // the second case: parts finally disposed of out of the quarantine
        // store. Before, that meant declaring them unsalvageable first.
        $filters = $this->lotPart();
        $user = $this->qualifiedUser();

        app(ReceiveStock::class)->handle($filters, 1, '2025-07-01', lotData: $this->certified());
        $lot = StockLot::sole();

        $lot = $this->quarantine($lot);
        $this->assertSame(LotState::Quarantined, $lot->state);

        app(DisposeStock::class)->handle($filters->fresh(), 1, $lot, $user, 'Herkunft nicht zu klären');

        $this->assertSame(LotState::Disposed, $lot->fresh()->state);
    }

    #[Test]
    public function the_way_back_into_service_is_still_shut(): void
    {
        // What the one-way chain protects is the route back into service, and
        // opening disposal from every state leaves that untouched.
        $this->assertSame([], LotState::Disposed->allowedTransitions());
        $this->assertTrue(LotState::Disposed->isFinal());
        $this->assertSame([LotState::Disposed], LotState::Unsalvageable->allowedTransitions());
        $this->assertFalse(LotState::Unsalvageable->canTransitionTo(LotState::Serviceable));
    }

    #[Test]
    public function the_state_route_to_disposal_still_works(): void
    {
        // The older path through ChangeLotState is unchanged, it just no longer
        // has to be the only one.
        $filters = $this->lotPart();
        $user = $this->qualifiedUser();

        app(ReceiveStock::class)->handle($filters, 1, '2025-07-01', lotData: $this->certified());

        // Erst sperren: Von "brauchbar" direkt auf "ausgemustert" gibt es
        // keinen Weg -- eine Feststellung setzt einen Verdacht voraus.
        $lot = $this->quarantine(StockLot::sole(), 'Riss vermutet');

        app(ChangeLotState::class)->handle($lot, LotState::Unsalvageable, 'Riss', $user);
        app(ChangeLotState::class)->handle($lot->fresh(), LotState::Disposed, 'Entsorgt', $user);

        $this->assertSame(LotState::Disposed, $lot->fresh()->state);
        $this->assertSame(0.0, $lot->fresh()->remainingQuantity());
    }

    #[Test]
    public function expired_lots_are_offered_without_being_asked_for(): void
    {
        // Expired stock is the commonest reason to destroy anything and the
        // easiest to overlook: it looks exactly like the rest of the shelf.
        $resin = PartType::create([
            'name' => 'Harz L285',
            'classification' => PartClassification::ConsumableMaterial,
            'unit_of_measure' => 'kg',
            'shelf_life_days' => 365,
        ]);
        $filters = $this->lotPart();

        app(ReceiveStock::class)->handle($resin, 5, now()->subYears(2)->toDateString(), lotData: $this->certified());
        app(ReceiveStock::class)->handle($filters, 4, '2025-07-01', lotData: $this->certified());

        $expired = app(DisposeStock::class)->expiredLots();

        $this->assertCount(1, $expired);
        $this->assertSame('Harz L285', $expired->first()->partType->name);
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

    private function qualifiedUser(string $reference = 'DE.66.00000', string $category = 'B1'): User
    {
        $user = $this->userWith(Permissions::STOCK_SCRAP, Permissions::STOCK_QUARANTINE_CERTIFY);

        Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => $reference,
            'category' => $category,
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
