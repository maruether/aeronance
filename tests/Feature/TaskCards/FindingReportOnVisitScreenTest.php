<?php

declare(strict_types=1);

namespace Tests\Feature\TaskCards;

use App\Core\Access\AccessSetup;
use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Modules\TaskCards\Models\Finding;
use App\Modules\TaskCards\Models\TaskCard;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Permissions;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RendersModulePages;
use Tests\TestCase;

/**
 * Der Befundbericht auf der Vorgangsseite — wirklich gerendert, wirklich
 * abgeschickt.
 *
 * Die Aktion und das Blatt sind an anderer Stelle einzeln geprüft. Was nur hier
 * auffällt: ob die Seite mit dem eingebetteten Blatt überhaupt noch baut. Ein
 * Fehler in einer Blade-Vorlage macht keine Testzeile rot -- er macht die
 * meistbenutzte Seite des Moduls weiss.
 */
#[Group('rendering')]
final class FindingReportOnVisitScreenTest extends TestCase
{
    use RendersModulePages;

    /** @return list<string> */
    protected function modulesUnderTest(): array
    {
        return ['fleet', 'taskcards'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootWithModules();

        app(AccessSetup::class)->run();
    }

    #[Test]
    public function the_visit_page_shows_the_sheet(): void
    {
        $order = $this->workOrder();

        $this->actingAs($this->worker());

        Livewire::test(ViewWorkOrder::class, ['record' => $order->getKey()])
            ->assertOk()
            ->assertSee('Befundbericht')
            ->assertSee('Lfd. Nr.', false)
            ->assertSee('Fremdkörperkontrolle und Werkzeugkontrolle', false);
    }

    #[Test]
    public function the_action_turns_every_point_into_its_own_card(): void
    {
        $order = $this->workOrder();

        $this->actingAs($this->worker());

        // Repeater::fake(): ohne das verschluesselt Filament die Zeilen unter
        // UUIDs, und die Testdaten erreichen den Repeater nie.
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(ViewWorkOrder::class, ['record' => $order->getKey()])
            ->callAction('findingReport', data: [
                'found_on' => now()->toDateString(),
                'points' => [
                    ['title' => 'Riss in der Haube', 'description' => 'Vorne links, etwa 3 cm.'],
                    ['title' => 'Reifen abgefahren', 'description' => 'Hauptrad, Verschleißgrenze.'],
                ],
            ])
            ->assertHasNoActionErrors();

        $undoRepeaterFake();

        $this->assertSame(2, Finding::query()->count());
        $this->assertSame(2, TaskCard::query()->count());
        $this->assertSame(2, Finding::query()->distinct()->count('resolving_task_card_id'));
    }

    private function workOrder(): WorkOrder
    {
        $aircraft = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);

        return app(ManageWorkOrder::class)->open(
            $aircraft,
            'Jahresnachprüfung',
            $this->worker(),
        );
    }

    private function worker(): User
    {
        $user = User::factory()->create(['is_active' => true]);

        $user->givePermissionTo(
            Permissions::WORK_ORDERS_VIEW,
            Permissions::WORK_ORDERS_MANAGE,
            Permissions::FINDINGS_RECORD,
            Permissions::CARDS_WORK,
        );

        return $user->fresh();
    }
}
