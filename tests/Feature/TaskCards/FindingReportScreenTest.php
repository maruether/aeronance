<?php

declare(strict_types=1);

namespace Tests\Feature\TaskCards;

use App\Core\Access\AccessSetup;
use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Actions\RecordFinding;
use App\Modules\TaskCards\Enums\FindingState;
use App\Modules\TaskCards\Filament\Resources\Findings\Pages\ListFindings;
use App\Modules\TaskCards\Models\Finding;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Permissions;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RendersModulePages;
use Tests\TestCase;

/**
 * Der Befundbericht durch die Oberfläche -- nicht nur durch die Aktion.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Zwei Wege, beide wirklich ausgelöst: das Meldeformular mit mehreren
 * Punkten, und die Sammel-Aktion, die aus angehakten Befunden EINE Karte
 * macht. „Der Bildschirm baut" reicht nicht -- die Fehler dieser Sorte
 * liegen im Absenden (siehe RepairPageDispatchTest, derselbe Grund).
 * ─────────────────────────────────────────────────────────────────────────────
 */
#[Group('rendering')]
final class FindingReportScreenTest extends TestCase
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
    public function the_report_button_on_the_list_files_every_point(): void
    {
        // Feldtest: keine eigene Seite -- der Knopf wohnt an der Befundliste,
        // und das Melderecht allein oeffnet sie (canViewAny).
        $aircraft = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);

        $this->actingAs($this->reporter($aircraft));

        // Repeater::fake(): ohne das verschluesselt Filament die Zeilen unter
        // UUIDs, und die Testdaten erreichen den Repeater nie -- die leere
        // Standardzeile bliebe stehen und fiele durch die Pflichtfelder.
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(ListFindings::class)
            ->callAction('report', data: [
                'aircraft_id' => $aircraft->getKey(),
                'found_on' => now()->toDateString(),
                'points' => [
                    ['title' => 'Riss in der Haube', 'description' => 'Vorne links, 3 cm.'],
                    ['title' => 'Reifen abgefahren', 'description' => 'Hauptrad, Verschleißgrenze.'],
                ],
            ])
            ->assertHasNoActionErrors();

        $undoRepeaterFake();

        $this->assertSame(2, Finding::query()->count());
        $this->assertTrue(Finding::query()->get()->every(
            fn (Finding $f): bool => $f->state === FindingState::Open
                && $f->is_blocking
                && $f->reported_qualification_reference === 'SPL-DE-4711',
        ));
    }

    #[Test]
    public function the_bulk_action_raises_one_card_and_can_open_the_visit(): void
    {
        $aircraft = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);
        $melder = $this->reporter($aircraft);

        $a = app(RecordFinding::class)->report(
            aircraft: $aircraft, title: 'Riss in der Haube',
            description: 'Vorne links.', user: $melder,
        );
        $b = app(RecordFinding::class)->report(
            aircraft: $aircraft, title: 'Reifen abgefahren',
            description: 'Hauptrad.', user: $melder,
        );

        $this->actingAs($this->userWith(
            Permissions::WORK_ORDERS_VIEW,
            Permissions::WORK_ORDERS_MANAGE,
        ));

        // Kein offener Vorgang: Der Dialog bietet „neu eröffnen" vorbelegt an,
        // und genau dieser Weg läuft hier durch.
        Livewire::test(ListFindings::class)
            ->callTableBulkAction('raiseCard', [$a, $b], data: [
                'open_new_order' => true,
            ]);

        $order = WorkOrder::query()->firstOrFail();

        $this->assertSame(1, $order->taskCards()->count());
        $this->assertSame(FindingState::Scheduled, $a->fresh()->state);
        $this->assertSame(FindingState::Scheduled, $b->fresh()->state);
        $this->assertSame(
            (int) $order->taskCards()->first()->id,
            (int) $a->fresh()->resolving_task_card_id,
        );
    }

    #[Test]
    public function a_refused_bulk_leaves_no_orphan_visit(): void
    {
        // Review-Fund: Ohne gemeinsame Transaktion blieb nach einer
        // gemischten Auswahl ein frisch eröffneter, leerer Vorgang stehen --
        // Nummer verbraucht, null Karten.
        $eins = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);
        $zwei = Aircraft::create(['registration' => 'D-KXYZ', 'model' => 'DG-300']);

        $a = app(RecordFinding::class)->report(
            aircraft: $eins, title: 'Riss', description: 'Haube.',
            user: $this->reporter($eins),
        );
        $b = app(RecordFinding::class)->report(
            aircraft: $zwei, title: 'Delle', description: 'Fläche.',
            user: $this->reporter($zwei),
        );

        $this->actingAs($this->userWith(
            Permissions::WORK_ORDERS_VIEW,
            Permissions::WORK_ORDERS_MANAGE,
        ));

        Livewire::test(ListFindings::class)
            ->callTableBulkAction('raiseCard', [$a, $b], data: [
                'open_new_order' => true,
            ]);

        $this->assertSame(0, WorkOrder::query()->count());
        $this->assertSame(FindingState::Open, $a->fresh()->state);
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function reporter(Aircraft $aircraft): User
    {
        $user = $this->userWith(Permissions::FINDINGS_REPORT);

        Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PILOT_OWNER,
            'scope' => $aircraft->registration,
            'reference' => 'SPL-DE-4711',
            'valid_from' => now()->subYear()->toDateString(),
            'valid_until' => now()->addYear()->toDateString(),
        ]);

        return $user->fresh();
    }
}
