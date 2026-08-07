<?php

declare(strict_types=1);

namespace Tests\Feature\Tooling;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Tooling\Actions\RecordCalibration;
use App\Modules\Tooling\Enums\CalibrationResult;
use App\Modules\Tooling\Enums\GapReason;
use App\Modules\Tooling\Models\Tool;
use App\Modules\Tooling\Models\ToolCalibration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Werkzeuge und ihre Kalibrierung.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER TEST, UM DEN ES GEHT: `a_late_calibration_records_the_gap`.
 *
 * Ein Kalibrierschein sagt „ab heute stimmt es wieder". Was er nicht sagt: dass
 * es vier Monate lang nicht belegt war — und genau in diesen vier Monaten wurde
 * mit dem Ding gearbeitet. 145.A.40 verlangt, diese Arbeit zu bewerten. Wird
 * das Zeitfenster nicht in dem Moment festgehalten, in dem die neue
 * Kalibrierung eingetragen wird, ist es weg.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class CalibrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();
        app(ModuleManager::class)->enable('tooling');
        app(ModuleManager::class)->forgetCache();
    }

    /**
     * DER TEST, UM DEN ES GEHT.
     */
    #[Test]
    public function a_late_calibration_records_the_gap(): void
    {
        $werkzeug = $this->torqueWrench();

        // Fällig war sie vor 120 Tagen, gemessen wird heute.
        $werkzeug->update(['calibration_due_at' => now()->subDays(120)->toDateString()]);

        $kalibrierung = app(RecordCalibration::class)->handle(
            tool: $werkzeug->fresh(),
            performedAt: now()->toDateString(),
            result: CalibrationResult::InTolerance,
        );

        $this->assertTrue($kalibrierung->hasGap());
        $this->assertSame(120, $kalibrierung->gapDays());
        $this->assertSame(GapReason::Overdue, $kalibrierung->gap_reason);
        $this->assertTrue(
            $kalibrierung->gapNeedsReview(),
            'Eine Lücke ohne Bewertung muss als offen dastehen -- sonst ist sie nur Dekoration.',
        );
    }

    /**
     * Rechtzeitig kalibriert: keine Lücke, keine Bewertung.
     *
     * Sonst stünde nach jedem Schein eine Aufgabe da, und nach der dritten
     * überflüssigen wird auch die echte weggeklickt.
     */
    #[Test]
    public function an_early_calibration_leaves_no_gap(): void
    {
        $werkzeug = $this->torqueWrench();
        $werkzeug->update(['calibration_due_at' => now()->addDays(20)->toDateString()]);

        $kalibrierung = app(RecordCalibration::class)->handle(
            tool: $werkzeug->fresh(),
            performedAt: now()->toDateString(),
            result: CalibrationResult::InTolerance,
        );

        $this->assertFalse($kalibrierung->hasGap());
        $this->assertFalse($kalibrierung->gapNeedsReview());
    }

    /**
     * Die erste Kalibrierung überhaupt ist keine Lücke.
     *
     * Da ist nicht bekannt, dass etwas falsch war — da fängt die Historie an.
     * Eine erfundene Lücke bis zum Anfang der Zeit würde die echten Fälle unter
     * Rauschen begraben.
     */
    #[Test]
    public function the_very_first_calibration_is_not_a_gap(): void
    {
        $kalibrierung = app(RecordCalibration::class)->handle(
            tool: $this->torqueWrench(),
            performedAt: now()->toDateString(),
            result: CalibrationResult::InTolerance,
        );

        $this->assertFalse($kalibrierung->hasGap());
    }

    #[Test]
    public function the_interval_sets_the_next_due_date(): void
    {
        $werkzeug = $this->torqueWrench(intervalMonths: 12);

        app(RecordCalibration::class)->handle(
            tool: $werkzeug,
            performedAt: now()->toDateString(),
            result: CalibrationResult::InTolerance,
        );

        $this->assertSame(
            now()->addMonths(12)->toDateString(),
            $werkzeug->fresh()->calibration_due_at->toDateString(),
        );
    }

    /**
     * Was auf dem Schein steht, schlägt das Intervall.
     *
     * Das Labor weiß es besser als eine Zahl in einer Stammdatenmaske.
     */
    #[Test]
    public function the_certificate_beats_the_interval(): void
    {
        $werkzeug = $this->torqueWrench(intervalMonths: 12);

        app(RecordCalibration::class)->handle(
            tool: $werkzeug,
            performedAt: now()->toDateString(),
            result: CalibrationResult::InTolerance,
            validUntil: now()->addMonths(6)->toDateString(),
        );

        $this->assertSame(
            now()->addMonths(6)->toDateString(),
            $werkzeug->fresh()->calibration_due_at->toDateString(),
        );
    }

    /**
     * Kalibrierpflichtig und noch nie kalibriert zählt als überfällig.
     *
     * Alles andere hieße, dass ein neu angelegter Drehmomentschlüssel
     * unbegrenzt gültig ist, bis ihn jemand zum ersten Mal weggibt.
     */
    #[Test]
    public function a_never_calibrated_tool_counts_as_overdue(): void
    {
        $werkzeug = $this->torqueWrench();

        $this->assertTrue($werkzeug->isCalibrationOverdue());
        $this->assertFalse($werkzeug->isAvailable());
        $this->assertSame(1, Tool::query()->overdue()->count());
    }

    /**
     * Ein Werkzeug ohne Kalibrierpflicht wird nie überfällig.
     */
    #[Test]
    public function a_screwdriver_is_never_overdue(): void
    {
        $werkzeug = Tool::create([
            'inventory_number' => 'WZ-002',
            'name' => 'Schraubendreher',
        ]);

        $this->assertFalse($werkzeug->isCalibrationOverdue());
        $this->assertTrue($werkzeug->isAvailable());
        $this->assertSame(0, Tool::query()->overdue()->count());
    }

    /**
     * Eine abgelaufene Kalibrierung macht das Werkzeug unbenutzbar — auch wenn
     * es tadellos aussieht.
     */
    #[Test]
    public function an_expired_calibration_takes_the_tool_out_of_play(): void
    {
        $werkzeug = $this->torqueWrench();
        $werkzeug->update(['calibration_due_at' => now()->subDay()->toDateString()]);

        $this->assertFalse($werkzeug->fresh()->isAvailable());
    }

    #[Test]
    public function a_gap_review_is_recorded_with_who_and_what(): void
    {
        $werkzeug = $this->torqueWrench();
        $werkzeug->update(['calibration_due_at' => now()->subDays(60)->toDateString()]);

        $kalibrierung = app(RecordCalibration::class)->handle(
            tool: $werkzeug->fresh(),
            performedAt: now()->toDateString(),
            result: CalibrationResult::InTolerance,
        );

        $pruefer = User::factory()->create(['is_active' => true]);

        $bewertet = app(RecordCalibration::class)->reviewGap(
            $kalibrierung,
            $pruefer,
            'Arbeitskarten des Zeitraums durchgesehen, keine Drehmomentarbeiten.',
        );

        $this->assertFalse($bewertet->gapNeedsReview());
        $this->assertSame($pruefer->id, $bewertet->gap_reviewed_by_id);
        $this->assertNotNull($bewertet->gap_reviewed_at);

        // Und die Lücke selbst bleibt stehen.
        $this->assertTrue($bewertet->hasGap());
        $this->assertSame(0, ToolCalibration::query()->openGaps()->count());
    }

    #[Test]
    public function a_review_without_a_word_is_refused(): void
    {
        $werkzeug = $this->torqueWrench();
        $werkzeug->update(['calibration_due_at' => now()->subDays(60)->toDateString()]);

        $kalibrierung = app(RecordCalibration::class)->handle(
            tool: $werkzeug->fresh(),
            performedAt: now()->toDateString(),
            result: CalibrationResult::InTolerance,
        );

        $this->expectException(InvalidArgumentException::class);

        app(RecordCalibration::class)->reviewGap(
            $kalibrierung,
            User::factory()->create(['is_active' => true]),
            '   ',
        );
    }

    /**
     * Eine Messung aus der Zukunft ist ein Tippfehler — und einer, der die
     * Fälligkeit um Jahre verschiebt.
     */
    #[Test]
    public function a_calibration_dated_in_the_future_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(RecordCalibration::class)->handle(
            tool: $this->torqueWrench(),
            performedAt: now()->addWeek()->toDateString(),
            result: CalibrationResult::InTolerance,
        );
    }

    #[Test]
    public function a_validity_before_the_measurement_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(RecordCalibration::class)->handle(
            tool: $this->torqueWrench(),
            performedAt: now()->toDateString(),
            result: CalibrationResult::InTolerance,
            validUntil: now()->subMonth()->toDateString(),
        );
    }

    /**
     * DER TEST ZUM EIGENTLICHEN FUND: außer Toleranz zählt weiter zurück.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * EASA-FAQ 116318: „If the tool / equipment fails during next regular
     * calibration / inspection, the completed tasks may require to be verified /
     * performed again."
     *
     * Der Zeitraum reicht deshalb bis zur letzten Messung MIT gutem Befund —
     * nicht bis zum Fälligkeitsdatum. Wann das Werkzeug angefangen hat
     * abzuweichen, weiß niemand.
     * ─────────────────────────────────────────────────────────────────────────
     */
    #[Test]
    public function an_out_of_tolerance_finding_reaches_back_to_the_last_good_one(): void
    {
        $werkzeug = $this->torqueWrench(intervalMonths: 12);

        // Vor zwei Jahren gut gemessen ...
        app(RecordCalibration::class)->handle(
            tool: $werkzeug,
            performedAt: now()->subMonths(24)->toDateString(),
            result: CalibrationResult::InTolerance,
        );

        // ... vor einem Jahr wieder, ebenfalls gut ...
        app(RecordCalibration::class)->handle(
            tool: $werkzeug->fresh(),
            performedAt: now()->subMonths(12)->toDateString(),
            result: CalibrationResult::InTolerance,
        );

        // ... und heute außer Toleranz.
        $schlecht = app(RecordCalibration::class)->handle(
            tool: $werkzeug->fresh(),
            performedAt: now()->toDateString(),
            result: CalibrationResult::OutOfTolerance,
        );

        $this->assertTrue($schlecht->isOutOfTolerance());
        $this->assertSame(GapReason::OutOfTolerance, $schlecht->gap_reason);
        $this->assertSame(
            now()->subMonths(12)->toDateString(),
            $schlecht->gap_started_at->toDateString(),
            'Der Zeitraum muss bis zur letzten GUTEN Messung reichen, nicht bis zur letzten überhaupt.',
        );
        $this->assertTrue($schlecht->gapNeedsReview());
    }

    /**
     * Und der schwerere Befund gewinnt, wenn beides zutrifft.
     *
     * Zu spät UND außer Toleranz: Der Zeitraum der Verspätung wäre kürzer und
     * würde den eigentlichen Befund verharmlosen.
     */
    #[Test]
    public function out_of_tolerance_beats_mere_lateness(): void
    {
        $werkzeug = $this->torqueWrench(intervalMonths: 12);

        app(RecordCalibration::class)->handle(
            tool: $werkzeug,
            performedAt: now()->subMonths(18)->toDateString(),
            result: CalibrationResult::InTolerance,
        );

        // Faellig war sie vor einem halben Jahr; gemessen wird heute, und zwar
        // ausser Toleranz.
        $schlecht = app(RecordCalibration::class)->handle(
            tool: $werkzeug->fresh(),
            performedAt: now()->toDateString(),
            result: CalibrationResult::OutOfTolerance,
        );

        $this->assertSame(GapReason::OutOfTolerance, $schlecht->gap_reason);
        $this->assertSame(now()->subMonths(18)->toDateString(), $schlecht->gap_started_at->toDateString());
        $this->assertGreaterThan(
            180,
            $schlecht->gapDays(),
            'Der Zeitraum muss der lange sein, nicht der der blossen Verspätung.',
        );
    }

    /**
     * Außer Toleranz ohne jede Vorgeschichte: Der Zeitraum bleibt offen.
     *
     * Einen Anfang zu erfinden wäre schlimmer als ein ehrliches „unbekannt" —
     * die Bemerkung der Bewertung ist der Ort dafür.
     */
    #[Test]
    public function a_first_ever_calibration_that_fails_has_no_invented_start(): void
    {
        $schlecht = app(RecordCalibration::class)->handle(
            tool: $this->torqueWrench(),
            performedAt: now()->toDateString(),
            result: CalibrationResult::OutOfTolerance,
        );

        $this->assertSame(GapReason::OutOfTolerance, $schlecht->gap_reason);
        $this->assertNull($schlecht->gap_started_at);
    }

    /**
     * Ein fehlender Befund gilt NICHT als bestanden.
     *
     * Altbestand und nachgetragene Historie kennen ihn nicht; „unbekannt" und
     * „in Ordnung" auseinanderzuhalten ist genau der Zweck des Feldes.
     */
    #[Test]
    public function a_missing_finding_is_not_a_pass(): void
    {
        $werkzeug = $this->torqueWrench();

        $alt = ToolCalibration::create([
            'tool_id' => $werkzeug->getKey(),
            'performed_at' => now()->subYear()->toDateString(),
            'valid_until' => now()->subMonth()->toDateString(),
        ]);

        $this->assertNull($alt->result);
        $this->assertFalse($alt->isOutOfTolerance());

        // Und eine spaetere Fehlmessung zaehlt nicht bis dorthin zurueck, weil
        // dieser Eintrag eben KEIN guter Befund ist.
        $schlecht = app(RecordCalibration::class)->handle(
            tool: $werkzeug->fresh(),
            performedAt: now()->toDateString(),
            result: CalibrationResult::OutOfTolerance,
        );

        $this->assertSame(
            now()->subYear()->toDateString(),
            $schlecht->gap_started_at->toDateString(),
            'Ohne guten Befund faellt der Zeitraum auf die erste Kalibrierung ueberhaupt zurueck.',
        );
    }

    private function torqueWrench(?int $intervalMonths = null): Tool
    {
        return Tool::create([
            'inventory_number' => 'WZ-001',
            'name' => 'Drehmomentschlüssel 5–25 Nm',
            'calibration_required' => true,
            'calibration_interval_months' => $intervalMonths,
        ]);
    }
}
