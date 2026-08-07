<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Fleet\Filament\Pages\DuePage;
use App\Modules\Fleet\Filament\Resources\Aircraft\AircraftResource;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AirworthinessReview;
use App\Modules\Fleet\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The fleet's screens, and above all who cannot reach them.
 *
 * Same two layers as the warehouse (D3): a module that was off at boot has no
 * routes at all, and a screen somebody lacks the permission for is unreachable
 * rather than merely hidden.
 */
final class FleetInterfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();
        app(ModuleManager::class)->enable('fleet');
        app(ModuleManager::class)->forgetCache();
    }

    #[Test]
    public function a_module_that_was_off_at_boot_has_no_routes(): void
    {
        $this->assertFalse(Route::has('filament.admin.resources.aircraft.index'));
    }

    #[Test]
    public function without_the_permission_the_screens_do_not_exist(): void
    {
        $this->actingAs($this->userWith());

        $this->assertFalse(AircraftResource::canViewAny());
        $this->assertFalse(DuePage::canAccess());
    }

    #[Test]
    public function viewing_and_managing_are_different_rights(): void
    {
        // Reading an instrument is a routine act at the hangar door; adding an
        // aircraft is a master-data decision with regulatory weight.
        $this->actingAs($this->userWith(Permissions::FLEET_VIEW));

        $this->assertTrue(AircraftResource::canViewAny());
        $this->assertFalse(AircraftResource::canCreate());

        $this->actingAs($this->userWith(Permissions::FLEET_VIEW, Permissions::FLEET_MANAGE));

        $this->assertTrue(AircraftResource::canCreate());
    }

    #[Test]
    public function an_aircraft_is_taken_out_of_service_never_deleted(): void
    {
        // Its life record is the point of keeping it: what was fitted, what was
        // signed, what ran out. Deleting one takes all of that with it.
        $this->actingAs($this->userWith(Permissions::FLEET_VIEW, Permissions::FLEET_MANAGE));

        $aircraft = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);

        $this->assertFalse(AircraftResource::canDelete($aircraft));
    }

    #[Test]
    public function the_badge_counts_only_what_is_actually_overdue(): void
    {
        // A badge counting everything due within two months is a badge that is
        // never zero, and one that is never zero stops being read.
        $this->actingAs($this->userWith(Permissions::FLEET_VIEW));

        $soon = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);
        AirworthinessReview::create([
            'aircraft_id' => $soon->id,
            'issued_at' => now()->subYear()->toDateString(),
            'valid_until' => now()->addDays(20)->toDateString(),
        ]);

        $this->assertNull(DuePage::getNavigationBadge(), 'Due, but not yet overdue.');

        $late = Aircraft::create(['registration' => 'D-KXYZ', 'model' => 'ASK 21']);
        AirworthinessReview::create([
            'aircraft_id' => $late->id,
            'issued_at' => now()->subYears(2)->toDateString(),
            'valid_until' => now()->subDays(3)->toDateString(),
        ]);

        $this->assertSame('1', DuePage::getNavigationBadge());
    }

    #[Test]
    public function the_due_page_lists_what_is_coming(): void
    {
        $this->actingAs($this->userWith(Permissions::FLEET_VIEW));

        $aircraft = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);
        AirworthinessReview::create([
            'aircraft_id' => $aircraft->id,
            'certificate_reference' => 'ARC-2026-7',
            'issued_at' => now()->subYear()->toDateString(),
            'valid_until' => now()->addDays(15)->toDateString(),
        ]);

        Livewire::test(DuePage::class)
            ->assertSee('D-KABC')
            ->assertSee('ARC-2026-7');
    }

    #[Test]
    public function recording_a_reading_is_its_own_right(): void
    {
        // The action itself cannot be exercised here: a resource page needs its
        // module's routes, and those are built at boot, before the test enables
        // the module. Same constraint the warehouse documents. What is worth
        // pinning down anyway is the gate -- somebody who may look at the fleet
        // is not thereby somebody who may write in it.
        $reader = $this->userWith(Permissions::FLEET_VIEW);
        $recorder = $this->userWith(Permissions::FLEET_VIEW, Permissions::COUNTERS_RECORD);

        $this->assertFalse($reader->can(Permissions::COUNTERS_RECORD));
        $this->assertTrue($recorder->can(Permissions::COUNTERS_RECORD));

        // And that naming somebody in the programme is separate again: it
        // grants an authority to sign, which is not the same as being allowed
        // to add an aircraft.
        $manager = $this->userWith(Permissions::FLEET_MANAGE);

        $this->assertFalse($manager->can(Permissions::PROGRAMME_MANAGE));
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
