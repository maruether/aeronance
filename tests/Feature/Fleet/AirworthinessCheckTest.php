<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Modules\Fleet\Airworthiness\AirworthinessCheck;
use App\Modules\Fleet\Airworthiness\ContributesOpenItems;
use App\Modules\Fleet\Airworthiness\OpenItem;
use App\Modules\Fleet\Enums\DocumentType;
use App\Modules\Fleet\Enums\LimitKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AircraftDocument;
use App\Modules\Fleet\Models\AirworthinessReview;
use App\Modules\Fleet\Models\ComponentLimit;
use App\Modules\Fleet\Models\Installation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Hier ist noch was offen."
 *
 * the framing, and a much more honest thing to build than a verdict:
 * airworthiness is a judgement a qualified person makes with the aircraft in
 * front of them. What software can do is make sure nothing they would want to
 * know is sitting unnoticed in a database.
 */
final class AirworthinessCheckTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_aircraft_in_order_produces_nothing(): void
    {
        $this->assertSame([], app(AirworthinessCheck::class)->openItemsFor($this->goodAircraft()));
    }

    #[Test]
    public function an_empty_list_is_not_a_verdict(): void
    {
        // The distinction the whole class rests on. "Nothing found" is a
        // statement about the database, not about the aeroplane.
        $this->assertStringContainsString(
            'keine Feststellung der Lufttüchtigkeit',
            __('fleet.airworthiness.not_a_verdict'),
        );
    }

    #[Test]
    public function a_missing_or_expired_review_is_reported(): void
    {
        $bare = Aircraft::create(['registration' => 'D-KAAA', 'model' => 'ASK 21']);

        $items = app(AirworthinessCheck::class)->openItemsFor($bare);

        $this->assertCount(1, $items);
        $this->assertStringContainsString('Keine Nachprüfung', $items[0]->detail);

        $expired = $this->goodAircraft();
        $expired->currentReview()->update(['valid_until' => now()->subDay()->toDateString()]);

        $this->assertCount(1, app(AirworthinessCheck::class)->openItemsFor($expired->fresh()));
    }

    #[Test]
    public function a_limit_inside_its_tolerance_is_a_warning_not_a_stopper(): void
    {
        // Colouring "four days over, permitted" the same as "a year over" is how
        // a list stops being read.
        $aircraft = $this->goodAircraft();
        $installation = $this->fit($aircraft, monthsAgo: 13);

        ComponentLimit::create([
            'installation_id' => $installation->id,
            'kind' => LimitKind::CalendarMonths,
            'value' => 12,
            'tolerance_absolute' => 2,
        ]);

        $items = app(AirworthinessCheck::class)->openItemsFor($aircraft->fresh());

        $this->assertCount(1, $items);
        $this->assertFalse($items[0]->blocking);
    }

    #[Test]
    public function past_the_tolerance_it_blocks(): void
    {
        $aircraft = $this->goodAircraft();
        $installation = $this->fit($aircraft, monthsAgo: 20);

        ComponentLimit::create([
            'installation_id' => $installation->id,
            'kind' => LimitKind::CalendarMonths,
            'value' => 12,
            'tolerance_absolute' => 1,
        ]);

        $items = app(AirworthinessCheck::class)->openItemsFor($aircraft->fresh());

        $this->assertTrue($items[0]->blocking);
    }

    #[Test]
    public function removing_minimum_equipment_stops_the_aircraft(): void
    {
        // the rule, and the reason the flag exists: "baue ich das
        // zusätzliche Garmin G5 aus darf ich fliegen, nehm ich die Analoganzeige
        // steht der vogel."
        $aircraft = $this->goodAircraft();

        Installation::create([
            'aircraft_id' => $aircraft->id,
            'part_name' => 'Fahrtmesser',
            'installed_at' => now()->subYear()->toDateString(),
            'removed_at' => now()->toDateString(),
            'is_minimum_equipment' => true,
        ]);

        $items = app(AirworthinessCheck::class)->openItemsFor($aircraft->fresh());

        $this->assertCount(1, $items);
        $this->assertSame('Fahrtmesser', $items[0]->what);
    }

    #[Test]
    public function removing_something_that_is_not_minimum_equipment_does_not(): void
    {
        $aircraft = $this->goodAircraft();

        Installation::create([
            'aircraft_id' => $aircraft->id,
            'part_name' => 'Garmin G5',
            'installed_at' => now()->subYear()->toDateString(),
            'removed_at' => now()->toDateString(),
            'is_minimum_equipment' => false,
        ]);

        $this->assertSame([], app(AirworthinessCheck::class)->openItemsFor($aircraft->fresh()));
    }

    #[Test]
    public function replacing_it_clears_the_item(): void
    {
        // Taking the instrument out to fit a new one is not the same as flying
        // without it.
        $aircraft = $this->goodAircraft();

        Installation::create([
            'aircraft_id' => $aircraft->id,
            'part_name' => 'Fahrtmesser',
            'installed_at' => now()->subYear()->toDateString(),
            'removed_at' => now()->toDateString(),
            'is_minimum_equipment' => true,
        ]);

        Installation::create([
            'aircraft_id' => $aircraft->id,
            'part_name' => 'Fahrtmesser',
            'installed_at' => now()->toDateString(),
            'is_minimum_equipment' => true,
        ]);

        $this->assertSame([], app(AirworthinessCheck::class)->openItemsFor($aircraft->fresh()));
    }

    #[Test]
    public function an_expired_document_shows_up(): void
    {
        $aircraft = $this->goodAircraft();

        AircraftDocument::create([
            'aircraft_id' => $aircraft->id,
            'type' => DocumentType::Insurance,
            'title' => 'Haftpflicht',
            'valid_until' => now()->subWeek()->toDateString(),
        ]);

        $this->assertCount(1, app(AirworthinessCheck::class)->openItemsFor($aircraft->fresh()));
    }

    #[Test]
    public function a_document_that_never_expires_does_not(): void
    {
        $aircraft = $this->goodAircraft();

        AircraftDocument::create([
            'aircraft_id' => $aircraft->id,
            'type' => DocumentType::FlightManual,
            'title' => 'Flughandbuch',
        ]);

        $this->assertSame([], app(AirworthinessCheck::class)->openItemsFor($aircraft->fresh()));
    }

    #[Test]
    public function another_module_can_add_its_own_reasons(): void
    {
        // The extension point. The verdict spans modules -- task cards will know
        // their open findings, releases whether a certificate was signed -- and
        // none of them may reach into the others.
        $check = app(AirworthinessCheck::class);
        $check->register(FakeContributor::class);

        $items = $check->openItemsFor($this->goodAircraft());

        $this->assertCount(1, $items);
        $this->assertSame('workorders', $items[0]->source);
    }

    #[Test]
    public function registering_the_same_contributor_twice_does_not_double_it(): void
    {
        $check = app(AirworthinessCheck::class);
        $check->register(FakeContributor::class);
        $check->register(FakeContributor::class);

        $this->assertCount(1, $check->openItemsFor($this->goodAircraft()));
    }

    private function goodAircraft(): Aircraft
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

    private function fit(Aircraft $aircraft, int $monthsAgo): Installation
    {
        return Installation::create([
            'aircraft_id' => $aircraft->id,
            'part_name' => 'Tost Schleppkupplung',
            'installed_at' => now()->subMonths($monthsAgo)->toDateString(),
        ]);
    }
}

/**
 * Stands in for a module that is not built yet.
 */
final class FakeContributor implements ContributesOpenItems
{
    public function openItemsFor(Aircraft $aircraft): array
    {
        return [new OpenItem(source: 'workorders', what: 'Offener Befund', detail: 'Riss im Holm')];
    }
}
