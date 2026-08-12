<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Models\User;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Filament\Pages\DisposalPage;
use App\Modules\Warehouse\Filament\Pages\StockAttention;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\RendersModulePages;
use Tests\TestCase;

/**
 * "Was liegt an" fuehrt hin, nicht nur hinein.
 *
 * Feldtest: "Meldungen sollten direkt auf die seite fuehren auf der sich das
 * problem beheben laesst." Ein abgelaufenes Los verlinkt deshalb auf die
 * Vernichten-Seite -- mit dem Los schon im Formular -- und der Link erscheint
 * nur, wenn die Zielseite fuer diese Person ueberhaupt aufgeht.
 */
#[Group('rendering')]
final class AttentionLinksTest extends TestCase
{
    use RendersModulePages;

    /** @return list<string> */
    protected function modulesUnderTest(): array
    {
        return ['warehouse'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Die Seite verlinkt auf Modul-Ressourcen -- deren Routen entstehen
        // erst beim Panel-Bau mit aktivem Modul, daher rendering-Gruppe.
        $this->bootWithModules();

        foreach ([Permissions::STOCK_VIEW, Permissions::STOCK_SCRAP, Permissions::STOCK_RECEIVE] as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    #[Test]
    public function an_expired_lot_links_to_the_disposal_page(): void
    {
        $lot = $this->expiredLot();

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::STOCK_VIEW);
        $user->givePermissionTo(Permissions::STOCK_SCRAP);
        $this->actingAs($user->fresh());

        Livewire::test(StockAttention::class)
            ->assertSuccessful()
            ->assertSeeHtml(DisposalPage::getUrl(['lot' => $lot->id]));
    }

    #[Test]
    public function without_the_scrap_permission_the_row_is_no_link(): void
    {
        // Ein Link auf eine Seite, die mit 403 antwortet, ist ein Versprechen
        // an die falsche Person.
        $this->expiredLot();

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::STOCK_VIEW);
        $this->actingAs($user->fresh());

        Livewire::test(StockAttention::class)
            ->assertSuccessful()
            ->assertDontSeeHtml('vernichten?lot=');
    }

    #[Test]
    public function the_disposal_page_prefills_the_lot_from_the_link(): void
    {
        $lot = $this->expiredLot();

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::STOCK_SCRAP);
        $this->actingAs($user->fresh());

        $this->withoutExceptionHandling();

        // Der Query-Parameter aus dem Link fuellt das Formular -- zwei Klicks
        // vom Befund zur Behebung.
        $this->get(DisposalPage::getUrl(['lot' => $lot->id]))
            ->assertSuccessful()
            ->assertSee($lot->lot_number);
    }

    private function expiredLot(): StockLot
    {
        // Als Komponente mit Nachweis, damit ein LOS entsteht -- Sammelbestand
        // ohne Los kann nicht ablaufen.
        $teil = PartType::create([
            'name' => 'Dichtmittel',
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'Tube',
            'requires_form_one' => true,
        ]);

        app(ReceiveStock::class)->handle($teil, 3, '2026-01-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-77',
        ]);

        // Das Ablaufdatum direkt am Los: ReceiveStock uebernimmt keines aus
        // lotData -- und genau der abgelaufene Zustand ist hier der Punkt.
        $lot = StockLot::sole();
        $lot->forceFill(['expires_at' => now()->subDay()->toDateString()])->saveQuietly();

        return $lot->fresh();
    }
}
