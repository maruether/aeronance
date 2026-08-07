<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Directives\Filament\Pages\AircraftDirectivesPage;
use App\Modules\Directives\Models\Directive;
use App\Modules\Directives\Permissions;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AircraftType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Types nobody looks after any more -- and the warning that says so.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * This module exists against one failure: an empty list reading as "the
 * manufacturer published nothing new". For a Bölkow Phoebus, an SHK-1, a Fauvel
 * AV-36 or a Pégase the list is legitimately empty for the opposite reason --
 * the manufacturer is gone and nobody took over the type support, so the club
 * researches for itself. On screen both look identical, and on the printed sheet
 * an inspector holds at the annual, the identical one is a document asserting
 * something it cannot back up.
 *
 * Three states, and the tests below keep them apart:
 *
 *   1. type support exists, source set up      -> the ordinary list, no warning
 *   2. type support exists, source NOT set up  -> an empty list that names its
 *                                                 two possible readings
 *   3. no type support at all                  -> "Achtung! Kein Musterbetreuer!"
 *
 * (3) is a stated fact on the aircraft type, never derived from "no source
 * found" -- deriving it would merge it with (2), and those two want opposite
 * reactions: (2) is an administrator's task that goes away, (3) is permanent.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class OrphanedTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate(Permissions::DIRECTIVES_VIEW, 'web');

        app(ModuleManager::class)->enable('fleet');
        app(ModuleManager::class)->enable('directives');
        app(ModuleManager::class)->forgetCache();
    }

    // ── The flag on the type ────────────────────────────────────────────────

    #[Test]
    public function a_type_is_supported_until_somebody_says_otherwise(): void
    {
        // The safe default. A flag that starts true would print the warning on
        // every well-supported type in the fleet, and a warning that appears
        // everywhere is read nowhere.
        $type = AircraftType::create(['designation' => 'ASK 21']);

        $this->assertFalse($type->isOrphaned());
    }

    #[Test]
    public function the_flag_is_stated_and_not_derived_from_a_missing_name(): void
    {
        // An empty "Musterbetreuer" field means nobody typed one in -- which is
        // exactly state 2, and must not raise the permanent warning.
        $unfilled = AircraftType::create(['designation' => 'ASK 21']);
        $orphaned = AircraftType::create([
            'designation' => 'Phoebus C',
            'without_type_support' => true,
        ]);

        $this->assertNull($unfilled->typeSupport());
        $this->assertFalse($unfilled->isOrphaned());
        $this->assertTrue($orphaned->isOrphaned());
    }

    #[Test]
    public function a_named_supporter_is_reported_and_the_flag_overrides_it(): void
    {
        // Grob gliders: "LTB Lindner". Should somebody tick the box anyway, the
        // flag wins in one place rather than the two contradicting each other
        // wherever they happen to be read.
        $supported = AircraftType::create([
            'designation' => 'G 103 Twin II',
            'type_support' => 'LTB Lindner',
        ]);

        $this->assertSame('LTB Lindner', $supported->typeSupport());

        $supported->update(['without_type_support' => true]);

        $this->assertNull($supported->fresh()->typeSupport());
    }

    // ── The screen ──────────────────────────────────────────────────────────

    #[Test]
    public function the_page_warns_for_a_type_nobody_looks_after(): void
    {
        $this->aircraftOfOrphanedType();

        $this->page()
            ->assertSee(__('directives.orphaned.headline'))
            ->assertSee('Phoebus C');
    }

    #[Test]
    public function the_page_stays_quiet_for_an_ordinary_type(): void
    {
        // The negative half matters as much: a warning on every aircraft is
        // noise, and noise is what makes the real one invisible.
        $this->aircraftOfSupportedType();

        $this->page()->assertDontSee(__('directives.orphaned.headline'));
    }

    #[Test]
    public function an_empty_list_names_its_two_readings_when_the_type_is_supported(): void
    {
        // State 2, as far as this page can honestly go: it cannot tell "nothing
        // published" from "no source configured", so it says both out loud
        // instead of letting silence pass for an answer.
        $this->aircraftOfSupportedType();

        $this->page()
            ->assertSee(__('directives.empty.ambiguous'))
            ->assertDontSee(__('directives.orphaned.headline'));
    }

    // ── The printed sheet, which is where it matters most ────────────────────

    #[Test]
    public function the_printed_sheet_carries_the_warning(): void
    {
        $aircraft = $this->aircraftOfOrphanedType();

        $this->actingAs($this->user())
            ->get(route('directives.overview', ['aircraft' => $aircraft]))
            ->assertSuccessful()
            ->assertSee('Achtung! Kein Musterbetreuer!', false)
            ->assertSee('Phoebus C');
    }

    #[Test]
    public function the_printed_warning_survives_a_table_that_is_not_empty(): void
    {
        // The subtler trap: a Phoebus with three recorded lines, all assessed,
        // reads as complete. It is not -- nobody publishes a fourth, so the
        // warning belongs on a full sheet as much as on an empty one.
        $aircraft = $this->aircraftOfOrphanedType();

        $this->directive(['subject_model' => $aircraft->model]);

        $this->actingAs($this->user())
            ->get(route('directives.overview', ['aircraft' => $aircraft]))
            ->assertSee('LTA-2026-005')
            ->assertSee('Achtung! Kein Musterbetreuer!', false);
    }

    #[Test]
    public function the_printed_sheet_warns_even_when_the_table_is_empty(): void
    {
        // The whole point, on paper: no lines, nothing overdue, nothing red --
        // and still a sheet that must not be read as "all clear".
        $aircraft = $this->aircraftOfOrphanedType();

        $this->actingAs($this->user())
            ->get(route('directives.overview', ['aircraft' => $aircraft]))
            ->assertSee('Achtung! Kein Musterbetreuer!', false)
            ->assertSee(__('directives.empty.orphaned'), false);
    }

    #[Test]
    public function the_printed_sheet_stays_quiet_for_an_ordinary_type(): void
    {
        $aircraft = $this->aircraftOfSupportedType();

        $this->actingAs($this->user())
            ->get(route('directives.overview', ['aircraft' => $aircraft]))
            ->assertSuccessful()
            ->assertDontSee('Achtung! Kein Musterbetreuer!', false);
    }

    #[Test]
    public function an_aircraft_without_a_type_prints_without_a_warning(): void
    {
        // Free-text models stay possible, so the sheet must render for one. It
        // cannot warn either -- there is no type record to have been flagged.
        $aircraft = Aircraft::create(['registration' => 'D-KFRE', 'model' => 'Eigenbau Möwe 3']);

        $this->actingAs($this->user())
            ->get(route('directives.overview', ['aircraft' => $aircraft]))
            ->assertSuccessful()
            ->assertDontSee('Achtung! Kein Musterbetreuer!', false);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function aircraftOfOrphanedType(): Aircraft
    {
        // Bölkow ceased; nobody took the Phoebus on. A real example, not a
        // placeholder -- the club that flies one has to research for itself.
        $type = AircraftType::create([
            'designation' => 'Phoebus C',
            'manufacturer' => 'Bölkow',
            'without_type_support' => true,
        ]);

        return Aircraft::create([
            'registration' => 'D-KPHO',
            'model' => 'Phoebus C',
            'aircraft_type_id' => $type->id,
            'is_active' => true,
        ]);
    }

    private function aircraftOfSupportedType(): Aircraft
    {
        $type = AircraftType::create([
            'designation' => 'G 103 Twin II',
            'manufacturer' => 'Grob',
            'type_support' => 'LTB Lindner',
        ]);

        return Aircraft::create([
            'registration' => 'D-KGRO',
            'model' => 'G 103 Twin II',
            'aircraft_type_id' => $type->id,
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function directive(array $attributes = []): Directive
    {
        return Directive::create($attributes + [
            'source' => 'manual',
            'number' => 'LTA-2026-005',
            'title' => 'Beschlag prüfen',
            'kind' => DirectiveKind::Lta,
            'bindingness' => Bindingness::Mandatory,
            'subject_kind' => SubjectKind::AircraftModel,
        ]);
    }

    private function page(): Testable
    {
        return Livewire::actingAs($this->user())->test(AircraftDirectivesPage::class);
    }

    private ?User $viewer = null;

    private function user(): User
    {
        if ($this->viewer !== null) {
            return $this->viewer->fresh();
        }

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::DIRECTIVES_VIEW);

        return $this->viewer = $user->fresh();
    }
}
