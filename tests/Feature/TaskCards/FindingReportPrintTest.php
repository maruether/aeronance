<?php

declare(strict_types=1);

namespace Tests\Feature\TaskCards;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Actions\ManageFindingReport;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Der Befundbericht auf Papier.
 *
 * Das Blatt wird unterschrieben und abgeheftet — es muss deshalb ohne
 * Datenbank lesbar sein: welches Luftfahrzeug, welcher Vorgang, welche Punkte,
 * und wer sie erledigt, kontrolliert und geprüft hat.
 */
final class FindingReportPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();
        app(ModuleManager::class)->enable('fleet');
        app(ModuleManager::class)->enable('taskcards');
        app(ModuleManager::class)->forgetCache();

        foreach ([
            Permissions::CARDS_WORK,
            Permissions::FINDINGS_RECORD,
            Permissions::WORK_ORDERS_MANAGE,
            Permissions::WORK_ORDERS_VIEW,
        ] as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    #[Test]
    public function the_sheet_carries_its_columns_points_and_signatures(): void
    {
        $order = $this->orderWithPoints();

        $this->actingAs($this->userWith(Permissions::WORK_ORDERS_VIEW))
            ->get(route('taskcards.finding-report', ['workOrder' => $order]))
            ->assertSuccessful()
            ->assertSee('Befundbericht', false)

            // Der Kopf: welches Luftfahrzeug, welches Blatt.
            ->assertSee('D-KABC')
            ->assertSee($order->number)

            // Die Gliederung, wie sie das Papier vorgibt.
            ->assertSee('Lfd. Nr.', false)
            ->assertSee('Art der Beanstandung, Bericht oder Befund', false)
            ->assertSee('Art der Behebung, Bemerkungen', false)
            ->assertSee('Erledigung', false)
            ->assertSee('Kontrolle', false)
            ->assertSee('Prüfung', false)

            // Die Punkte selbst -- mit der Unterschrift, die an der Karte steht.
            ->assertSee('Riss in der Haube', false)
            ->assertSee('Reifen abgefahren', false)
            ->assertSee('Hilde Hobel')

            // Die vorgedruckte letzte Zeile und die Unterschriftsblöcke.
            ->assertSee('Fremdkörperkontrolle und Werkzeugkontrolle', false)
            ->assertSee('Bericht erstellt', false)
            ->assertSee('Abschließend geprüft', false)

            /*
             * Der Druckknopf muss unter der CSP leben: ein onclick-Attribut
             * wäre inline-Skript und bliebe stumm. Dieselbe Falle wie bei der
             * Freigabebescheinigung.
             */
            ->assertSee('/js/print-button.js', false)
            ->assertDontSee('onclick=', false);
    }

    #[Test]
    public function without_a_permission_the_sheet_stays_shut(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->get(route('taskcards.finding-report', ['workOrder' => $this->orderWithPoints()]))
            ->assertForbidden();
    }

    private function orderWithPoints(): WorkOrder
    {
        $aircraft = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);

        $order = app(ManageWorkOrder::class)->open(
            $aircraft,
            'Jahresnachprüfung',
            $this->userWith(Permissions::WORK_ORDERS_MANAGE),
        );

        $cards = app(ManageFindingReport::class)->record(
            order: $order,
            points: [
                ['title' => 'Riss in der Haube', 'description' => 'Vorne links, etwa 3 cm.'],
                ['title' => 'Reifen abgefahren', 'description' => 'Hauptrad, Verschleißgrenze.'],
            ],
            user: $this->userWith(Permissions::FINDINGS_RECORD, Permissions::CARDS_WORK),
        );

        $cards[0]->update([
            'work_performed' => 'Haube ausgebaut, Riss gestoppt und laminiert.',
            'completed_by_name' => 'Hilde Hobel',
            'completed_at' => now(),
        ]);

        return $order->fresh();
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }
}
