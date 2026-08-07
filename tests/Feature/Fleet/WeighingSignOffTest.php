<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Models\User;
use App\Modules\Fleet\Actions\PrepareWeighing;
use App\Modules\Fleet\Actions\SignOffWeighing;
use App\Modules\Fleet\Enums\WeighingKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\Weighing;
use App\Modules\Fleet\Models\WeighingEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Signing a weighing off, and starting the next one.
 *
 * Vorgabe: "eine einmal abgezeichnete Wägung ist unveränderlich" -- the rule this
 * system keeps everywhere else, for the reason it keeps giving: the document is
 * the evidence, and evidence that can be revised afterwards is not evidence.
 */
final class WeighingSignOffTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function signing_off_freezes_the_figures(): void
    {
        $weighing = $this->weighing();
        $this->massRow($weighing, 'Gesamt', 250.0);

        $result = app(SignOffWeighing::class)->handle($weighing->fresh(), $this->mechanic());

        $this->assertSame(250.0, $result->emptyMassKg);
        $this->assertTrue($weighing->fresh()->isSignedOff());
        $this->assertSame('250.00', $weighing->fresh()->empty_mass_kg);
    }

    #[Test]
    public function a_signed_off_sheet_cannot_be_changed(): void
    {
        $weighing = $this->weighing();
        $this->massRow($weighing, 'Gesamt', 250.0);
        app(SignOffWeighing::class)->handle($weighing->fresh(), $this->mechanic());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot be changed/');

        $weighing->fresh()->update(['place' => 'Woanders']);
    }

    #[Test]
    public function nor_can_its_rows(): void
    {
        // Locking only the header would have been decoration -- the figures come
        // from the rows.
        $weighing = $this->weighing();
        $row = $this->massRow($weighing, 'Gesamt', 250.0);
        app(SignOffWeighing::class)->handle($weighing->fresh(), $this->mechanic());

        try {
            $row->fresh()->update(['mass_kg' => 999]);
            $this->fail('A row of a signed sheet must not be editable.');
        } catch (RuntimeException) {
        }

        try {
            WeighingEntry::create([
                'weighing_id' => $weighing->id,
                'section' => WeighingEntry::SECTION_COMPONENT,
                'label' => 'Nachgeschoben',
                'mass_kg' => 5,
            ]);
            $this->fail('A row must not be added to a signed sheet.');
        } catch (RuntimeException) {
        }

        $this->expectException(RuntimeException::class);
        $row->fresh()->delete();
    }

    #[Test]
    public function it_cannot_be_signed_off_twice(): void
    {
        $weighing = $this->weighing();
        $this->massRow($weighing, 'Gesamt', 250.0);

        $action = app(SignOffWeighing::class);
        $action->handle($weighing->fresh(), $this->mechanic());

        $this->expectException(RuntimeException::class);
        $action->handle($weighing->fresh(), $this->mechanic());
    }

    #[Test]
    public function a_result_out_of_range_is_still_signed_off(): void
    {
        // Vorgabe: "ist ein Ergebnis, verhindert halt die Freigabe, aber das ist
        // im echten leben so." What it stops is the aircraft flying, which is a
        // different decision made by a different person.
        $weighing = $this->weighing();
        $weighing->update(['cg_range_from_mm' => 100, 'cg_range_to_mm' => 200]);
        $this->massRow($weighing, 'Gesamt', 250.0);
        $this->support($weighing, 'G1', 100.0);
        $this->support($weighing, 'G2', 100.0);

        $result = app(SignOffWeighing::class)->handle($weighing->fresh(), $this->mechanic());

        $this->assertFalse($result->isAcceptable());
        $this->assertTrue($weighing->fresh()->isSignedOff());
    }

    #[Test]
    public function the_signature_is_copied_not_looked_up(): void
    {
        $weighing = $this->weighing();
        $this->massRow($weighing, 'Gesamt', 250.0);

        $mechanic = $this->mechanic();
        app(SignOffWeighing::class)->handle($weighing->fresh(), $mechanic);

        $this->assertSame($mechanic->name, $weighing->fresh()->signed_off_by_name);
    }

    #[Test]
    public function the_manual_values_come_across_to_the_next_sheet(): void
    {
        // They describe the type, they are copied from a document, and retyping
        // them every four years is four chances to transpose a digit.
        $aircraft = $this->aircraft();
        $first = $this->weighing($aircraft);
        $first->update([
            'max_mass_kg' => 600,
            'cg_range_from_mm' => 200,
            'cg_range_to_mm' => 400,
            'flight_cg_from_mm' => 250,
            'flight_cg_to_mm' => 350,
            'cockpit_load_max_kg' => 110,
        ]);
        $this->massRow($first, 'Gesamt', 250.0);
        app(SignOffWeighing::class)->handle($first->fresh(), $this->mechanic());

        $next = app(PrepareWeighing::class)->from($aircraft->fresh(), $this->mechanic());

        $this->assertSame('600.00', $next->max_mass_kg);
        $this->assertSame('250.00', $next->flight_cg_from_mm);
        $this->assertSame('110.00', $next->cockpit_load_max_kg);
    }

    #[Test]
    public function the_scale_distances_deliberately_do_not(): void
    {
        // the exception, and worth being exact about because I first read
        // it too broadly: the datum's DEFINITION is a property of the type and
        // carries over. What must not is where the scales stood in relation to
        // it -- that is measured every time and genuinely changes.
        //
        // Carrying a measurement forward would let a mistake from 2022 become
        // the 2026 result with nothing in the sheet ever showing it.
        $aircraft = $this->aircraft();
        $first = $this->weighing($aircraft);
        $first->update([
            'datum_reference' => 'Flügelvorderkante Wurzelrippe',
            'reference_line' => 'Rumpfoberkante waagerecht',
            'front_support_arm_mm' => -250,
            'support_distance_mm' => 1400,
        ]);
        $this->massRow($first, 'Gesamt', 250.0);
        app(SignOffWeighing::class)->handle($first->fresh(), $this->mechanic());

        $next = app(PrepareWeighing::class)->from($aircraft->fresh(), $this->mechanic());

        $this->assertSame('Flügelvorderkante Wurzelrippe', $next->datum_reference, 'The datum is defined, not measured.');
        $this->assertSame('Rumpfoberkante waagerecht', $next->reference_line);

        $this->assertNull($next->front_support_arm_mm, 'Where the scales stood is measured afresh.');
        $this->assertNull($next->support_distance_mm);
    }

    #[Test]
    public function the_seats_and_the_row_labels_come_across_but_not_the_figures(): void
    {
        // The aircraft has the same components as last time; typing out
        // "Tragwerk rechts innen" again is not the part worth repeating. The
        // masses are.
        $aircraft = $this->aircraft();
        $first = $this->weighing($aircraft);
        $this->massRow($first, 'Tragwerk rechts innen', 62.4);
        WeighingEntry::create([
            'weighing_id' => $first->id,
            'section' => WeighingEntry::SECTION_SEAT,
            'label' => 'Vordersitz',
            'arm_mm' => 100,
        ]);
        app(SignOffWeighing::class)->handle($first->fresh(), $this->mechanic());

        $next = app(PrepareWeighing::class)->from($aircraft->fresh(), $this->mechanic());

        $seat = $next->entriesOf(WeighingEntry::SECTION_SEAT)->sole();
        $this->assertSame('100.00', $seat->arm_mm);

        $component = $next->entriesOf(WeighingEntry::SECTION_COMPONENT)->sole();
        $this->assertSame('Tragwerk rechts innen', $component->label);
        $this->assertNull($component->mass_kg, 'The label, not the measurement.');
    }

    #[Test]
    public function an_unsigned_draft_is_not_copied_from(): void
    {
        // It may hold figures nobody ever checked, and carrying those forward
        // would launder them into the next report.
        $aircraft = $this->aircraft();
        $draft = $this->weighing($aircraft);
        $draft->update(['max_mass_kg' => 999]);

        $next = app(PrepareWeighing::class)->from($aircraft->fresh(), $this->mechanic());

        $this->assertNull($next->max_mass_kg);
    }

    private function aircraft(): Aircraft
    {
        return Aircraft::create([
            'registration' => 'D-K'.strtoupper(substr(uniqid(), -4)),
            'model' => 'ASK 21',
        ]);
    }

    private function weighing(?Aircraft $aircraft = null): Weighing
    {
        return Weighing::create([
            'aircraft_id' => ($aircraft ?? $this->aircraft())->id,
            'kind' => WeighingKind::Glider,
            'weighed_at' => now()->toDateString(),
            'front_support_arm_mm' => 0,
            'support_distance_mm' => 1000,
        ]);
    }

    private function massRow(Weighing $w, string $label, float $mass): WeighingEntry
    {
        return WeighingEntry::create([
            'weighing_id' => $w->id,
            'section' => WeighingEntry::SECTION_COMPONENT,
            'label' => $label,
            'mass_kg' => $mass,
        ]);
    }

    private function support(Weighing $w, string $label, float $gross): WeighingEntry
    {
        return WeighingEntry::create([
            'weighing_id' => $w->id,
            'section' => WeighingEntry::SECTION_SUPPORT,
            'label' => $label,
            'gross_kg' => $gross,
            'tare_kg' => 0,
        ]);
    }

    private function mechanic(): User
    {
        return User::factory()->create(['is_active' => true]);
    }
}
