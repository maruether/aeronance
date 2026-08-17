<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Core\Access\AccessSetup;
use App\Models\User;
use App\Modules\Fleet\Actions\SeedWeighingSheet;
use App\Modules\Fleet\Enums\SheetVariant;
use App\Modules\Fleet\Enums\Undercarriage;
use App\Modules\Fleet\Filament\Resources\Weighings\Pages\EditWeighing;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\Weighing;
use App\Modules\Fleet\Permissions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RendersModulePages;
use Tests\TestCase;

#[Group('rendering')]
final class WeighingSheetScreenTest extends TestCase
{
    use RendersModulePages;

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
    public function the_sheet_renders_as_a_sheet_with_its_tables(): void
    {
        $blatt = $this->blatt();

        $this->actingAs($this->pfleger());

        Livewire::test(EditWeighing::class, ['record' => $blatt->getKey()])
            ->assertOk()
            ->assertSee('WÄGUNG', escape: false)
            ->assertSee('MASSENGRENZEN', escape: false)
            ->assertSee('SCHWERPUNKTERMITTLUNG', escape: false)
            ->assertSee('Tragwerk rechts innen')
            ->assertSee('wire:model.blur="bauteile.0.mass_kg"', escape: false);
    }

    #[Test]
    public function typing_into_the_sheet_and_saving_keeps_the_decimals(): void
    {
        $blatt = $this->blatt();

        Livewire::actingAs($this->pfleger())
            ->test(EditWeighing::class, ['record' => $blatt->getKey()])
            ->set('bauteile.0.mass_kg', '62,4')
            ->set('kopf.max_mass_kg', '525')
            ->call('speichern')
            ->assertHasNoErrors();

        $frisch = $blatt->fresh(['entries']);

        $this->assertSame('62.40', $frisch->entriesOf('component')->first()->mass_kg);
        $this->assertSame('525.00', $frisch->max_mass_kg);
    }

    private function blatt(): Weighing
    {
        $aircraft = Aircraft::create(['registration' => 'D-7777', 'model' => 'ASK 21']);

        $blatt = Weighing::create([
            'aircraft_id' => $aircraft->id,
            'kind' => SheetVariant::Glider->kind(),
            'sheet_variant' => SheetVariant::Glider,
            'undercarriage' => Undercarriage::TailwheelOneMain,
            'weighed_at' => now()->toDateString(),
        ]);

        return app(SeedWeighingSheet::class)->handle($blatt);
    }

    private function pfleger(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::FLEET_VIEW);
        $user->givePermissionTo(Permissions::REVIEWS_RECORD);

        return $user->fresh();
    }
}
