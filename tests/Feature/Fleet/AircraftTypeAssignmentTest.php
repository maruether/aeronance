<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Directives\Models\Directive;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AircraftType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Assigning a type to an aircraft -- the last link in the chain.
 *
 * Without the field on the aircraft form, the type table existed and nothing in
 * the ordinary flow filled it: a person entering an aircraft never met it, so both
 * the exact LTA matching and the Kennblatt would have stayed theoretical.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT IS NOT TESTED HERE, said plainly: the Filament form itself. This panel
 * builds its resources from the enabled-modules table at boot, and RefreshDatabase
 * gives every test an empty one -- so driving the aircraft resource through
 * Livewire fails on missing routes rather than on anything about this feature.
 *
 * Rather than fight that for a test of framework behaviour, the logic that WAS in
 * the form closure moved onto the model, where it can be tested. What remains
 * untested is Filament wiring a select to a relationship, which is Filament's
 * business.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class AircraftTypeAssignmentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function choosing_a_type_offers_the_model_and_the_manufacturer(): void
    {
        // So the two do not silently disagree.
        $type = AircraftType::create([
            'designation' => 'ASK 21',
            'manufacturer' => 'Alexander Schleicher',
            'type_certificate' => 'EASA.A.221',
        ]);

        $this->assertSame(
            ['model' => 'ASK 21', 'manufacturer' => 'Alexander Schleicher'],
            $type->prefill(),
        );
    }

    #[Test]
    public function a_type_without_a_manufacturer_offers_only_the_model(): void
    {
        // Never forced: an aircraft may record a different manufacturer
        // legitimately -- a licence-built airframe -- and overwriting that
        // silently would be worse than leaving it.
        $type = AircraftType::create(['designation' => 'Eigenbau Möwe 3']);

        $this->assertSame(['model' => 'Eigenbau Möwe 3'], $type->prefill());
    }

    #[Test]
    public function an_aircraft_carries_its_type_and_reaches_the_certificate(): void
    {
        $type = AircraftType::create([
            'designation' => 'ASK 21',
            'type_certificate' => 'EASA.A.221',
            'certificate_authority' => AircraftType::AUTHORITY_EASA,
        ]);

        $aircraft = Aircraft::create([
            'registration' => 'D-KABC',
            'model' => 'ASK 21',
            'aircraft_type_id' => $type->id,
        ]);

        $this->assertSame('EASA.A.221', $aircraft->aircraftType->type_certificate);
        $this->assertSame('ASK 21 (EASA.A.221)', $aircraft->aircraftType->label());
    }

    #[Test]
    public function an_aircraft_without_a_type_still_works(): void
    {
        // the requirement was for the free text to stay: a club may fly something nobody
        // catalogued, and typing a name has to keep working.
        $aircraft = Aircraft::create(['registration' => 'D-KXYZ', 'model' => 'Eigenbau Möwe 3']);

        $this->assertNull($aircraft->aircraft_type_id);
        $this->assertNull($aircraft->aircraftType);
        $this->assertSame('Eigenbau Möwe 3', $aircraft->model);
    }

    #[Test]
    public function the_assignment_is_what_makes_the_directive_matching_exact(): void
    {
        // The end-to-end point of the whole exercise. Without the type link the
        // substring comparison would have matched both variants.
        $ask21 = AircraftType::create(['designation' => 'ASK 21']);
        $ask21b = AircraftType::create(['designation' => 'ASK 21 B']);

        $plain = Aircraft::create([
            'registration' => 'D-KABC', 'model' => 'ASK 21', 'aircraft_type_id' => $ask21->id,
        ]);
        $variant = Aircraft::create([
            'registration' => 'D-KDEF', 'model' => 'ASK 21 B', 'aircraft_type_id' => $ask21b->id,
        ]);

        $forVariant = Directive::create([
            'source' => 'manual',
            'number' => 'LTA-1',
            'title' => 'Nur die B-Variante',
            'kind' => DirectiveKind::Lta,
            'bindingness' => Bindingness::Mandatory,
            'subject_kind' => SubjectKind::AircraftModel,
            'subject_model' => 'ASK 21 B',
            'aircraft_type_id' => $ask21b->id,
        ]);

        $this->assertTrue($forVariant->mayApplyTo($variant));
        $this->assertFalse(
            $forVariant->mayApplyTo($plain),
            'Without the type link the substring comparison would have matched.',
        );
    }

    #[Test]
    public function and_an_unassigned_aircraft_is_still_not_exempted(): void
    {
        // The deliberate asymmetry, seen from the assignment side: curating the
        // types must not make the uncurated aircraft disappear from the list.
        $type = AircraftType::create(['designation' => 'ASK 21']);

        $directive = Directive::create([
            'source' => 'manual',
            'number' => 'LTA-2',
            'title' => 'Alle ASK 21',
            'kind' => DirectiveKind::Lta,
            'bindingness' => Bindingness::Mandatory,
            'subject_kind' => SubjectKind::AircraftModel,
            'subject_model' => 'ASK 21',
            'aircraft_type_id' => $type->id,
        ]);

        $unassigned = Aircraft::create(['registration' => 'D-KGHI', 'model' => 'ASK 21']);

        $this->assertTrue($directive->mayApplyTo($unassigned));
    }
}
