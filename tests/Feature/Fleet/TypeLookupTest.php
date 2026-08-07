<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Modules\Fleet\Models\AircraftType;
use App\Modules\Fleet\Types\TypeLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The fleet answering "which of our types is this?".
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The seam exists so another module does not have to hold this knowledge. What
 * is asserted here is exactly what would otherwise have leaked into the
 * directives module: how a Kennblatt is written, that one cell can name
 * several, and that an unknown one is a normal answer rather than a failure.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class TypeLookupTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_finds_a_type_by_its_kennblatt(): void
    {
        $type = AircraftType::create([
            'designation' => 'ASK 21',
            'manufacturer' => 'Alexander Schleicher',
            'type_certificate' => 'EASA.A.189',
        ]);

        $this->assertSame($type->id, app(TypeLookup::class)->byCertificate('EASA.A.189'));
    }

    #[Test]
    public function one_cell_may_name_several_and_the_flown_one_decides(): void
    {
        // The gazette prints "EASA.R.008, EASA.R.146" where a directive covers
        // two data sheets. Only one of them is in this hangar.
        $type = AircraftType::create([
            'designation' => 'DR 400',
            'type_certificate' => 'EASA.A.146',
        ]);

        $this->assertSame(
            $type->id,
            app(TypeLookup::class)->byCertificate('EASA.R.008, EASA.A.146'),
        );
    }

    #[Test]
    public function a_number_nobody_flies_is_an_answer_and_not_an_error(): void
    {
        /*
         * Most of what an authority publishes concerns aircraft this club does
         * not own. Treating that as a failure would turn every ordinary import
         * into a wall of warnings.
         */
        AircraftType::create(['designation' => 'ASK 21', 'type_certificate' => 'EASA.A.189']);

        $this->assertNull(app(TypeLookup::class)->byCertificate('EASA.IM.A.120'));
        $this->assertNull(app(TypeLookup::class)->byCertificate(''));
    }

    #[Test]
    public function the_designation_is_matched_exactly(): void
    {
        // A loose match here would attach a manufacturer's row to the wrong
        // variant -- "ASK 21" and "ASK 21 B" are different aircraft.
        AircraftType::create(['designation' => 'ASK 21', 'type_certificate' => 'EASA.A.189']);

        $this->assertNotNull(app(TypeLookup::class)->byDesignation('ASK 21'));
        $this->assertNull(app(TypeLookup::class)->byDesignation('ASK 21 B'));
    }
}
