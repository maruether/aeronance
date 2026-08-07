<?php

declare(strict_types=1);

namespace Tests\Feature\Tooling;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Tooling\Actions\IssueTool;
use App\Modules\Tooling\Actions\RecordCalibration;
use App\Modules\Tooling\Enums\CalibrationResult;
use App\Modules\Tooling\Enums\ToolState;
use App\Modules\Tooling\Models\Tool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Werkzeugausgabe — wer hat was, und ist es zurück.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ZWEI TESTS TRAGEN DAS GANZE:
 *
 *   `an_overdue_tool_is_not_handed_out` — die Sperre sitzt bei der Ausgabe.
 *   Danach ist damit gearbeitet worden, und dann hilft nur noch die
 *   Nachprüfung des Zeitraums.
 *
 *   `a_failed_calibration_names_the_work_orders` — die Antwort auf F42. Die
 *   Kalibrierung liefert das Zeitfenster, die Ausgabeliste die Vorgänge darin,
 *   und niemand musste dafür bei jedem Handgriff etwas erfassen.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ToolIssueTest extends TestCase
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
     * DER ERSTE TEST, UM DEN ES GEHT.
     */
    #[Test]
    public function an_overdue_tool_is_not_handed_out(): void
    {
        // Kalibrierpflichtig und noch nie kalibriert -- gilt als überfällig.
        $werkzeug = $this->torqueWrench();

        $this->expectException(RuntimeException::class);

        app(IssueTool::class)->handle($werkzeug, $this->user());
    }

    #[Test]
    public function a_calibrated_tool_goes_out_and_comes_back(): void
    {
        $werkzeug = $this->calibratedWrench();
        $mechaniker = $this->user();

        $ausgabe = app(IssueTool::class)->handle(
            tool: $werkzeug,
            to: $mechaniker,
            workOrderReference: 'AV-2026-014',
        );

        $this->assertTrue($ausgabe->isOutstanding());
        $this->assertSame($mechaniker->name, $ausgabe->issued_to_name);
        $this->assertTrue($werkzeug->fresh()->isIssued());
        $this->assertFalse($werkzeug->fresh()->isAvailable(), 'Was draußen ist, ist nicht verfügbar.');

        app(IssueTool::class)->returnIt($ausgabe);

        $this->assertFalse($ausgabe->fresh()->isOutstanding());
        $this->assertFalse($werkzeug->fresh()->isIssued());
        $this->assertTrue($werkzeug->fresh()->isAvailable());
    }

    #[Test]
    public function a_tool_that_is_out_cannot_be_issued_again(): void
    {
        $werkzeug = $this->calibratedWrench();
        app(IssueTool::class)->handle($werkzeug, $this->user());

        $this->expectException(RuntimeException::class);

        app(IssueTool::class)->handle($werkzeug->fresh(), $this->user());
    }

    #[Test]
    public function a_blocked_tool_is_not_handed_out(): void
    {
        $werkzeug = $this->calibratedWrench();
        $werkzeug->update(['state' => ToolState::OutOfService]);

        $this->expectException(RuntimeException::class);

        app(IssueTool::class)->handle($werkzeug->fresh(), $this->user());
    }

    /**
     * Zurücknehmen geht IMMER — auch wenn die Frist inzwischen abgelaufen ist.
     *
     * Ein Werkzeug, das sich nicht zurückbuchen lässt, bliebe für immer als
     * „draußen" stehen, und eine Liste mit Karteileichen liest niemand.
     */
    #[Test]
    public function a_tool_comes_back_even_if_its_calibration_lapsed_meanwhile(): void
    {
        $werkzeug = $this->calibratedWrench();
        $ausgabe = app(IssueTool::class)->handle($werkzeug, $this->user());

        // Die Frist läuft ab, während das Werkzeug draußen ist.
        $werkzeug->update(['calibration_due_at' => now()->subDay()->toDateString()]);

        $zurueck = app(IssueTool::class)->returnIt($ausgabe);

        $this->assertFalse($zurueck->isOutstanding());
    }

    /**
     * DER ZWEITE TEST, UM DEN ES GEHT: F42, beantwortet.
     */
    #[Test]
    public function a_failed_calibration_names_the_work_orders(): void
    {
        $werkzeug = $this->torqueWrench(intervalMonths: 12);

        // Vor einem Jahr gut gemessen.
        app(RecordCalibration::class)->handle(
            tool: $werkzeug,
            performedAt: now()->subMonths(12)->toDateString(),
            result: CalibrationResult::InTolerance,
        );

        // In der Zwischenzeit zweimal im Einsatz ...
        foreach (['AV-2026-007', 'AV-2026-011'] as $vorgang) {
            $ausgabe = app(IssueTool::class)->handle(
                tool: $werkzeug->fresh(),
                to: $this->user(),
                workOrderReference: $vorgang,
            );
            app(IssueTool::class)->returnIt($ausgabe);
        }

        // ... und heute außer Toleranz.
        $schlecht = app(RecordCalibration::class)->handle(
            tool: $werkzeug->fresh(),
            performedAt: now()->toDateString(),
            result: CalibrationResult::OutOfTolerance,
        );

        $this->assertSame(
            ['AV-2026-007', 'AV-2026-011'],
            $schlecht->affectedWorkOrders(),
            'Der Nachprüfzeitraum muss die Vorgänge nennen, an denen damit gearbeitet wurde.',
        );
    }

    /**
     * Ohne Lücke gibt es nichts nachzuprüfen.
     */
    #[Test]
    public function a_clean_calibration_names_nothing(): void
    {
        $werkzeug = $this->calibratedWrench();

        $ausgabe = app(IssueTool::class)->handle(
            tool: $werkzeug,
            to: $this->user(),
            workOrderReference: 'AV-2026-020',
        );
        app(IssueTool::class)->returnIt($ausgabe);

        $sauber = app(RecordCalibration::class)->handle(
            tool: $werkzeug->fresh(),
            performedAt: now()->toDateString(),
            result: CalibrationResult::InTolerance,
        );

        $this->assertSame([], $sauber->affectedWorkOrders());
    }

    /**
     * Eine noch laufende Ausgabe zählt mit — sie überschneidet jeden Zeitraum,
     * der bis heute reicht.
     */
    #[Test]
    public function a_tool_still_out_counts_towards_the_review(): void
    {
        $werkzeug = $this->torqueWrench(intervalMonths: 12);

        app(RecordCalibration::class)->handle(
            tool: $werkzeug,
            performedAt: now()->subMonths(12)->toDateString(),
            result: CalibrationResult::InTolerance,
        );

        app(IssueTool::class)->handle(
            tool: $werkzeug->fresh(),
            to: $this->user(),
            workOrderReference: 'AV-LAEUFT-NOCH',
        );

        $schlecht = app(RecordCalibration::class)->handle(
            tool: $werkzeug->fresh(),
            performedAt: now()->toDateString(),
            result: CalibrationResult::OutOfTolerance,
        );

        $this->assertContains('AV-LAEUFT-NOCH', $schlecht->affectedWorkOrders());
    }

    private function torqueWrench(?int $intervalMonths = null): Tool
    {
        return Tool::create([
            'inventory_number' => 'WZ-'.uniqid(),
            'name' => 'Drehmomentschlüssel',
            'calibration_required' => true,
            'calibration_interval_months' => $intervalMonths,
        ]);
    }

    private function calibratedWrench(): Tool
    {
        $werkzeug = $this->torqueWrench(intervalMonths: 12);

        app(RecordCalibration::class)->handle(
            tool: $werkzeug,
            performedAt: now()->toDateString(),
            result: CalibrationResult::InTolerance,
        );

        return $werkzeug->fresh();
    }

    private function user(): User
    {
        return User::factory()->create(['is_active' => true]);
    }
}
