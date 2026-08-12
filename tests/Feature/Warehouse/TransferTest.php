<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Models\Activity;
use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\Warehouse\Actions\ChangeLotState;
use App\Modules\Warehouse\Actions\DisposeStock;
use App\Modules\Warehouse\Actions\IssueStock;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Actions\TransferStock;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\LotStateChange;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StorageCompartment;
use App\Modules\Warehouse\Models\StorageLocation;
use App\Modules\Warehouse\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Moving a lot to another compartment.
 *
 * The interesting part is not the address change but the quarantine store.
 * Physical separation is what 145.A.42 asks for, and the shelf and the record
 * have to agree at the moment the part is put there -- not when somebody finally
 * reaches for it and the belt-and-braces check in IssueStock fires.
 */
final class TransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            Permissions::STOCK_TRANSFER,
            Permissions::STOCK_QUARANTINE,
            Permissions::STOCK_QUARANTINE_CERTIFY,
            Permissions::STOCK_QUARANTINE_RELEASE,
            Permissions::STOCK_SCRAP,
            Permissions::STOCK_ISSUE,
        ] as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    #[Test]
    public function a_lot_moves_to_another_compartment(): void
    {
        $part = $this->part();
        app(ReceiveStock::class)->handle($part, 4, '2025-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2025-1',
        ]);

        $lot = StockLot::sole();
        $shelf = $this->compartment('Regal B');

        $moved = app(TransferStock::class)->handle($lot, $shelf, $this->storeman());

        $this->assertSame($shelf->id, $moved->storage_compartment_id);
        $this->assertSame(4.0, $moved->remainingQuantity(), 'Nothing changed but the address.');
    }

    #[Test]
    public function moving_it_is_not_a_stock_movement(): void
    {
        // Every line in the ledger is a change in how much there is. A transfer
        // is not one, and putting it there would make the journal lie about
        // quantities.
        $part = $this->part();
        app(ReceiveStock::class)->handle($part, 4, '2025-07-01', lotData: $this->certified());

        $before = $part->fresh()->movements()->count();

        app(TransferStock::class)->handle(
            StockLot::sole(), $this->compartment('Regal B'), $this->storeman(),
        );

        $this->assertSame($before, $part->fresh()->movements()->count());
    }

    #[Test]
    public function the_move_is_recorded_even_though_the_ledger_stays_quiet(): void
    {
        // A lot could be carried across the store and nothing anywhere would say
        // by whom -- while the same change on a part type had been logged since
        // the beginning.
        $part = $this->part();
        app(ReceiveStock::class)->handle($part, 4, '2025-07-01', lotData: $this->certified());

        $user = $this->storeman();
        $shelf = $this->compartment('Regal B');

        $this->actingAs($user);
        app(TransferStock::class)->handle(StockLot::sole(), $shelf, $user);

        $logged = Activity::query()
            ->where('subject_type', StockLot::class)
            ->where('subject_id', StockLot::sole()->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($logged, 'The move has to leave a trace somewhere.');
        $this->assertSame($shelf->id, $logged->attribute_changes['attributes']['storage_compartment_id'] ?? null);
    }

    #[Test]
    public function moving_serviceable_stock_into_the_quarantine_store_sets_it_aside(): void
    {
        // Physically separating something IS setting it aside. Leaving the state
        // saying "serviceable" would be the shelf and the record telling
        // different stories.
        $part = $this->part();
        app(ReceiveStock::class)->handle($part, 4, '2025-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2025-1',
        ]);

        $lot = StockLot::sole();
        $this->assertSame(LotState::Serviceable, $lot->state);

        $moved = app(TransferStock::class)->handle(
            $lot, $this->quarantineCompartment(), $this->storeman(), 'Verdacht auf Transportschaden',
        );

        $this->assertSame(LotState::Quarantined, $moved->state);
        $this->assertFalse($moved->isIssuable());
    }

    #[Test]
    public function that_quarantine_gets_a_numbered_tag_like_any_other(): void
    {
        // Through the state action rather than beside it: nothing about
        // quarantine works differently because it was reached by moving a box.
        $part = $this->part();
        app(ReceiveStock::class)->handle($part, 4, '2025-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2025-1',
        ]);

        app(TransferStock::class)->handle(
            StockLot::sole(), $this->quarantineCompartment(), $this->storeman(), 'Verdacht',
        );

        $change = LotStateChange::where('to_state', LotState::Quarantined->value)->sole();

        $this->assertNotNull($change->quarantine_tag);
        $this->assertMatchesRegularExpression('/^\d{6}-\d{3}$/', $change->quarantine_tag);
        $this->assertStringContainsString('Verdacht', $change->reason);
    }

    #[Test]
    public function moving_it_out_again_is_refused_while_it_is_still_blocked(): void
    {
        // THE rule. Otherwise "move it back to the shelf" releases a part
        // without anyone determining anything.
        $part = $this->part();
        app(ReceiveStock::class)->handle($part, 4, '2025-07-01', lotData: $this->certified());

        $lot = StockLot::sole();
        $lot = $this->quarantine($lot);
        $this->assertSame(LotState::Quarantined, $lot->state);

        app(TransferStock::class)->handle($lot, $this->quarantineCompartment(), $this->storeman(), 'Verdacht auf Transportschaden');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/would be a release/');

        app(TransferStock::class)->handle(
            $lot->fresh(), $this->compartment('Regal B'), $this->storeman(),
        );
    }

    #[Test]
    public function once_released_it_may_come_back_to_the_shelf(): void
    {
        $part = $this->part();
        app(ReceiveStock::class)->handle($part, 4, '2025-07-01', lotData: $this->certified());

        $lot = StockLot::sole();
        app(TransferStock::class)->handle($lot, $this->quarantineCompartment(), $this->storeman(), 'Verdacht auf Transportschaden');

        app(ChangeLotState::class)->handle(
            $lot->fresh(), LotState::Serviceable, 'Form 1 nachgereicht', $this->qualifiedMechanic(),
        );

        $shelf = $this->compartment('Regal B');
        $moved = app(TransferStock::class)->handle($lot->fresh(), $shelf, $this->storeman());

        $this->assertSame($shelf->id, $moved->storage_compartment_id);
    }

    #[Test]
    public function an_unserviceable_lot_cannot_be_carried_out_of_the_quarantine_store(): void
    {
        $part = $this->part();
        app(ReceiveStock::class)->handle($part, 4, '2025-07-01', lotData: $this->certified());

        $lot = StockLot::sole();
        app(TransferStock::class)->handle($lot, $this->quarantineCompartment(), $this->storeman(), 'Verdacht auf Transportschaden');
        app(ChangeLotState::class)->handle(
            $lot->fresh(), LotState::Unserviceable, 'Korrosion', $this->qualifiedMechanic(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/would be a release/');

        app(TransferStock::class)->handle(
            $lot->fresh(), $this->compartment('Regal B'), $this->storeman(),
        );
    }

    #[Test]
    public function a_blocked_lot_on_an_ordinary_shelf_may_still_be_shuffled(): void
    {
        // The first version of this rule refused every move of a blocked lot
        // into normal storage -- which pinned a lot that arrived without its
        // paperwork exactly where it happened to land, and gave a club with no
        // quarantine location no way to move such a lot at all. Blocking a move
        // that does not worsen the separation achieves nothing except somebody
        // carrying the box anyway and recording nothing.
        $part = $this->part();
        app(ReceiveStock::class)->handle($part, 4, '2025-07-01', lotData: $this->certified());

        $lot = $this->quarantine(StockLot::sole());
        $this->assertSame(LotState::Quarantined, $lot->state);

        $shelf = $this->compartment('Regal B');
        $moved = app(TransferStock::class)->handle($lot, $shelf, $this->storeman());

        $this->assertSame($shelf->id, $moved->storage_compartment_id);
        $this->assertSame(LotState::Quarantined, $moved->state, 'Still blocked.');

        // Not refused, but worth saying: it belongs in the quarantine store.
        $this->assertTrue(app(TransferStock::class)->belongsToQuarantineStore($moved, $shelf));
    }

    #[Test]
    public function but_it_may_be_moved_within_the_quarantine_store(): void
    {
        // Rearranging the blocked shelf is not a release.
        $part = $this->part();
        app(ReceiveStock::class)->handle($part, 4, '2025-07-01', lotData: $this->certified());

        $lot = StockLot::sole();
        app(TransferStock::class)->handle($lot, $this->quarantineCompartment(), $this->storeman(), 'Verdacht auf Transportschaden');

        $second = StorageCompartment::create([
            'storage_location_id' => StorageLocation::where('is_quarantine', true)->sole()->id,
            'name' => 'Sperrfach 2',
        ]);

        $moved = app(TransferStock::class)->handle($lot->fresh(), $second, $this->storeman());

        $this->assertSame($second->id, $moved->storage_compartment_id);
        $this->assertSame(LotState::Quarantined, $moved->state);
    }

    #[Test]
    public function a_reason_is_required_when_the_move_blocks_the_lot(): void
    {
        // It goes on the tag that gets printed and hung on the part.
        $part = $this->part();
        app(ReceiveStock::class)->handle($part, 4, '2025-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2025-1',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/goes on the tag/');

        app(TransferStock::class)->handle(
            StockLot::sole(), $this->quarantineCompartment(), $this->storeman(),
        );
    }

    #[Test]
    public function an_empty_lot_has_nothing_to_move(): void
    {
        $part = $this->part();
        app(ReceiveStock::class)->handle($part, 4, '2025-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2025-1',
        ]);

        $lot = StockLot::sole();
        app(IssueStock::class)->handle($part->fresh(), 4, $lot);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nothing to move/');

        app(TransferStock::class)->handle(
            $lot->fresh(), $this->compartment('Regal B'), $this->storeman(),
        );
    }

    #[Test]
    public function a_destroyed_lot_is_not_moved_anywhere(): void
    {
        $part = $this->part();
        app(ReceiveStock::class)->handle($part, 4, '2025-07-01', lotData: $this->certified());

        $lot = StockLot::sole();
        app(DisposeStock::class)->handle($part->fresh(), 4, $lot, $this->qualifiedMechanic(), 'Wasserschaden');

        $this->assertFalse(
            app(TransferStock::class)->mayMoveTo($lot->fresh(), $this->compartment('Regal B')),
        );

        $this->expectException(RuntimeException::class);
        app(TransferStock::class)->handle(
            $lot->fresh(), $this->compartment('Regal B'), $this->storeman(),
        );
    }

    #[Test]
    public function it_needs_the_permission(): void
    {
        $part = $this->part();
        app(ReceiveStock::class)->handle($part, 4, '2025-07-01', lotData: $this->certified());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/stock\.transfer/');

        app(TransferStock::class)->handle(
            StockLot::sole(), $this->compartment('Regal B'), $this->userWith(),
        );
    }

    #[Test]
    public function moving_it_where_it_already_is_is_refused(): void
    {
        $part = $this->part();
        app(ReceiveStock::class)->handle($part, 4, '2025-07-01', lotData: $this->certified());

        $lot = StockLot::sole();
        $shelf = $this->compartment('Regal B');
        app(TransferStock::class)->handle($lot, $shelf, $this->storeman());

        $this->expectException(InvalidArgumentException::class);
        app(TransferStock::class)->handle($lot->fresh(), $shelf, $this->storeman());
    }

    private function part(): PartType
    {
        return PartType::create([
            'name' => 'Ölfilter Rotax 912',
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'St',
            'requires_form_one' => true,
        ]);
    }

    private function compartment(string $name): StorageCompartment
    {
        $location = StorageLocation::firstOrCreate(
            ['name' => 'Werkstatt'],
            ['is_quarantine' => false],
        );

        return StorageCompartment::firstOrCreate([
            'storage_location_id' => $location->id,
            'name' => $name,
        ]);
    }

    private function quarantineCompartment(): StorageCompartment
    {
        $location = StorageLocation::firstOrCreate(
            ['name' => 'Sperrlager'],
            ['is_quarantine' => true],
        );

        return StorageCompartment::firstOrCreate([
            'storage_location_id' => $location->id,
            'name' => 'Sperrfach',
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
        return $this->userWith(Permissions::STOCK_TRANSFER, Permissions::STOCK_QUARANTINE);
    }

    private function qualifiedMechanic(): User
    {
        $user = $this->userWith(
            Permissions::STOCK_QUARANTINE_CERTIFY,
            // Seit dem Feldtest ist der Weg aus der Eingangs-Quarantaene ein
            // eigenes Recht (keine Lizenzfrage) -- der Mechaniker hier darf
            // beides, der Test handelt von der Umlagerung danach.
            Permissions::STOCK_QUARANTINE_RELEASE,
            Permissions::STOCK_SCRAP,
            Permissions::STOCK_TRANSFER,
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
