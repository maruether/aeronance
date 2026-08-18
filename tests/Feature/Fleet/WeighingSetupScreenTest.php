<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Core\Access\AccessSetup;
use App\Models\User;
use App\Modules\Fleet\Actions\PrepareWeighing;
use App\Modules\Fleet\Enums\SheetVariant;
use App\Modules\Fleet\Enums\Undercarriage;
use App\Modules\Fleet\Enums\WeighingKind;
use App\Modules\Fleet\Filament\Resources\Weighings\Pages\EditWeighing;
use App\Modules\Fleet\Filament\Resources\Weighings\Pages\ListWeighings;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AircraftType;
use App\Modules\Fleet\Models\Weighing;
use App\Modules\Fleet\Models\WeighingEntry;
use App\Modules\Fleet\Permissions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RendersModulePages;
use Tests\TestCase;

/**
 * Die Abfrage beim Anlegen — durch die Oberfläche, nicht nur durch die Aktion.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Der gemeldete Fehler lag nicht in der Fachlogik, sondern im Dialog: Er fragte
 * nicht. Ein Test, der nur PrepareWeighing aufruft, hätte ihn nie gefunden --
 * die Aktion konnte immer schon eine Blattart entgegennehmen, es hat sie ihr
 * nur niemand gegeben. Also wird hier wirklich abgesendet.
 * ─────────────────────────────────────────────────────────────────────────────
 */
#[Group('rendering')]
final class WeighingSetupScreenTest extends TestCase
{
    use RendersModulePages;

    /** @return list<string> */
    protected function modulesUnderTest(): array
    {
        return ['fleet'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootWithModules();

        app(AccessSetup::class)->run();
    }

    #[Test]
    public function the_dialog_asks_for_the_sheet_and_the_type_can_learn_it(): void
    {
        $muster = AircraftType::create(['designation' => 'Aquila AT01']);

        $lfz = Aircraft::create([
            'registration' => 'D-EICC',
            'model' => 'Aquila AT01',
            'aircraft_type_id' => $muster->id,
        ]);

        $this->actingAs($this->manager());

        Livewire::test(ListWeighings::class)
            ->callAction('prepare', data: [
                'aircraft_id' => $lfz->getKey(),
                'sheet_variant' => SheetVariant::Aeroplane->value,
                'undercarriage' => Undercarriage::Tricycle->value,
                'remember_on_type' => true,
            ])
            ->assertHasNoActionErrors();

        $blatt = Weighing::sole();

        $this->assertSame(SheetVariant::Aeroplane, $blatt->sheet_variant);
        $this->assertSame(WeighingKind::Powered, $blatt->kind);
        $this->assertCount(3, $blatt->load('entries')->entriesOf(WeighingEntry::SECTION_SUPPORT));

        // „Noch besser wäre wenn Diese Daten direkt im Muster hinterlegt
        // werden könnten." -- der Haken tut genau das.
        $muster->refresh();

        $this->assertSame(SheetVariant::Aeroplane, $muster->sheet_variant);
        $this->assertSame(Undercarriage::Tricycle, $muster->undercarriage);
    }

    #[Test]
    public function a_sheet_of_the_wrong_kind_can_be_switched_on_its_own_page(): void
    {
        $lfz = Aircraft::create(['registration' => 'D-EICC', 'model' => 'Aquila AT01']);

        // Ein Blatt, wie es der Fehler erzeugt hat.
        $blatt = app(PrepareWeighing::class)->from($lfz);

        $this->assertSame(SheetVariant::Glider, $blatt->sheet_variant);

        $this->actingAs($this->manager());

        Livewire::test(EditWeighing::class, ['record' => $blatt->getKey()])
            ->callAction('blattart', data: [
                'sheet_variant' => SheetVariant::Aeroplane->value,
                'undercarriage' => Undercarriage::Tricycle->value,
            ])
            ->assertHasNoActionErrors();

        $blatt->refresh()->load('entries');

        $this->assertSame(SheetVariant::Aeroplane, $blatt->sheet_variant);
        $this->assertSame(WeighingKind::Powered, $blatt->kind);
        $this->assertCount(3, $blatt->entriesOf(WeighingEntry::SECTION_SUPPORT));
    }

    private function manager(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        // Bearbeiten verlangt das Nachpruefungs-Recht -- siehe WeighingResource.
        $user->givePermissionTo(Permissions::FLEET_VIEW, Permissions::REVIEWS_RECORD);

        return $user->fresh();
    }
}
