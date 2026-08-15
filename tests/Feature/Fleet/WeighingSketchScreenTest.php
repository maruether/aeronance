<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Core\Access\AccessSetup;
use App\Models\User;
use App\Modules\Fleet\Actions\PrepareWeighing;
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
    public function a_glider_gets_the_lever_sketch(): void
    {
        $weighing = $this->gliderWeighing();
        $this->support($weighing, 'Auflage vorn G1', 248.0, 2.0);
        $this->support($weighing, 'Auflage hinten G2', 32.0, 1.0);

        $this->actingAs($this->manager());

        // Der eindeutige Marker ist das SVG selbst: stroke-dasharray gibt
        // es nur in der Zeichnung -- "B.P." steht auch in Feldbeschriftungen.
        Livewire::test(EditWeighing::class, ['record' => $weighing->getKey()])
            ->assertOk()
            ->assertSee('stroke-dasharray', escape: false)
            ->assertSee('Hebelarme hinter dem Bezugspunkt', escape: false);
    }

    #[Test]
    public function a_powered_aircraft_gets_the_moment_sketch_not_the_lever(): void
    {
        /*
         * DER FELDTEST-FEHLER: Motorflugzeuge stehen auf DREI Auflagen
         * (WeighingKind::defaultSupports), und die alte Fassung zeichnete nur
         * bei exakt zwei -- an einer Robin erschien nie eine Skizze. Und die
         * Hebelzeichnung waere dort auch das falsche Bild: Das Motorflugblatt
         * rechnet ueber Momente, jede Auflage mit eigenem Arm.
         */
        $weighing = $this->poweredWeighing();
        $this->support($weighing, 'Auflage links G1l', 210.0, 0.0, 1200.0);
        $this->support($weighing, 'Auflage rechts G1r', 208.0, 0.0, 1200.0);
        $this->support($weighing, 'Auflage vorn G2', 120.0, 0.0, -400.0);

        $this->actingAs($this->manager());

        Livewire::test(EditWeighing::class, ['record' => $weighing->getKey()])
            ->assertOk()
            ->assertSee('stroke-dasharray', escape: false)
            ->assertSee('Drei Auflagen, drei Hebelarme', escape: false)
            ->assertDontSee('Hebelarme hinter dem Bezugspunkt', escape: false);
    }

    #[Test]
    public function without_supports_the_screen_says_what_is_missing(): void
    {
        // Eine leere Stelle sieht aus wie ein Fehler -- und wurde genau so
        // gemeldet. Also sagt sie, worauf sie wartet.
        $weighing = $this->gliderWeighing();

        $this->actingAs($this->manager());

        Livewire::test(EditWeighing::class, ['record' => $weighing->getKey()])
            ->assertOk()
            ->assertDontSee('stroke-dasharray', escape: false)
            ->assertSee(__('fleet.weighing.sketch_pending'), escape: false);
    }

    #[Test]
    public function a_prepared_sheet_already_carries_its_supports(): void
    {
        /*
         * DER GRUND, WARUM NIE EINE SKIZZE ERSCHIEN: Die Auflagen entstanden
         * nirgends. WeighingKind::defaultSupports() gab es, aufgerufen hat es
         * niemand -- und ohne Auflagen zeichnet weder Maske noch Druck.
         * Feldtest, dreimal gemeldet.
         */
        $aircraft = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);

        $weighing = app(PrepareWeighing::class)->from($aircraft, $this->manager());

        $this->assertCount(
            2,
            $weighing->entriesOf(WeighingEntry::SECTION_SUPPORT),
            'Ein Segelflugzeug steht auf zwei Auflagen -- die gehören aufs Blatt.',
        );

        $motor = Aircraft::create(['registration' => 'D-EABC', 'model' => 'DR 400/180']);
        $motorblatt = app(PrepareWeighing::class)->from($motor, $this->manager(), WeighingKind::Powered);

        $this->assertCount(3, $motorblatt->entriesOf(WeighingEntry::SECTION_SUPPORT));
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

    private function poweredWeighing(): Weighing
    {
        $aircraft = Aircraft::create(['registration' => 'D-EABC', 'model' => 'DR 400/180']);

        return Weighing::create([
            'aircraft_id' => $aircraft->id,
            'kind' => WeighingKind::Powered,
            'weighed_at' => now()->toDateString(),
        ]);
    }

    private function support(
        Weighing $w,
        string $label,
        float $gross,
        float $tare,
        ?float $arm = null,
    ): WeighingEntry {
        return WeighingEntry::create([
            'weighing_id' => $w->id,
            'section' => WeighingEntry::SECTION_SUPPORT,
            'label' => $label,
            'gross_kg' => $gross,
            'tare_kg' => $tare,
            'arm_mm' => $arm,
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
