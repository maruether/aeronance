<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Warehouse\Actions\ChangeLotState;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Filament\Pages\IssueStockPage;
use App\Modules\Warehouse\Filament\Pages\ReceiveStockPage;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The two screens the warehouse is actually used through.
 *
 * Driven as a person would drive them -- pick a part, fill the form, submit --
 * rather than by calling the action directly, because the point here is the
 * behaviour that only exists in the screen: which fields appear, what is
 * preselected, and what happens when the answer is no.
 */
final class BookingPagesTest extends TestCase
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
    public function it_books_bulk_stock_in(): void
    {
        $nuts = $this->bulkPart();
        $this->actingAs($this->userWith(Permissions::STOCK_RECEIVE));

        Livewire::test(ReceiveStockPage::class)
            ->fillForm([
                'part_type_id' => $nuts->id,
                'quantity' => 500,
                'received_at' => '2026-07-01',
            ])
            ->call('submit')
            ->assertHasNoFormErrors();

        $this->assertSame(500.0, $nuts->fresh()->currentStock());
        $this->assertSame(0, StockLot::count(), 'Nuts do not need a lot.');
    }

    #[Test]
    public function it_books_in_a_lot_with_its_form_one(): void
    {
        $filters = $this->componentPart(requiresFormOne: true, shelfLifeDays: 1095);
        $this->actingAs($this->userWith(Permissions::STOCK_RECEIVE));

        Livewire::test(ReceiveStockPage::class)
            ->fillForm([
                'part_type_id' => $filters->id,
                'quantity' => 4,
                'received_at' => '2026-07-10',
                'document_type' => StockLot::DOCUMENT_FORM_ONE,
                'document_reference' => 'F1-2026-8842',
                'document_issuer' => 'Rotax Service Center',
                'document_issuer_approval' => 'AT.145.0123',
                'document_signatory' => 'H. Meier',
            ])
            ->call('submit')
            ->assertHasNoFormErrors();

        $lot = StockLot::sole();
        $this->assertSame('F1-2026-8842', $lot->document_reference);
        $this->assertSame('AT.145.0123', $lot->document_issuer_approval);
        $this->assertSame(LotState::Serviceable, $lot->state);
        $this->assertSame('2029-07-09', $lot->expires_at->toDateString());
    }

    /**
     * Der Aufkleber gehört an die Meldung, nicht hinter einen Umweg.
     *
     * Feldtest: „um losaufkleber zu drucken muss ich erst einbuchen und dann im
     * bestand den druck auswählen. das sollte direkt beim einbuchen gehen."
     * Und das ist die Reihenfolge, in der es passiert: Die Ware liegt auf dem
     * Tisch, das Los ist gerade entstanden, der Aufkleber gehört jetzt darauf.
     */
    #[Test]
    public function booking_a_lot_in_offers_its_label_right_away(): void
    {
        $filters = $this->componentPart(requiresFormOne: true);
        $this->actingAs($this->userWith(Permissions::STOCK_RECEIVE));

        Livewire::test(ReceiveStockPage::class)
            ->fillForm([
                'part_type_id' => $filters->id,
                'quantity' => 2,
                'received_at' => '2026-07-10',
                'document_type' => StockLot::DOCUMENT_FORM_ONE,
                'document_reference' => 'F1-2026-9001',
                'document_issuer' => 'Rotax Service Center',
                'document_issuer_approval' => 'AT.145.0123',
                'document_signatory' => 'H. Meier',
            ])
            ->call('submit')
            ->assertHasNoFormErrors()
            ->assertNotified();

        // Die Meldung fuehrt auf das Etikett DIESES Loses.
        $lot = StockLot::sole();

        $this->assertStringContainsString(
            'lots='.$lot->getKey(),
            urldecode(route('warehouse.label.print', ['lots' => $lot->getKey()])),
        );
    }

    /**
     * Sammelbestand bekommt kein Etikett angeboten -- es gibt kein Los, auf das
     * eines gehoerte. Schrauben tragen keine Losnummer.
     */
    #[Test]
    public function bulk_stock_gets_no_label_offered(): void
    {
        $nuts = $this->bulkPart();
        $this->actingAs($this->userWith(Permissions::STOCK_RECEIVE));

        Livewire::test(ReceiveStockPage::class)
            ->fillForm([
                'part_type_id' => $nuts->id,
                'quantity' => 100,
                'received_at' => '2026-07-01',
            ])
            ->call('submit')
            ->assertHasNoFormErrors();

        $this->assertSame(0, StockLot::count());
    }

    /**
     * Ohne Form 1 nimmt der Bildschirm die Ware nicht an.
     *
     * Vorgabe: „ein los geht erst dann ins lager wenn das form1 da ist. vorher
     * liegt es im wareneingang und ist noch nicht verbucht." Wer es trotzdem
     * versucht, bekommt einen Satz, der sagt WO die Ware bleibt -- nicht bloss
     * eine rote Zeile am Feld.
     */
    #[Test]
    public function goods_without_the_certificate_are_not_booked_at_all(): void
    {
        $filters = $this->componentPart(requiresFormOne: true);
        $this->actingAs($this->userWith(Permissions::STOCK_RECEIVE));

        Livewire::test(ReceiveStockPage::class)
            ->fillForm([
                'part_type_id' => $filters->id,
                'quantity' => 4,
                'received_at' => '2026-07-10',
                'document_type' => StockLot::DOCUMENT_NONE,
            ])
            ->call('submit');

        $this->assertSame(0, StockLot::count(), 'Es darf kein Los entstanden sein.');
        $this->assertSame(0.0, (float) $filters->currentStock());
    }

    #[Test]
    public function it_issues_against_a_lot_and_records_the_aircraft(): void
    {
        $filters = $this->componentPart(requiresFormOne: true);
        app(ReceiveStock::class)->handle($filters, 4, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-8842',
        ]);

        $this->actingAs($this->userWith(Permissions::STOCK_ISSUE));

        Livewire::test(IssueStockPage::class)
            ->fillForm([
                'part_type_id' => $filters->id,
                'stock_lot_id' => StockLot::sole()->id,
                'quantity' => 1,
                'aircraft_reference' => 'D-KABC',
                'work_order_reference' => 'AK-2026-014',
            ])
            ->call('submit')
            ->assertHasNoFormErrors();

        $this->assertSame(3.0, StockLot::sole()->remainingQuantity());
    }

    #[Test]
    public function the_lot_that_expires_first_is_preselected(): void
    {
        $oil = $this->componentPart(shelfLifeDays: 365);
        $receive = app(ReceiveStock::class);

        $receive->handle($oil, 2, '2026-06-01');
        $receive->handle($oil, 2, '2026-01-01');   // expires first
        $receive->handle($oil, 2, '2026-07-01');

        $this->actingAs($this->userWith(Permissions::STOCK_ISSUE));

        $expected = StockLot::where('part_type_id', $oil->id)
            ->orderBy('expires_at')->first();

        Livewire::test(IssueStockPage::class)
            ->fillForm(['part_type_id' => $oil->id])
            ->assertFormSet(['stock_lot_id' => $expected->id]);
    }

    #[Test]
    public function nothing_is_preselected_for_a_serialised_part(): void
    {
        // The serial number is the identification -- a default would invite
        // confirming the wrong part. See F26.
        $release = $this->componentPart(serialTracked: true);
        app(ReceiveStock::class)->handle($release, 1, '2026-07-01', lotData: ['serial_number' => '1378X5V'] + $this->certified());

        $this->actingAs($this->userWith(Permissions::STOCK_ISSUE));

        Livewire::test(IssueStockPage::class)
            ->fillForm(['part_type_id' => $release->id])
            ->assertFormSet(['stock_lot_id' => null]);
    }

    #[Test]
    public function issuing_more_than_is_there_is_refused_without_booking_anything(): void
    {
        $oil = $this->componentPart(shelfLifeDays: 365);
        app(ReceiveStock::class)->handle($oil, 2, '2026-07-01', lotData: $this->certified());

        $this->actingAs($this->userWith(Permissions::STOCK_ISSUE));

        Livewire::test(IssueStockPage::class)
            ->fillForm([
                'part_type_id' => $oil->id,
                'stock_lot_id' => StockLot::sole()->id,
                'quantity' => 5,
            ])
            ->call('submit');

        // The refusal is shown as a notification; what matters is that the
        // ledger is untouched.
        $this->assertSame(2.0, StockLot::sole()->remainingQuantity());
    }

    #[Test]
    public function a_quarantined_lot_is_not_offered_for_issue(): void
    {
        $filters = $this->componentPart(requiresFormOne: true);
        app(ReceiveStock::class)->handle($filters, 4, '2026-07-01', lotData: $this->certified());
        $this->quarantine(StockLot::sole());

        $this->actingAs($this->userWith(Permissions::STOCK_ISSUE));

        Livewire::test(IssueStockPage::class)
            ->fillForm(['part_type_id' => $filters->id])
            ->assertFormSet(['stock_lot_id' => null]);
    }

    #[Test]
    public function the_pages_are_closed_without_the_permission(): void
    {
        $this->actingAs($this->userWith());

        $this->assertFalse(ReceiveStockPage::canAccess());
        $this->assertFalse(IssueStockPage::canAccess());
    }

    #[Test]
    public function receiving_and_issuing_are_separate_permissions(): void
    {
        $this->actingAs($this->userWith(Permissions::STOCK_RECEIVE));

        $this->assertTrue(ReceiveStockPage::canAccess());
        $this->assertFalse(IssueStockPage::canAccess());
    }

    private function bulkPart(): PartType
    {
        return PartType::create([
            'name' => 'Mutter M6 '.uniqid(),
            'classification' => PartClassification::StandardPart,
            'unit_of_measure' => 'St',
        ]);
    }

    private function componentPart(
        bool $requiresFormOne = false,
        bool $serialTracked = false,
        ?int $shelfLifeDays = null,
    ): PartType {
        return PartType::create([
            'name' => 'Teil '.uniqid(),
            'classification' => PartClassification::Component,
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
