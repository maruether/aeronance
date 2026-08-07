<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Modules\Fleet\Actions\CollectDueItems;
use App\Modules\Fleet\Enums\DocumentType;
use App\Modules\Fleet\Enums\WeighingKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AircraftDocument;
use App\Modules\Fleet\Models\AirworthinessReview;
use App\Modules\Fleet\Models\Weighing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Papers on an aircraft, and the "kommt drauf an" of their deadlines.
 *
 * the warning, and it is the sort that is easy to nod at and then get
 * wrong: "Manche lfz brauchen z.B. alle 4 Jahre eine wägung, andere nur bei
 * bedarf."
 *
 * So a document with no expiry does not expire. It is not a document whose
 * expiry somebody forgot -- and reporting its absence would fill the list with
 * work nobody owes, which is the fastest way to teach people to stop reading it.
 */
final class DocumentsAndDeadlinesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_document_without_an_expiry_never_falls_due(): void
    {
        $aircraft = $this->aircraftWithValidReview();

        AircraftDocument::create([
            'aircraft_id' => $aircraft->id,
            'type' => DocumentType::Amp,
            'title' => 'AMP ASK 21',
            'issued_at' => now()->subYears(6)->toDateString(),
            // No valid_until: it does not expire.
        ]);

        $this->assertCount(0, app(CollectDueItems::class)->within(60));
    }

    #[Test]
    public function one_with_an_expiry_does(): void
    {
        $aircraft = $this->aircraftWithValidReview();

        AircraftDocument::create([
            'aircraft_id' => $aircraft->id,
            'type' => DocumentType::Insurance,
            'title' => 'Haftpflicht 2026',
            'valid_until' => now()->addDays(20)->toDateString(),
        ]);

        $due = app(CollectDueItems::class)->within(60);

        $this->assertCount(1, $due);
        $this->assertSame('document', $due->first()['kind']);
        $this->assertSame('Haftpflicht 2026', $due->first()['detail']);
    }

    #[Test]
    public function a_weighing_that_is_valid_on_demand_is_not_a_deadline(): void
    {
        // The example from the brief. Some aircraft owe one every four years,
        // others only when something changes -- and the second kind must not
        // appear on the list at all.
        $aircraft = $this->aircraftWithValidReview();

        Weighing::create([
            'aircraft_id' => $aircraft->id,
            'kind' => WeighingKind::Glider,
            'weighed_at' => now()->subYears(9)->toDateString(),
            // No valid_until.
        ]);

        $this->assertCount(0, app(CollectDueItems::class)->within(60));
    }

    #[Test]
    public function a_weighing_with_a_four_year_validity_does_fall_due(): void
    {
        $aircraft = $this->aircraftWithValidReview();

        Weighing::create([
            'aircraft_id' => $aircraft->id,
            'kind' => WeighingKind::Glider,
            'weighed_at' => now()->subYears(4)->toDateString(),
            'valid_until' => now()->subDays(3)->toDateString(),
        ]);

        $due = app(CollectDueItems::class)->within(60);

        $this->assertCount(1, $due);
        $this->assertSame('weighing', $due->first()['kind']);
        $this->assertTrue($due->first()['overdue']);
    }

    #[Test]
    public function only_the_review_is_reported_when_it_is_missing_entirely(): void
    {
        // The one thing every aircraft always owes. Everything else may
        // legitimately not exist, and treating absence as a finding there would
        // be inventing work.
        $bare = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);

        $due = app(CollectDueItems::class)->within(60);

        $this->assertCount(1, $due);
        $this->assertSame('review', $due->first()['kind']);

        // No weighing, no documents -- and no rows for either.
        $this->assertSame(0, $bare->documents()->count());
        $this->assertSame(0, $bare->weighings()->count());
    }

    #[Test]
    public function a_document_that_does_not_expire_reports_that_plainly(): void
    {
        $document = AircraftDocument::create([
            'aircraft_id' => $this->aircraftWithValidReview()->id,
            'type' => DocumentType::FlightManual,
            'title' => 'Flughandbuch',
        ]);

        $this->assertFalse($document->expires());
        $this->assertTrue($document->isValid(), 'Not expiring is not the same as expired.');
        $this->assertNull($document->daysRemaining());
    }

    #[Test]
    public function the_amp_is_a_document_and_not_a_form(): void
    {
        // Vorgabe: "IHP gibt es nicht mehr, ist inzwischen ein AMP. Das lässt
        // sich als Dokument anhängen." What follows from it -- intervals -- is
        // entered on the component limits, where somebody can act on it.
        $aircraft = $this->aircraftWithValidReview();

        AircraftDocument::create([
            'aircraft_id' => $aircraft->id,
            'type' => DocumentType::Amp,
            'title' => 'AMP nach ML.A.302',
            'reference' => 'AMP-2026-4',
        ]);

        $this->assertSame(DocumentType::Amp, $aircraft->fresh()->documents->first()->type);
        $this->assertFalse(Schema::hasTable('maintenance_programmes'));
    }

    private function aircraftWithValidReview(): Aircraft
    {
        $aircraft = Aircraft::create([
            'registration' => 'D-K'.strtoupper(substr(uniqid(), -4)),
            'model' => 'ASK 21',
        ]);

        AirworthinessReview::create([
            'aircraft_id' => $aircraft->id,
            'issued_at' => now()->subMonths(2)->toDateString(),
            'valid_until' => now()->addMonths(10)->toDateString(),
        ]);

        return $aircraft->fresh();
    }
}
