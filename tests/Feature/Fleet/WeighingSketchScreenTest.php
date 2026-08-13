<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Core\Access\AccessSetup;
use App\Models\User;
use App\Modules\Fleet\Enums\WeighingKind;
use App\Modules\Fleet\Filament\Resources\Weighings\Pages\EditWeighing;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\Weighing;
use App\Modules\Fleet\Models\WeighingEntry;
use App\Modules\Fleet\Permissions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RendersModulePages;
use Tests\TestCase;

/**
 * Die Hebelskizze in der MASKE, nicht nur auf dem Papier.
 *
 * Feldtest: "bei den wägungen will ich die grafik nicht nur beim drucken,
 * sondern auch in der maske haben." Und ihre Abwesenheit muss ehrlich
 * bleiben: Ohne die zwei gespeicherten Auflagen gibt es nichts zu zeichnen.
 */
#[Group('rendering')]
final class WeighingSketchScreenTest extends TestCase
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
    public function the_edit_screen_draws_the_sketch_for_a_two_point_weighing(): void
    {
        $weighing = $this->gliderWeighing();
        $this->support($weighing, 'Hauptrad', 248.0, 2.0);
        $this->support($weighing, 'Sporn', 32.0, 1.0);

        $this->actingAs($this->manager());

        // Der eindeutige Marker ist das SVG selbst: stroke-dasharray gibt
        // es nur in der Zeichnung -- "B.P." steht auch in Feldbeschriftungen.
        Livewire::test(EditWeighing::class, ['record' => $weighing->getKey()])
            ->assertOk()
            ->assertSee('stroke-dasharray', escape: false)
            ->assertSee('G1', escape: false);
    }

    #[Test]
    public function without_two_supports_there_is_honestly_nothing_to_draw(): void
    {
        $weighing = $this->gliderWeighing();
        $this->support($weighing, 'Hauptrad', 248.0, 2.0);

        $this->actingAs($this->manager());

        // assertOk zuerst: Ein 403 enthielte den Marker auch nicht -- der
        // Test waere gruen und pruefte nichts (beim ersten Anlauf passiert).
        Livewire::test(EditWeighing::class, ['record' => $weighing->getKey()])
            ->assertOk()
            ->assertDontSee('stroke-dasharray', escape: false);
    }

    private function gliderWeighing(): Weighing
    {
        $aircraft = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);

        return Weighing::create([
            'aircraft_id' => $aircraft->id,
            'kind' => WeighingKind::Glider,
            'weighed_at' => now()->toDateString(),
            'front_support_arm_mm' => 150,
            'support_distance_mm' => 4200,
        ]);
    }

    private function support(Weighing $w, string $label, float $gross, float $tare): WeighingEntry
    {
        return WeighingEntry::create([
            'weighing_id' => $w->id,
            'section' => WeighingEntry::SECTION_SUPPORT,
            'label' => $label,
            'gross_kg' => $gross,
            'tare_kg' => $tare,
        ]);
    }

    private function manager(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        // Bearbeiten verlangt das Nachpruefungs-Recht -- siehe WeighingResource.
        $user->givePermissionTo(Permissions::FLEET_VIEW, Permissions::REVIEWS_RECORD);

        return $user->fresh();
    }
}
