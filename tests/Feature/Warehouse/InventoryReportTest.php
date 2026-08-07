<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Warehouse\Actions\ChangeLotState;
use App\Modules\Warehouse\Actions\IssueStock;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The inventory report, and above all its cut-off date.
 *
 * A stocktake is a statement AS OF a date -- usually one that has passed by the
 * time anyone counts. That the figure for a past day is exactly computable
 * rather than estimated is the practical payoff of decision E1, and the
 * predecessor could not have managed it at all.
 */
final class InventoryReportTest extends TestCase
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
    public function it_reports_stock_as_it_stood_on_a_past_date(): void
    {
        // The whole point: 500 in July, 200 taken out in August. What was there
        // on the 31st of July?
        $nuts = $this->bulkPart();

        app(ReceiveStock::class)->handle($nuts, 500, '2025-07-01', lotData: $this->certified());
        app(IssueStock::class)->handle($nuts->fresh(), 200, occurredAt: '2025-08-15');

        $this->assertSame(300.0, $nuts->fresh()->currentStock());
        $this->assertSame(500.0, $nuts->fresh()->stockAsOf('2025-07-31'));
        $this->assertSame(0.0, $nuts->fresh()->stockAsOf('2025-06-30'));
    }

    #[Test]
    public function the_report_shows_the_figure_for_the_cut_off_not_for_today(): void
    {
        $nuts = $this->bulkPart('Mutter M6 DIN 934');

        app(ReceiveStock::class)->handle($nuts, 500, '2025-07-01', lotData: $this->certified());
        app(IssueStock::class)->handle($nuts->fresh(), 200, occurredAt: '2025-08-15');

        $this->actingAs($this->userWith(Permissions::STOCK_REPORT))
            ->get(route('warehouse.inventory-report', ['as_of' => '2025-07-31']))
            ->assertSuccessful()
            ->assertSee('Mutter M6 DIN 934')
            ->assertSee('500')
            ->assertSee('31.07.2025');
    }

    #[Test]
    public function a_lot_emptied_since_still_appears_at_its_cut_off(): void
    {
        // Which is the whole reason for a cut-off date: the shelf looked
        // different then, and the report has to say what it looked like.
        $filters = $this->lotPart();

        app(ReceiveStock::class)->handle($filters, 4, '2025-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2025-8842',
        ]);

        $lot = StockLot::sole();
        app(IssueStock::class)->handle($filters->fresh(), 4, $lot, occurredAt: '2025-09-01');

        $this->assertSame(0.0, $lot->fresh()->remainingQuantity());
        $this->assertSame(4.0, $lot->fresh()->remainingQuantityAsOf('2025-08-31'));

        $this->actingAs($this->userWith(Permissions::STOCK_REPORT))
            ->get(route('warehouse.inventory-report', ['as_of' => '2025-08-31']))
            ->assertSuccessful()
            ->assertSee('F1-2025-8842');
    }

    #[Test]
    public function a_future_cut_off_is_pulled_back_to_today(): void
    {
        // Anything else would mix in bookings that have not happened yet in any
        // meaningful sense. This guard caught the first version of the tests
        // above, which had picked a cut-off two days ahead without noticing.
        $this->bulkPart();

        $this->actingAs($this->userWith(Permissions::STOCK_REPORT))
            ->get(route('warehouse.inventory-report', ['as_of' => now()->addYear()->toDateString()]))
            ->assertSuccessful()
            ->assertSee(now()->format('d.m.Y'));
    }

    #[Test]
    public function available_and_blocked_are_reported_separately(): void
    {
        // When counting a shelf both are in the same hand; in usability they are
        // worlds apart.
        $filters = $this->lotPart();

        app(ReceiveStock::class)->handle($filters, 4, '2025-07-01', lotData: $this->certified());
        $this->quarantine(StockLot::sole());

        $response = $this->actingAs($this->userWith(Permissions::STOCK_REPORT))
            ->get(route('warehouse.inventory-report'));

        $response->assertSuccessful()
            ->assertSee(__('warehouse.inventory.blocked'))
            ->assertSee(__('warehouse.lot_state.quarantined'));

        $this->assertSame(0.0, $filters->fresh()->availableStock());
        $this->assertSame(4.0, $filters->fresh()->currentStock());
    }

    #[Test]
    public function it_lists_lots_whose_document_was_never_filed(): void
    {
        $filters = $this->lotPart();
        app(ReceiveStock::class)->handle($filters, 4, '2025-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2025-8842',
        ]);

        $this->actingAs($this->userWith(Permissions::STOCK_REPORT))
            ->get(route('warehouse.inventory-report'))
            ->assertSuccessful()
            ->assertSee(__('warehouse.inventory.section.missing_evidence'))
            ->assertSee('F1-2025-8842');
    }

    #[Test]
    public function the_journal_is_off_unless_asked_for(): void
    {
        // By far the longest section, and rarely what someone wants.
        $nuts = $this->bulkPart();
        app(ReceiveStock::class)->handle($nuts, 500, '2025-07-01', lotData: $this->certified());

        $without = $this->actingAs($this->userWith(Permissions::STOCK_REPORT))
            ->get(route('warehouse.inventory-report'));
        $without->assertDontSee(__('warehouse.inventory.section.journal'));

        $with = $this->actingAs($this->userWith(Permissions::STOCK_REPORT))
            ->get(route('warehouse.inventory-report', ['journal' => 1, 'from' => '2025-01-01']));
        $with->assertSee(__('warehouse.inventory.section.journal'));
    }

    #[Test]
    public function it_needs_the_report_permission(): void
    {
        $this->actingAs($this->userWith(Permissions::STOCK_VIEW))
            ->get(route('warehouse.inventory-report'))
            ->assertForbidden();
    }

    #[Test]
    public function nothing_is_reported_while_the_module_is_off(): void
    {
        app(ModuleManager::class)->disable('warehouse');
        app(ModuleManager::class)->forgetCache();

        $this->actingAs($this->userWith(Permissions::STOCK_REPORT))
            ->get(route('warehouse.inventory-report'))
            ->assertNotFound();
    }

    private function bulkPart(string $name = 'Mutter M6'): PartType
    {
        return PartType::create([
            'name' => $name,
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
