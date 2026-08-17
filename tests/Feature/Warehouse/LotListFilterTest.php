<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Access\AccessSetup;
use App\Models\User;
use App\Modules\Warehouse\Actions\IssueStock;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Filament\Resources\StockLots\Pages\ListStockLots;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RendersModulePages;
use Tests\TestCase;

/**
 * Der Filter „nur mit Bestand" in der Loseliste.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Feldtest: „unter lose funktioniert der filter ‚nur mit bestand' nicht. es
 * werden lose mit menge 0 angezeigt."
 *
 * Die Ursache war ein Denkfehler, kein Tippfehler: Geprüft wurde, ob das Los
 * mindestens EINE Buchung hat -- und ein leergeräumtes Los hat deren zwei. Der
 * Filter zeigte also am zuverlässigsten genau das, was er ausblenden sollte.
 *
 * Dieser Test hält deshalb den Unterschied fest, an dem es hing: ein Los mit
 * Buchungen, aber ohne Bestand.
 * ─────────────────────────────────────────────────────────────────────────────
 */
#[Group('rendering')]
final class LotListFilterTest extends TestCase
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
    public function a_lot_that_has_been_emptied_is_hidden_although_it_has_movements(): void
    {
        $teil = $this->losgefuehrtesTeil();
        $lagerist = $this->lagerist();

        // Eines bleibt liegen, eines wird vollständig entnommen.
        app(ReceiveStock::class)->handle(
            partType: $teil, quantity: 4, receivedAt: now()->toDateString(), user: $lagerist,
        );
        $voll = StockLot::latest('id')->first();

        app(ReceiveStock::class)->handle(
            partType: $teil, quantity: 2, receivedAt: now()->toDateString(), user: $lagerist,
        );
        $leer = StockLot::latest('id')->first();

        app(IssueStock::class)->handle(
            partType: $teil, quantity: 2, lot: $leer, user: $lagerist,
        );

        $this->assertSame(0.0, $leer->fresh()->remainingQuantity(), 'Aufbau stimmt nicht.');
        $this->assertGreaterThan(1, $leer->movements()->count(), 'Es braucht MEHRERE Buchungen.');

        $this->actingAs($this->leser());

        // Der Filter ist ab Werk an.
        Livewire::test(ListStockLots::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$voll])
            ->assertCanNotSeeTableRecords([$leer]);
    }

    #[Test]
    public function without_the_filter_the_emptied_lot_is_there_again(): void
    {
        // Es ist nicht verschwunden, nur ausgeblendet -- die Lebenslaufakte
        // eines Loses bleibt lesbar, auch wenn nichts mehr da ist.
        $teil = $this->losgefuehrtesTeil();
        $lagerist = $this->lagerist();

        app(ReceiveStock::class)->handle(
            partType: $teil, quantity: 2, receivedAt: now()->toDateString(), user: $lagerist,
        );
        $leer = StockLot::sole();

        app(IssueStock::class)->handle(
            partType: $teil, quantity: 2, lot: $leer, user: $lagerist,
        );

        $this->actingAs($this->leser());

        Livewire::test(ListStockLots::class)
            ->filterTable('in_stock', false)
            ->assertCanSeeTableRecords([$leer]);
    }

    private function losgefuehrtesTeil(): PartType
    {
        /*
         * Losgefuehrt wird ein Teil ueber Form-1-Pflicht, Seriennummer ODER
         * Haltbarkeit -- die Bauart allein reicht nicht. Hier die Haltbarkeit,
         * weil sie ein Los erzeugt, ohne beim Einbuchen einen Nachweis zu
         * verlangen; geprueft werden soll der Filter, nicht die Nachweiskette.
         */
        return PartType::create([
            'name' => 'Ölfilter',
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'Stk',
            'shelf_life_days' => 1095,
        ]);
    }

    private function lagerist(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::STOCK_RECEIVE);
        $user->givePermissionTo(Permissions::STOCK_ISSUE);

        return $user->fresh();
    }

    private function leser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::STOCK_VIEW);

        return $user->fresh();
    }
}
