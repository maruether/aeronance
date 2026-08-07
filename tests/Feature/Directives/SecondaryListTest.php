<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Modules\Directives\Actions\ImportDirectives;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Directives\Models\Directive;
use App\Modules\Directives\Sources\DirectiveRow;
use App\Modules\Fleet\Models\AircraftType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A sheet that lists documents somebody else owns.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Aquila publishes everything that concerns the AT01 -- their own notes beside
 * Rotax's and MT-Propeller's -- and uses the ORIGINAL numbers. Measured on the
 * real sheet: 67 of its 74 Rotax-shaped numbers appear character for character
 * in rotax.yaml as well.
 *
 * Filed as they stand, those 67 are a second copy of something the club already
 * has, and an inspector ticks each one twice. Excluded wholesale, seven
 * directives disappear that exist ONLY on Aquila's sheet -- withdrawn from
 * Rotax's own search but still effective for early serial numbers.
 *
 * Vorgabe: "ich hätte lieber eine nicht mehr gültige tm, die ich als alt abhaken
 * kann wie eine zu wenig." So: keep the unique ones, leave the shared ones to
 * the manufacturer who issued them.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SecondaryListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();
        app(ModuleManager::class)->enable('directives');
        app(ModuleManager::class)->forgetCache();
    }

    #[Test]
    public function it_leaves_a_shared_number_to_the_source_that_owns_it(): void
    {
        $import = app(ImportDirectives::class);

        $import->store('rotax', [$this->row('ASB-912-060', 'Inspection of the propeller gearbox')]);

        $result = $import->store('aquila', [
            $this->row('ASB-912-060', 'Inspektion des Propellergetriebes'),
        ], secondary: true);

        $this->assertSame(0, $result['created']);
        $this->assertSame(['ASB-912-060'], $result['deferred']);

        // One directive, and it is the manufacturer's own.
        $this->assertSame(1, Directive::where('number', 'ASB-912-060')->count());
        $this->assertSame('rotax', Directive::where('number', 'ASB-912-060')->value('source'));
    }

    #[Test]
    public function what_exists_only_on_the_secondary_sheet_is_still_filed(): void
    {
        /*
         * THE REASON THE SWITCH EXISTS RATHER THAN AN EXCLUSION. Seven Rotax
         * numbers appear only on Aquila's sheet. Dropping the supplier sections
         * would lose exactly those -- the withdrawn ones, which are the ones an
         * older aircraft still needs.
         */
        $import = app(ImportDirectives::class);

        $import->store('rotax', [$this->row('ASB-912-060', 'Gearbox')]);

        $result = $import->store('aquila', [
            $this->row('ASB-912-060', 'Getriebe'),
            $this->row('SB-912-021', 'Nur bei Aquila geführt'),
        ], secondary: true);

        $this->assertSame(1, $result['created']);
        $this->assertSame(['ASB-912-060'], $result['deferred']);
        $this->assertSame('aquila', Directive::where('number', 'SB-912-021')->value('source'));
    }

    #[Test]
    public function a_row_already_filed_here_keeps_being_updated(): void
    {
        /*
         * Order matters in real life: Aquila may be imported before Rotax is
         * ever added. A row this source has already stored carries assessments,
         * and taking it away afterwards would delete somebody's work -- so the
         * deferral applies only where nothing was filed here before.
         */
        $import = app(ImportDirectives::class);

        $import->store('aquila', [$this->row('SB-914-045', 'Erste Fassung')], secondary: true);
        $import->store('rotax', [$this->row('SB-914-045', 'Rotax eigene Fassung')]);

        $result = $import->store('aquila', [$this->row('SB-914-045', 'Zweite Fassung')], secondary: true);

        $this->assertSame([], $result['deferred']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame('Zweite Fassung', Directive::where('source', 'aquila')->value('title'));
    }

    #[Test]
    public function an_ordinary_source_is_untouched_by_any_of_it(): void
    {
        // Two manufacturers may legitimately use the same number for different
        // documents; only a sheet that DECLARES itself secondary yields.
        $import = app(ImportDirectives::class);

        $import->store('rotax', [$this->row('SB-1', 'Rotax')]);
        $result = $import->store('mt-propeller', [$this->row('SB-1', 'MT-Propeller')]);

        $this->assertSame(1, $result['created']);
        $this->assertSame([], $result['deferred']);
        $this->assertSame(2, Directive::where('number', 'SB-1')->count());
    }

    private function row(string $number, string $title): DirectiveRow
    {
        return new DirectiveRow(
            number: $number,
            title: $title,
            kind: DirectiveKind::Sb,
            subjectKind: SubjectKind::Engine,
        );
    }

    #[Test]
    public function the_gazette_yields_where_the_authority_already_holds_the_ad(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * TWO NUMBERS, ONE DOCUMENT. EASA files it as 2026-0132; Germany
         * publishes the same directive as D-2026-152 and prints "EASA AD
         * 2026-0132" beside it. Comparing the two national numbers would never
         * find the match -- the tie is the authority's own number.
         * ─────────────────────────────────────────────────────────────────────
         */
        $import = app(ImportDirectives::class);

        $import->store('easa-ad', [$this->row('2026-0132', 'Airbus Helicopters')]);

        $result = $import->store('nfl', [
            new DirectiveRow(
                number: 'D-2026-152',
                title: 'AIRBUS HELICOPTERS',
                kind: DirectiveKind::Lta,
                subjectKind: SubjectKind::AircraftModel,
                externalReference: 'EASA AD 2026-0132',
            ),
        ], secondary: true);

        $this->assertSame(0, $result['created']);
        $this->assertSame(['D-2026-152'], $result['deferred']);
        $this->assertSame(1, Directive::where('number', '2026-0132')->count());
    }

    #[Test]
    public function a_purely_national_directive_is_still_filed(): void
    {
        /*
         * The reason the gazette is worth having at all: for an Annex-I type
         * there is no EASA directive, and this is the only source that carries
         * one. Yielding on everything would throw exactly those away.
         */
        $import = app(ImportDirectives::class);

        $import->store('easa-ad', [$this->row('2026-0132', 'Airbus Helicopters')]);

        $result = $import->store('nfl', [
            new DirectiveRow(
                number: 'D-2026-199',
                title: 'PÜTZER Elster',
                kind: DirectiveKind::Lta,
                subjectKind: SubjectKind::AircraftModel,
            ),
        ], secondary: true);

        $this->assertSame(1, $result['created']);
        $this->assertSame([], $result['deferred']);
        $this->assertSame('nfl', Directive::where('number', 'D-2026-199')->value('source'));
    }

    #[Test]
    public function a_kennblatt_number_puts_the_row_on_an_aircraft_type(): void
    {
        /*
         * Vorgabe: "die kennblattnummer ist im kfz typ im flottenmodul
         * hinterlegt." The gazette names the holder and the type certificate,
         * never the model -- so the model cannot be read from the row, only
         * looked up. Without this the directives arrive attached to nothing.
         */
        $type = AircraftType::create([
            'designation' => 'ASK 21',
            'manufacturer' => 'Alexander Schleicher',
            'type_certificate' => 'EASA.A.189',
        ]);

        app(ImportDirectives::class)->store('nfl', [
            new DirectiveRow(
                number: 'D-2026-201',
                title: 'BAE SYSTEMS (Operations) Limited',
                kind: DirectiveKind::Lta,
                subjectKind: SubjectKind::AircraftModel,
                subjectDesignation: 'EASA.A.189, UK.TC.A.00147',
            ),
        ]);

        $this->assertSame(
            $type->id,
            Directive::where('number', 'D-2026-201')->value('aircraft_type_id'),
        );
    }
}
