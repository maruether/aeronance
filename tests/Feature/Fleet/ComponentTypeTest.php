<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Directives\Models\Directive;
use App\Modules\Fleet\Enums\ComponentKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AircraftType;
use App\Modules\Fleet\Models\ComponentType;
use App\Modules\Fleet\Models\Installation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Component types -- "auch die haben tms."
 *
 * The Tost coupling is the case that proves it: its own Kennblatt (60.230/2 in
 * the LBA's coupling volume), its own technical notes, and "2 Jahre oder 500
 * Starts, whatever comes first".
 */
final class ComponentTypeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_component_type_carries_its_certificate_and_part_number(): void
    {
        $tost = $this->tostCoupling();

        $this->assertSame(ComponentKind::TowRelease, $tost->kind);
        $this->assertSame('60.230/2', $tost->type_certificate);
        $this->assertStringContainsString('60.230/2', $tost->label());
    }

    #[Test]
    public function it_counts_only_what_is_fitted_now(): void
    {
        // A type fitted twenty times over thirty years would otherwise read as if
        // it were everywhere.
        $tost = $this->tostCoupling();
        $aircraft = $this->aircraft();

        $this->installation($aircraft, $tost, '0150');
        $removed = $this->installation($aircraft, $tost, '0042');
        $removed->update(['removed_at' => now()->subYear()->toDateString()]);

        $this->assertSame(2, $tost->installations()->count());
        $this->assertSame(1, $tost->fittedCount());
    }

    #[Test]
    public function a_component_directive_matches_the_catalogued_type_exactly(): void
    {
        // The sharpening the aircraft types brought, now for components.
        $tost = $this->tostCoupling();
        $other = ComponentType::create([
            'designation' => 'Sicherheitskupplung Europa G 73',
            'manufacturer' => 'Tost GmbH',
            'kind' => ComponentKind::TowRelease,
        ]);

        $aircraft = $this->aircraft();
        $fitted = $this->installation($aircraft, $tost, '0150');

        $directive = $this->componentDirective(['component_type_id' => $other->id]);

        $this->assertFalse(
            $directive->mayApplyTo($aircraft->fresh()),
            'A directive for the G 73 must not hit an aircraft carrying a G 88.',
        );

        $forThisOne = $this->componentDirective([
            'number' => 'TM-2', 'component_type_id' => $tost->id,
        ]);

        $this->assertTrue($forThisOne->mayApplyTo($aircraft->fresh()));
    }

    #[Test]
    public function the_serial_range_still_narrows_an_exact_type_match(): void
    {
        // Catalogued does not mean unconditional: a directive from S/N 0100 must
        // not hit an earlier one just because the type is right.
        $tost = $this->tostCoupling();
        $aircraft = $this->aircraft();
        $this->installation($aircraft, $tost, '0042');

        $directive = $this->componentDirective([
            'component_type_id' => $tost->id,
            'serial_from' => '0100',
        ]);

        $this->assertFalse($directive->mayApplyTo($aircraft->fresh()));
    }

    #[Test]
    public function an_uncatalogued_installation_falls_back_to_the_name(): void
    {
        // The same asymmetry as everywhere here: curating the catalogue must not
        // make the uncurated components disappear from a directive's reach.
        $aircraft = $this->aircraft();

        Installation::create([
            'aircraft_id' => $aircraft->id,
            'part_name' => 'Tost Sicherheitskupplung Europa G 88',
            'serial_number' => '0150',
            'installed_at' => now()->subYear()->toDateString(),
        ]);

        $directive = $this->componentDirective(['subject_designation' => 'Tost']);

        $this->assertTrue($directive->mayApplyTo($aircraft->fresh()));
    }

    #[Test]
    public function a_part_number_matches_where_the_name_does_not(): void
    {
        // A directive names one or the other, and a parts list and a person use
        // different words for the same thing.
        $tost = $this->tostCoupling();

        $this->assertTrue($tost->matchesName(null, 'E-88-100'));
        $this->assertTrue($tost->matchesName('Sicherheitskupplung Europa G 88'));
        $this->assertFalse($tost->matchesName('Bugkupplung E 85'));
    }

    #[Test]
    public function two_manufacturers_may_use_the_same_designation(): void
    {
        // Which is why the unique key is the pair, not the name.
        ComponentType::create([
            'designation' => 'E 85', 'manufacturer' => 'Tost GmbH', 'kind' => ComponentKind::TowRelease,
        ]);

        $second = ComponentType::create([
            'designation' => 'E 85', 'manufacturer' => 'Andere GmbH', 'kind' => ComponentKind::TowRelease,
        ]);

        $this->assertSame(2, ComponentType::count());
        $this->assertSame('Andere GmbH', $second->manufacturer);
    }

    #[Test]
    public function winches_are_deliberately_not_a_kind(): void
    {
        /*
         * the requirement was whether the LBA's winch volume also lists rope winches
         * installed IN an aircraft. It does not: 07-2-winden.pdf is headed
         * "Startgeräte / Launching Devices" and lists ground winches only -- Rhön,
         * Tost-Doppeltrommelwinde, System Dunkel. Ground equipment is not
         * maintained under an aircraft's programme, so there is nothing here for
         * it to be.
         */
        $kinds = array_map(fn (ComponentKind $k): string => $k->value, ComponentKind::cases());

        $this->assertSame(['engine', 'propeller', 'tow_release', 'other'], $kinds);
    }

    #[Test]
    public function only_the_catch_all_kind_is_assumed_to_have_no_limits(): void
    {
        // Advisory only -- it decides what the form suggests, never what it
        // permits. An empty limit on every bracket somebody records would be
        // noise.
        $this->assertTrue(ComponentKind::Engine->usuallyHasLimits());
        $this->assertTrue(ComponentKind::TowRelease->usuallyHasLimits());
        $this->assertFalse(ComponentKind::Other->usuallyHasLimits());
    }

    private function tostCoupling(): ComponentType
    {
        return ComponentType::create([
            'designation' => 'Sicherheitskupplung Europa G 88',
            'manufacturer' => 'Tost GmbH',
            'kind' => ComponentKind::TowRelease,
            'type_certificate' => '60.230/2',
            'certificate_authority' => AircraftType::AUTHORITY_LBA,
            'part_number' => 'E-88-100',
        ]);
    }

    private function aircraft(): Aircraft
    {
        return Aircraft::firstOrCreate(['registration' => 'D-KABC'], ['model' => 'ASK 21']);
    }

    private function installation(Aircraft $aircraft, ComponentType $type, string $serial): Installation
    {
        return Installation::create([
            'aircraft_id' => $aircraft->id,
            'part_name' => $type->designation,
            'component_type_id' => $type->id,
            'serial_number' => $serial,
            'installed_at' => now()->subYear()->toDateString(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function componentDirective(array $attributes = []): Directive
    {
        return Directive::create(array_merge([
            'source' => 'manual',
            'number' => 'TM-1',
            'title' => 'Kupplung prüfen',
            'kind' => DirectiveKind::Tm,
            'bindingness' => Bindingness::Mandatory,
            'subject_kind' => SubjectKind::Component,
        ], $attributes));
    }
}
