<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Fleet\Enums\CounterKind;
use App\Modules\Fleet\Enums\LimitKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\ComponentLimit;
use App\Modules\Fleet\Models\CounterReading;
use App\Modules\Fleet\Models\Installation;
use App\Modules\Fleet\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two sheets a club hands over.
 *
 * Modelled on the BWLV forms, and folded into one table with two views -- paper
 * needs two sheets, a database does not.
 */
final class AircraftRecordPrintTest extends TestCase
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
    public function the_equipment_list_prints_what_is_fitted(): void
    {
        $aircraft = $this->aircraft();
        $this->fitVariometer($aircraft);

        $this->actingAs($this->userWith(Permissions::FLEET_VIEW))
            ->get(route('fleet.equipment-list', ['aircraft' => $aircraft]))
            ->assertSuccessful()
            ->assertSee('Ausrüstungsverzeichnis', false)
            ->assertSee('D-KABC')
            ->assertSee('Variometer')
            ->assertSee('Winter');
    }

    #[Test]
    public function the_lever_arm_keeps_its_sign(): void
    {
        // The datum is not at the nose, and a lever arm without its sign is a
        // number nobody can use.
        $aircraft = $this->aircraft();
        $this->fitVariometer($aircraft, leverArm: -420);

        $this->actingAs($this->userWith(Permissions::FLEET_VIEW))
            ->get(route('fleet.equipment-list', ['aircraft' => $aircraft]))
            ->assertSee('-420 mm');
    }

    #[Test]
    public function minimum_equipment_is_marked_and_sorted_first(): void
    {
        $aircraft = $this->aircraft();
        $this->fitVariometer($aircraft, minimum: false);

        Installation::create([
            'aircraft_id' => $aircraft->id,
            'part_name' => 'Fahrtmesser',
            'installed_at' => now()->toDateString(),
            'is_minimum_equipment' => true,
        ]);

        $body = $this->actingAs($this->userWith(Permissions::FLEET_VIEW))
            ->get(route('fleet.equipment-list', ['aircraft' => $aircraft]))
            ->assertSuccessful()
            ->getContent();

        $this->assertLessThan(
            strpos($body, 'Variometer'),
            strpos($body, 'Fahrtmesser'),
            'Required equipment belongs at the top of the sheet.',
        );
        $this->assertStringContainsString('Mindestausrüstung', $body);
    }

    #[Test]
    public function the_operating_times_sheet_shows_when_removal_falls_due(): void
    {
        // The column the BWLV form has and the model did not: not "how much is
        // left" but what the instrument in the hangar has to read.
        $aircraft = $this->aircraft();
        $release = $this->fitTowRelease($aircraft);

        ComponentLimit::create([
            'installation_id' => $release->id,
            'kind' => LimitKind::Starts,
            'value' => 500,
        ]);

        // 1200 launches on the aircraft, none of them on this release.
        $this->reading($aircraft, CounterKind::Starts, 1200);

        $this->actingAs($this->userWith(Permissions::FLEET_VIEW))
            ->get(route('fleet.operating-times', ['aircraft' => $aircraft]))
            ->assertSuccessful()
            ->assertSee('Betriebszeitenübersicht', false)
            ->assertSee('fälliger Ausbau', false)
            ->assertSee('1.700', false);
    }

    #[Test]
    public function due_at_is_the_aircraft_reading_and_not_the_remainder(): void
    {
        $aircraft = $this->aircraft();
        $release = $this->fitTowRelease($aircraft);

        $limit = ComponentLimit::create([
            'installation_id' => $release->id,
            'kind' => LimitKind::Starts,
            'value' => 500,
        ]);

        $this->reading($aircraft, CounterKind::Starts, 1200);

        $limit = $limit->fresh();

        $this->assertSame(500.0, $limit->remaining(), 'Nothing used yet.');
        $this->assertSame(1700.0, $limit->dueAtAircraftValue(), 'But the counter has to read 1700.');
    }

    #[Test]
    public function a_removed_component_stays_on_the_times_sheet(): void
    {
        // It is a history. The "beim Ausbau" columns exist precisely so what
        // came off can still be read years later.
        $aircraft = $this->aircraft();
        $release = $this->fitTowRelease($aircraft);

        $release->update([
            'removed_at' => now()->toDateString(),
            'counters_at_removal' => [CounterKind::Starts->value => 900.0],
        ]);

        $this->actingAs($this->userWith(Permissions::FLEET_VIEW))
            ->get(route('fleet.operating-times', ['aircraft' => $aircraft]))
            ->assertSee('Tost Schleppkupplung');
    }

    #[Test]
    public function the_sheets_need_the_permission_and_the_module(): void
    {
        $aircraft = $this->aircraft();

        $this->actingAs($this->userWith())
            ->get(route('fleet.equipment-list', ['aircraft' => $aircraft]))
            ->assertForbidden();

        app(ModuleManager::class)->disable('fleet');
        app(ModuleManager::class)->forgetCache();

        $this->actingAs($this->userWith(Permissions::FLEET_VIEW))
            ->get(route('fleet.operating-times', ['aircraft' => $aircraft]))
            ->assertNotFound();
    }

    private function aircraft(): Aircraft
    {
        return Aircraft::create([
            'registration' => 'D-KABC',
            'model' => 'ASK 21',
            'serial_number' => '21123',
            'optional_counters' => [CounterKind::Starts->value],
        ]);
    }

    private function fitVariometer(Aircraft $aircraft, ?int $leverArm = null, bool $minimum = true): Installation
    {
        return Installation::create([
            'aircraft_id' => $aircraft->id,
            'part_name' => 'Variometer',
            'type_designation' => 'V5',
            'manufacturer' => 'Winter',
            'serial_number' => 'VAR-8891',
            'position' => 'Instrumentenbrett',
            'lever_arm_mm' => $leverArm,
            'is_minimum_equipment' => $minimum,
            'installed_at' => now()->toDateString(),
        ]);
    }

    private function fitTowRelease(Aircraft $aircraft): Installation
    {
        return Installation::create([
            'aircraft_id' => $aircraft->id,
            'part_name' => 'Tost Schleppkupplung',
            'serial_number' => '1378X5V',
            'installed_at' => now()->toDateString(),
            'counters_at_installation' => [CounterKind::Starts->value => 1200.0],
        ]);
    }

    private function reading(Aircraft $aircraft, CounterKind $kind, float $value): void
    {
        CounterReading::create([
            'aircraft_id' => $aircraft->id,
            'kind' => $kind,
            'value' => $value,
            'read_at' => now()->toDateString(),
        ]);
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
