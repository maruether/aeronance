<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Directives\Models\Directive;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\Installation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which aircraft a directive touches.
 *
 * The judgement the module hangs on, and it deliberately errs towards YES: the
 * answer is only a proposal for the list, and somebody still assesses each line.
 * Erring the other way would let a mandatory directive vanish on the strength of
 * data nobody entered.
 */
final class ApplicabilityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_model_directive_hits_every_aircraft_of_that_type(): void
    {
        $ask21 = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);
        $dg300 = Aircraft::create(['registration' => 'D-KXYZ', 'model' => 'DG 300']);

        $directive = $this->directive(['subject_model' => 'ASK 21']);

        $this->assertTrue($directive->mayApplyTo($ask21));
        $this->assertFalse($directive->mayApplyTo($dg300));
    }

    #[Test]
    public function model_matching_tolerates_a_longer_or_shorter_name(): void
    {
        // Manufacturers write "ASK 21" where the fleet says "ASK 21 B", and
        // either can be the longer string. Exact matching would quietly exempt
        // half a club's fleet.
        $variant = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21 B']);

        $this->assertTrue($this->directive(['subject_model' => 'ASK 21'])->mayApplyTo($variant));
        $this->assertTrue($this->directive([
            'number' => 'LTA-2', 'subject_model' => 'ASK 21 B',
        ])->mayApplyTo(Aircraft::create(['registration' => 'D-KDEF', 'model' => 'ASK 21'])));
    }

    #[Test]
    public function an_aircraft_with_a_blank_model_is_not_exempted(): void
    {
        // A missing entry must not be a way out. (The column is NOT NULL, so the
        // reachable case is a blank string -- which is what the guard has to
        // handle. The null branch stays as defence for imports and console use.)
        $unknown = Aircraft::create(['registration' => 'D-KABC', 'model' => '   ']);

        $this->assertTrue($this->directive(['subject_model' => 'ASK 21'])->mayApplyTo($unknown));
    }

    #[Test]
    public function a_component_directive_follows_the_fitted_part(): void
    {
        $withHook = $this->aircraftWithPart('D-KABC', 'Tost Schleppkupplung Europa G 88', '0150');
        $without = $this->aircraftWithPart('D-KXYZ', 'Höhenrudergestänge', '77');

        $directive = $this->directive([
            'subject_kind' => SubjectKind::Component,
            'subject_model' => null,
            'subject_designation' => 'Tost',
        ]);

        $this->assertTrue($directive->mayApplyTo($withHook));
        $this->assertFalse($directive->mayApplyTo($without));
    }

    #[Test]
    public function the_serial_range_narrows_it(): void
    {
        $inRange = $this->aircraftWithPart('D-KABC', 'Tost Kupplung', '0150');
        $below = $this->aircraftWithPart('D-KXYZ', 'Tost Kupplung', '0042');

        $directive = $this->directive([
            'subject_kind' => SubjectKind::Component,
            'subject_model' => null,
            'subject_designation' => 'Tost',
            'serial_from' => '0100',
        ]);

        $this->assertTrue($directive->mayApplyTo($inRange));
        $this->assertFalse($directive->mayApplyTo($below));
    }

    #[Test]
    public function serials_are_compared_as_text_not_numbers(): void
    {
        // Manufacturers write "A-45" and "1000 and up". Casting to int turns
        // "A-45" into 0 and the range check stops meaning anything.
        $directive = $this->directive([
            'subject_kind' => SubjectKind::Component,
            'serial_from' => 'A-10',
            'serial_to' => 'A-99',
        ]);

        $this->assertTrue($directive->serialInRange('A-45'));
        $this->assertFalse($directive->serialInRange('B-01'));
        $this->assertTrue($directive->serialInRange('a-45'), 'Case must not matter.');
    }

    #[Test]
    public function an_unknown_serial_is_not_exempted_either(): void
    {
        $directive = $this->directive([
            'subject_kind' => SubjectKind::Component,
            'serial_from' => '0100',
        ]);

        $this->assertTrue($directive->serialInRange(null));
        $this->assertTrue($directive->serialInRange('  '));
    }

    #[Test]
    public function an_aircraft_with_nothing_recorded_matches_a_component_directive(): void
    {
        // The most important of these: the tool must not decide a line does not
        // apply because nobody has entered the components yet.
        $bare = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);

        $directive = $this->directive([
            'subject_kind' => SubjectKind::Component,
            'subject_model' => null,
            'subject_designation' => 'Tost',
        ]);

        $this->assertTrue($directive->mayApplyTo($bare));
    }

    #[Test]
    public function a_superseded_directive_applies_to_nobody(): void
    {
        $aircraft = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);

        $old = $this->directive(['subject_model' => 'ASK 21']);
        $new = $this->directive(['number' => 'LTA-2026-006', 'subject_model' => 'ASK 21']);

        $old->update(['superseded_by_id' => $new->id]);

        $this->assertFalse($old->fresh()->mayApplyTo($aircraft));
        $this->assertTrue($new->mayApplyTo($aircraft));
    }

    /** @param array<string, mixed> $attributes */
    private function directive(array $attributes = []): Directive
    {
        return Directive::create(array_merge([
            'source' => 'manual',
            'number' => 'LTA-2026-005',
            'title' => 'Prüfung',
            'kind' => DirectiveKind::Lta,
            'subject_kind' => SubjectKind::AircraftModel,
        ], $attributes));
    }

    private function aircraftWithPart(string $registration, string $partName, string $serial): Aircraft
    {
        $aircraft = Aircraft::create(['registration' => $registration, 'model' => 'ASK 21']);

        Installation::create([
            'aircraft_id' => $aircraft->id,
            'part_name' => $partName,
            'serial_number' => $serial,
            'installed_at' => now()->subYear()->toDateString(),
        ]);

        return $aircraft->fresh();
    }
}
