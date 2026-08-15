<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Access\AccessSetup;
use App\Models\User;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Filament\Pages\StockOverview;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Permissions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RendersModulePages;
use Tests\TestCase;

/**
 * „Was ist im Lager" -- die dritte Frage neben Stammdaten und Losen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Feldtest: "ich hätte gerne noch eine übersichtsseite was im lager ist.
 * bisher gibt es nur die seite ‚Lose', welche nur bei seriennummern geführten
 * Teilen greift."
 *
 * Der Kern dieser Tests ist deshalb: Sammelbestand OHNE Lose muss hier
 * auftauchen -- genau das konnte die Lose-Liste nicht.
 * ─────────────────────────────────────────────────────────────────────────────
 */
#[Group('rendering')]
final class StockOverviewTest extends TestCase
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

        $this->bootWithModules();

        app(AccessSetup::class)->run();
    }

    #[Test]
    public function bulk_stock_without_any_lot_shows_up_here(): void
    {
        $schrauben = PartType::create([
            'name' => 'Sechskantschraube M6',
            'classification' => PartClassification::StandardPart,
            'unit_of_measure' => 'Stk',
        ]);

        app(ReceiveStock::class)->handle(
            partType: $schrauben,
            quantity: 500,
            receivedAt: now()->toDateString(),
            user: $this->storekeeper(),
        );

        $this->actingAs($this->viewer());

        Livewire::test(StockOverview::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$schrauben]);
    }

    #[Test]
    public function a_type_nobody_ever_stocked_stays_out_of_the_way(): void
    {
        // Ein Katalog aller je angelegten Bauteiltypen wäre die
        // Stammdatenliste -- hier steht, was da ist oder fehlt.
        $nie = PartType::create([
            'name' => 'Nie bestellt',
            'classification' => PartClassification::StandardPart,
            'unit_of_measure' => 'Stk',
        ]);

        $this->actingAs($this->viewer());

        Livewire::test(StockOverview::class)
            ->assertOk()
            ->assertCanNotSeeTableRecords([$nie]);
    }

    #[Test]
    public function a_type_with_a_minimum_is_shown_even_when_empty(): void
    {
        // Was fehlt, ist die wichtigere Hälfte der Frage "was ist im Lager".
        $leer = PartType::create([
            'name' => 'Bremsbelag',
            'classification' => PartClassification::StandardPart,
            'unit_of_measure' => 'Stk',
            'minimum_stock' => 4,
        ]);

        $this->actingAs($this->viewer());

        Livewire::test(StockOverview::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$leer]);
    }

    #[Test]
    public function it_needs_the_stock_view_permission(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true]));

        $this->assertFalse(StockOverview::canAccess());
    }

    private function viewer(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::STOCK_VIEW);

        return $user->fresh();
    }

    private function storekeeper(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::STOCK_RECEIVE);

        return $user->fresh();
    }
}
