<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Modules\ModuleManager;
use App\Modules\Fleet\Enums\CounterKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AircraftType;
use App\Modules\Fleet\Models\CounterReading;
use App\Modules\Vereinsflieger\Actions\ReadAircraftTimes;
use App\Modules\Vereinsflieger\Models\AircraftLink;
use App\Modules\Vereinsflieger\Models\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Mehrere Vereine an einer Installation.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „ich möchte optional mehrere vereine koppeln können. Hintergrund ist
 * da das cao umfeld: Verein ist mit seinen flugzeugen in der cao und diese
 * bekommt so automatisch die stunden quasi live statt immer nachfragen zu
 * müssen."
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class VereinsfliegerCouplingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(ModuleManager::class)->enable('vereinsflieger');
        app(ModuleManager::class)->enable('fleet');
    }

    /**
     * DER HAKEN, DER ZUGRIFF AUF EIN FREMDES SYSTEM GEBEN KANN.
     *
     * Vorgabe: „mit mehreren anbindungen geben wir ggf leuten zugriff auf ein
     * cao system." Eine Anbindung, die nur Zeiten liefern soll, darf niemandem
     * ein Konto verschaffen — also ist der Haken ab Werk aus.
     */
    #[Test]
    public function a_connection_imports_nobody_unless_it_is_told_to(): void
    {
        $anbindung = $this->connection('Nachbarverein');

        $this->assertFalse($anbindung->provides_identities);
        $this->assertNull(Connection::identitySource());
    }

    /**
     * Und genau eine darf es.
     *
     * Ein Mensch hat ein Konto; zwei Vereinsflieger vergeben dieselben
     * Kennungen doppelt. Umgeschaltet statt abgewiesen: Wer eine neue Anbindung
     * dazu macht, meint genau das.
     */
    #[Test]
    public function only_one_connection_may_import_members(): void
    {
        $erste = $this->connection('Verein A', identities: true);
        $zweite = $this->connection('Verein B', identities: true);

        $this->assertFalse($erste->fresh()->provides_identities);
        $this->assertTrue($zweite->fresh()->provides_identities);
        $this->assertSame($zweite->id, Connection::identitySource()?->id);
    }

    // ── Betriebszeiten ───────────────────────────────────────────────────────

    /**
     * Die vier Zähler, gemessen an D-KEWW.
     *
     * `towcount` fehlt mit Absicht: Aeronance kennt Schleppstarts nicht als
     * eigene Zählerart, und einen Wert in eine unpassende Schublade zu legen
     * wäre schlimmer als ihn wegzulassen.
     */
    #[Test]
    public function the_operating_times_become_counter_readings(): void
    {
        $anbindung = $this->connection('Verein A');
        $flugzeug = $this->aircraft('D-KEWW');
        $this->link($anbindung, $flugzeug, 'D-KEWW');

        $this->fakeMaintenance(['motortime' => 788.55, 'flighttime' => 8230.3, 'landingcount' => 21342, 'towcount' => 0]);

        $ergebnis = app(ReadAircraftTimes::class)->handle($anbindung);

        $this->assertSame(1, $ergebnis['read']);

        $this->assertSame('788.55', $this->reading($flugzeug, CounterKind::EngineHours));
        $this->assertSame('8230.30', $this->reading($flugzeug, CounterKind::FlightHours));
        $this->assertSame('21342.00', $this->reading($flugzeug, CounterKind::Landings));
    }

    /**
     * "Jetzt lesen" liest genau EINE Kopplung -- die anderen bleiben unberuehrt.
     *
     * Der Knopf an der Zeile (Feldtest: "Es fehlt ein 'jetzt lesen' Button")
     * reicht die Kopplung als only: durch; der Nachtlauf ohne only liest
     * weiterhin alle.
     */
    #[Test]
    public function reading_a_single_link_leaves_the_others_alone(): void
    {
        $anbindung = $this->connection('Verein A');
        $eins = $this->aircraft('D-KEWW');
        $zwei = $this->aircraft('D-EABC');
        $linkEins = $this->link($anbindung, $eins, 'D-KEWW');
        $this->link($anbindung, $zwei, 'D-EABC');

        $this->fakeMaintenance(['motortime' => 100.0, 'flighttime' => 200.0, 'landingcount' => 300]);

        $ergebnis = app(ReadAircraftTimes::class)->handle(
            connection: $anbindung,
            only: $linkEins,
        );

        $this->assertSame(1, $ergebnis['read']);
        $this->assertNotNull($this->reading($eins, CounterKind::EngineHours));
        $this->assertNull($this->reading($zwei, CounterKind::EngineHours));
    }

    /**
     * Ein unveränderter Stand erzeugt keine zweite Zeile.
     *
     * Ein Zählerstand ist unveränderlich — ein nächtlicher Lauf erzeugte sonst
     * 365 identische Zeilen im Jahr je Zähler und Maschine, und die
     * Betriebshistorie wäre nicht mehr lesbar.
     */
    #[Test]
    public function an_unchanged_value_is_not_recorded_twice(): void
    {
        $anbindung = $this->connection('Verein A');
        $flugzeug = $this->aircraft('D-KEWW');
        $this->link($anbindung, $flugzeug, 'D-KEWW');

        $this->fakeMaintenance(['motortime' => 788.55, 'flighttime' => 8230.3, 'landingcount' => 21342]);

        app(ReadAircraftTimes::class)->handle($anbindung);
        $nachErstem = CounterReading::count();

        app(ReadAircraftTimes::class)->handle($anbindung);

        $this->assertSame($nachErstem, CounterReading::count());
    }

    #[Test]
    public function a_changed_value_is_recorded(): void
    {
        $anbindung = $this->connection('Verein A');
        $flugzeug = $this->aircraft('D-KEWW');
        $this->link($anbindung, $flugzeug, 'D-KEWW');

        /*
         * EINE Fake-Registrierung mit wechselndem Wert -- Http::fake() ERGAENZT
         * die Stubs, statt sie zu ersetzen. Ein zweiter Aufruf haette den
         * ersten also nicht ueberschrieben, und der Test haette zweimal
         * denselben Wert gesehen. (Genau darauf ist er beim Schreiben
         * hereingefallen.)
         */
        $motorzeit = 788.55;

        Http::fake([
            '*auth/accesstoken' => Http::response(['accesstoken' => str_repeat('a', 64), 'httpstatuscode' => 200]),
            '*auth/signin' => Http::response(['httpstatuscode' => 200]),
            '*maintenance/airplane/*' => function () use (&$motorzeit) {
                return Http::response(['motortime' => $motorzeit, 'httpstatuscode' => 200]);
            },
            '*' => Http::response(['httpstatuscode' => 200]),
        ]);

        app(ReadAircraftTimes::class)->handle($anbindung);

        $motorzeit = 790.10;
        app(ReadAircraftTimes::class)->handle($anbindung);

        $this->assertSame('790.10', $this->reading($flugzeug, CounterKind::EngineHours));
        $this->assertSame(2, CounterReading::where('kind', CounterKind::EngineHours)->count());
    }

    /**
     * Kein Mensch am Zählerstand.
     *
     * Diesen Stand hat niemand abgelesen — er kommt aus einer Schnittstelle.
     * Einen Menschen daranzuschreiben wäre eine Behauptung über eine Handlung,
     * die nicht stattgefunden hat.
     */
    #[Test]
    public function nobody_is_named_as_having_read_it(): void
    {
        $anbindung = $this->connection('Verein A');
        $flugzeug = $this->aircraft('D-KEWW');
        $this->link($anbindung, $flugzeug, 'D-KEWW');

        $this->fakeMaintenance(['motortime' => 788.55]);
        app(ReadAircraftTimes::class)->handle($anbindung);

        $stand = CounterReading::sole();

        $this->assertNull($stand->user_id);
        $this->assertStringContainsString('Vereinsflieger', (string) $stand->note);
    }

    /**
     * Ein Fehlschlag bei einer Maschine bringt die anderen nicht um ihre Zeiten.
     */
    #[Test]
    public function one_failing_aircraft_does_not_stop_the_others(): void
    {
        $anbindung = $this->connection('Verein A');
        $gut = $this->aircraft('D-KEWW');
        $schlecht = $this->aircraft('D-FALSCH');
        $this->link($anbindung, $gut, 'D-KEWW');
        $this->link($anbindung, $schlecht, 'D-FALSCH');

        Http::fake([
            '*auth/accesstoken' => Http::response(['accesstoken' => str_repeat('a', 64), 'httpstatuscode' => 200]),
            '*auth/signin' => Http::response(['httpstatuscode' => 200]),
            '*maintenance/airplane/D-KEWW' => Http::response(['motortime' => 788.55, 'httpstatuscode' => 200]),
            '*maintenance/airplane/D-FALSCH' => Http::response(['error' => 'unknown callsign'], 400),
            '*' => Http::response(['httpstatuscode' => 200]),
        ]);

        $ergebnis = app(ReadAircraftTimes::class)->handle($anbindung);

        $this->assertSame(1, $ergebnis['read']);
        $this->assertSame(1, $ergebnis['failed']);

        // Der Fehler steht an der Zeile und ist dort zu sehen.
        $this->assertNotNull(AircraftLink::where('callsign', 'D-FALSCH')->sole()->last_error);
    }

    #[Test]
    public function without_the_fleet_module_nothing_happens(): void
    {
        app(ModuleManager::class)->disable('fleet');

        $anbindung = $this->connection('Verein A');
        Http::fake();

        $this->assertSame(['read' => 0, 'failed' => 0, 'skipped' => 0], app(ReadAircraftTimes::class)->handle($anbindung));

        Http::assertNothingSent();
    }

    // ── Helfer ───────────────────────────────────────────────────────────────

    private function connection(string $name, bool $identities = false): Connection
    {
        return Connection::create([
            'name' => $name,
            'username' => 'test',
            'password' => 'geheim',
            'app_key' => 'schluessel',
            'provides_identities' => $identities,
            'is_active' => true,
        ]);
    }

    private function aircraft(string $kennzeichen): Aircraft
    {
        $muster = AircraftType::firstOrCreate(
            ['designation' => 'ASK 21'],
            ['manufacturer' => 'Alexander Schleicher'],
        );

        return Aircraft::create([
            'registration' => $kennzeichen,
            'model' => 'ASK 21',
            'aircraft_type_id' => $muster->id,
        ]);
    }

    private function link(Connection $anbindung, Aircraft $flugzeug, string $callsign): AircraftLink
    {
        return AircraftLink::create([
            'connection_id' => $anbindung->id,
            'aircraft_id' => $flugzeug->id,
            'callsign' => $callsign,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $werte
     */
    private function fakeMaintenance(array $werte): void
    {
        Http::fake([
            '*auth/accesstoken' => Http::response(['accesstoken' => str_repeat('a', 64), 'httpstatuscode' => 200]),
            '*auth/signin' => Http::response(['httpstatuscode' => 200]),
            '*maintenance/airplane/*' => Http::response($werte + ['httpstatuscode' => 200]),
            '*' => Http::response(['httpstatuscode' => 200]),
        ]);
    }

    private function reading(Aircraft $flugzeug, CounterKind $art): ?string
    {
        return CounterReading::query()
            ->where('aircraft_id', $flugzeug->id)
            ->where('kind', $art)
            ->orderByDesc('id')
            ->value('value');
    }
}
