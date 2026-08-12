<?php

declare(strict_types=1);

namespace Tests\Feature\Inspection;

use App\Core\Access\AccessSetup;
use App\Models\User;
use App\Modules\Inspection\Enums\CheckResult;
use App\Modules\Inspection\Enums\InspectionState;
use App\Modules\Inspection\Filament\Resources\IncomingInspections\Pages\ListIncomingInspections;
use App\Modules\Inspection\Models\IncomingInspection;
use App\Modules\Inspection\Permissions;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockMovement;
use App\Modules\Warehouse\Permissions as WarehousePermissions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RendersModulePages;
use Tests\TestCase;

/**
 * Die Eingangsprüfung, wie sie tatsächlich bedient wird.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Der Unterschied zu IncomingInspectionTest: Dort wird die Aktion direkt
 * gerufen, hier geht es durch den Dialog. Beides ist nötig — die Regeln in der
 * Aktion nützen nichts, wenn das Formular danebengreift, und ein grüner
 * Formulartest sagt nichts über die Regeln.
 * ─────────────────────────────────────────────────────────────────────────────
 */
#[Group('rendering')]
final class InspectionScreenTest extends TestCase
{
    use RendersModulePages;

    /** @return list<string> */
    protected function modulesUnderTest(): array
    {
        return ['warehouse', 'inspection'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootWithModules();

        app(AccessSetup::class)->run();
    }

    #[Test]
    public function the_dialogue_signs_the_inspection_off_and_releases_the_goods(): void
    {
        $bewegung = $this->receive();
        $pruefung = IncomingInspection::query()->where('stock_movement_id', $bewegung->id)->firstOrFail();

        $this->actingAs($this->inspector());

        Livewire::test(ListIncomingInspections::class)
            ->callTableAction('inspect', $pruefung, [
                'answers' => $this->allPass($pruefung),
                'outcome' => InspectionState::Accepted->value,
                'note' => null,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(InspectionState::Accepted, $pruefung->fresh()->state);
        $this->assertSame(LotState::Serviceable, $bewegung->lot->fresh()->state);
    }

    /**
     * Und die Feldnamen im Formular passen wirklich auf die Aktion.
     *
     * Das ist der Test, der beim Umbenennen eines Feldes anschlaegt: Wuerde
     * `answers.<punkt>.result` nicht mehr ankommen, bliebe die Pruefung
     * unbeantwortet und die Sperre stehen -- ohne dass irgendwo ein Fehler
     * sichtbar waere.
     */
    #[Test]
    public function a_gap_in_the_list_stops_the_signature(): void
    {
        $bewegung = $this->receive();
        $pruefung = IncomingInspection::query()->where('stock_movement_id', $bewegung->id)->firstOrFail();

        $antworten = $this->allPass($pruefung);
        array_shift($antworten);

        $this->actingAs($this->inspector());

        Livewire::test(ListIncomingInspections::class)
            ->callTableAction('inspect', $pruefung, [
                'answers' => $antworten,
                'outcome' => InspectionState::Accepted->value,
                'note' => null,
            ]);

        $this->assertSame(InspectionState::Open, $pruefung->fresh()->state);
        $this->assertSame(
            LotState::Quarantined,
            $bewegung->lot->fresh()->state,
            'Solange die Liste Lücken hat, bleibt die Ware gesperrt.',
        );
    }

    /**
     * Wer nur zusehen darf, sieht den Knopf nicht.
     */
    #[Test]
    public function a_read_only_user_gets_no_button(): void
    {
        $bewegung = $this->receive();
        $pruefung = IncomingInspection::query()->where('stock_movement_id', $bewegung->id)->firstOrFail();

        $zuschauer = User::factory()->create(['is_active' => true]);
        $zuschauer->givePermissionTo(Permissions::INSPECTION_VIEW);

        $this->actingAs($zuschauer->fresh());

        Livewire::test(ListIncomingInspections::class)
            ->assertTableActionHidden('inspect', $pruefung);
    }

    private function receive(): StockMovement
    {
        $teil = PartType::create([
            'name' => 'Bremszylinder',
            'classification' => PartClassification::Component,
            'unit' => 'Stk',
            'requires_form_one' => true,
            'serial_tracked' => true,
        ]);

        return app(ReceiveStock::class)->handle($teil, 1, now()->toDateString(), null, [
            'serial_number' => 'SN-4711',
            'document_type' => 'form_one',
            'document_reference' => 'F1-2026-0815',
        ]);
    }

    /** @return array<string, array{result: string, note: null}> */
    private function allPass(IncomingInspection $inspection): array
    {
        $antworten = [];

        foreach ($inspection->checks as $check) {
            $antworten[$check->item->value] = ['result' => CheckResult::Pass->value, 'note' => null];
        }

        return $antworten;
    }

    /**
     * Seit dem Feldtest braucht die Annahme das RELEASE-Recht und keine
     * Lizenz mehr -- die Eingangspruefung ist Papier- und Zustandspruefung,
     * keine Freigabe. Dass hier keine Qualification mehr steht, ist die
     * Aussage (wie im qualifiedInspector von IncomingInspectionTest).
     */
    private function inspector(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::INSPECTION_VIEW);
        $user->givePermissionTo(Permissions::INSPECTION_PERFORM);
        $user->givePermissionTo(WarehousePermissions::STOCK_QUARANTINE);
        $user->givePermissionTo(WarehousePermissions::STOCK_QUARANTINE_RELEASE);

        return $user->fresh();
    }
}
