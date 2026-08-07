<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Access\AccessSetup;
use App\Core\Models\Qualification;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Warehouse\Actions\ChangeLotState;
use App\Modules\Warehouse\Actions\IssueStock;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Filament\Pages\IssueStockPage;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StorageCompartment;
use App\Modules\Warehouse\Models\StorageLocation;
use App\Modules\Warehouse\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Playing attacker, as the security guardrails ask for at every new feature.
 *
 * The question each of these asks is the same: what happens with a manipulated
 * value? A Livewire component's public state is client-side, so anything in
 * $data can be replaced with anything at all -- the guards have to be in the
 * action, not in the form that usually calls it.
 */
final class AdversarialTest extends TestCase
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
    public function a_lot_of_another_part_type_cannot_be_slipped_in(): void
    {
        // The form only ever offers lots of the chosen part. The client can send
        // any id it likes, so the action checks rather than trusting it.
        $oil = $this->part(shelfLifeDays: 365);
        $grease = $this->part(shelfLifeDays: 365);

        app(ReceiveStock::class)->handle($oil, 5, '2026-07-01', lotData: $this->certified());
        app(ReceiveStock::class)->handle($grease, 5, '2026-07-01', lotData: $this->certified());

        $greaseLot = StockLot::where('part_type_id', $grease->id)->sole();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/different part type/');

        app(IssueStock::class)->handle($oil, 1, $greaseLot);
    }

    #[Test]
    public function a_negative_quantity_cannot_turn_an_issue_into_a_receipt(): void
    {
        // Without this, "issue -5" would quietly add stock.
        $nuts = $this->part();
        app(ReceiveStock::class)->handle($nuts, 100, '2026-07-01', lotData: $this->certified());

        try {
            app(IssueStock::class)->handle($nuts, -50);
            $this->fail('A negative issue must be refused.');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame(100.0, $nuts->fresh()->currentStock());
    }

    #[Test]
    public function a_negative_receipt_cannot_drain_stock(): void
    {
        $nuts = $this->part();
        app(ReceiveStock::class)->handle($nuts, 100, '2026-07-01', lotData: $this->certified());

        try {
            app(ReceiveStock::class)->handle($nuts, -100, '2026-07-01', lotData: $this->certified());
            $this->fail('A negative receipt must be refused.');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame(100.0, $nuts->fresh()->currentStock());
    }

    #[Test]
    public function a_quarantined_lot_cannot_be_issued_by_naming_its_id(): void
    {
        $filters = $this->part(requiresFormOne: true);
        app(ReceiveStock::class)->handle($filters, 4, '2026-07-01', lotData: $this->certified());

        $lot = $this->quarantine(StockLot::sole());
        $this->assertSame(LotState::Quarantined, $lot->state);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must not be issued/');

        app(IssueStock::class)->handle($filters, 1, $lot);
    }

    #[Test]
    public function a_scrapped_lot_cannot_be_issued(): void
    {
        $part = $this->part(shelfLifeDays: 365);
        app(ReceiveStock::class)->handle($part, 4, '2026-07-01', lotData: $this->certified());
        $lot = StockLot::sole();

        $mechanic = $this->userWith(Permissions::STOCK_SCRAP, Permissions::STOCK_QUARANTINE_CERTIFY);
        $this->givePart66($mechanic);

        $action = app(ChangeLotState::class);
        $action->handle($lot, LotState::Unserviceable, 'Riss', $mechanic);
        $action->handle($lot->fresh(), LotState::Unsalvageable, 'Nicht reparabel', $mechanic);

        $this->expectException(RuntimeException::class);

        app(IssueStock::class)->handle($part, 1, $lot->fresh());
    }

    #[Test]
    public function stock_sitting_in_a_quarantine_location_cannot_be_issued(): void
    {
        // Belt and braces against the state and the shelf disagreeing: a lot
        // physically standing in the quarantine cupboard must not be issued even
        // if its state still says otherwise. Nobody takes parts out of that
        // cupboard, and the software should not either.
        $part = $this->part(shelfLifeDays: 365);
        app(ReceiveStock::class)->handle($part, 4, '2026-07-01', lotData: $this->certified());

        $quarantine = StorageLocation::create(['name' => 'Sperrlager', 'is_quarantine' => true]);
        $compartment = StorageCompartment::create([
            'storage_location_id' => $quarantine->id,
            'name' => 'Sperrfach',
        ]);

        $lot = StockLot::sole();
        $lot->update(['storage_compartment_id' => $compartment->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/quarantine/i');

        app(IssueStock::class)->handle($part, 1, $lot->fresh());
    }

    #[Test]
    public function the_state_dropdown_cannot_be_used_to_skip_the_chain(): void
    {
        // The interface only offers the transitions that are actually allowed,
        // but the value it sends can be anything.
        $part = $this->part(shelfLifeDays: 365);
        app(ReceiveStock::class)->handle($part, 4, '2026-07-01', lotData: $this->certified());

        $mechanic = $this->userWith(Permissions::STOCK_SCRAP, Permissions::STOCK_QUARANTINE_CERTIFY);
        $this->givePart66($mechanic);

        // serviceable -> disposed skips the whole determination chain
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot go from/');

        app(ChangeLotState::class)->handle(
            StockLot::sole(), LotState::Disposed, 'Abkürzung', $mechanic,
        );
    }

    #[Test]
    public function someone_without_a_licence_cannot_scrap_by_calling_the_action(): void
    {
        $part = $this->part(shelfLifeDays: 365);
        app(ReceiveStock::class)->handle($part, 4, '2026-07-01', lotData: $this->certified());

        // Has the permission, but no qualification.
        $storeman = $this->userWith(Permissions::STOCK_SCRAP, Permissions::STOCK_QUARANTINE_CERTIFY);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/qualified staff/');

        app(ChangeLotState::class)->handle(
            StockLot::sole(), LotState::Unserviceable, 'Angeblich defekt', $storeman,
        );
    }

    #[Test]
    public function a_livewire_page_does_not_trust_its_own_state(): void
    {
        // $data is client-side. Setting it directly is what a manipulated
        // request looks like.
        $oil = $this->part(shelfLifeDays: 365);
        $grease = $this->part(shelfLifeDays: 365);
        app(ReceiveStock::class)->handle($oil, 5, '2026-07-01', lotData: $this->certified());
        app(ReceiveStock::class)->handle($grease, 5, '2026-07-01', lotData: $this->certified());

        $greaseLot = StockLot::where('part_type_id', $grease->id)->sole();

        $this->actingAs($this->userWith(Permissions::STOCK_ISSUE));

        Livewire::test(IssueStockPage::class)
            ->fillForm([
                'part_type_id' => $oil->id,
                'stock_lot_id' => $greaseLot->id,   // belongs to another part
                'quantity' => 1,
            ])
            ->call('submit');

        // Refused, and nothing booked.
        $this->assertSame(5.0, $greaseLot->fresh()->remainingQuantity());
        $this->assertSame(5.0, $oil->fresh()->currentStock());
    }

    #[Test]
    public function a_deactivated_account_cannot_book(): void
    {
        $part = $this->part();
        $user = $this->userWith(Permissions::STOCK_ISSUE);
        $user->update(['is_active' => false]);

        $this->actingAs($user->fresh());

        $this->assertFalse(IssueStockPage::canAccess());
    }

    private function part(
        bool $requiresFormOne = false,
        bool $serialTracked = false,
        ?int $shelfLifeDays = null,
    ): PartType {
        return PartType::create([
            'name' => 'Teil '.uniqid(),
            'classification' => $requiresFormOne || $shelfLifeDays !== null
                ? PartClassification::Component
                : PartClassification::StandardPart,
            'unit_of_measure' => 'St',
            'requires_form_one' => $requiresFormOne,
            'serial_tracked' => $serialTracked,
            'shelf_life_days' => $shelfLifeDays,
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

    private function givePart66(User $user): void
    {
        Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.00000',
            'valid_from' => now()->subYear()->toDateString(),
        ]);
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
