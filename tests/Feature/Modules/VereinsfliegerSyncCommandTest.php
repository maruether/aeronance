<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Identity\ExternalGroup;
use App\Core\Modules\ModuleManager;
use App\Modules\Vereinsflieger\Jobs\SyncConnectionJob;
use App\Modules\Vereinsflieger\Models\Connection;
use App\Modules\Vereinsflieger\Models\MemberStatus;
use App\Modules\Vereinsflieger\Models\WorkHourCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Der eine Abruf am Tag.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „Der VF abruf findet bitte genau einmal am tag um 2 uhr morgens
 * statt."
 *
 * Vorher gab es gar keinen Zeitplan — geholt wurde nur, wenn jemand zufällig
 * auf die richtige Seite ging und einen Knopf drückte.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class VereinsfliegerSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->identityConnection();

        // Der Befehl fragt die Modulverwaltung, bevor er etwas tut -- so wie
        // jeder Modulbefehl (D1). Ohne das schweigt er hier hoeflich.
        app(ModuleManager::class)->enable('vereinsflieger');
    }

    /**
     * Ein Lauf, eine Antwort, zwei Ergebnisse.
     *
     * Vereinsflieger kennt weder einen Endpunkt für Funktionen noch einen für
     * Mitgliedsstatus — beides steht nur an den Mitgliedern. Ein zweiter Abruf
     * für dieselben Daten wäre die doppelte Last auf einem fremden Server.
     */
    #[Test]
    public function one_run_fetches_the_member_list_exactly_once(): void
    {
        $this->fakeService([
            ['uid' => '1', 'msid' => '1', 'memberstatus' => 'aktiv', 'functions' => ['Zellenwart']],
            ['uid' => '2', 'msid' => '6', 'memberstatus' => 'sonstige'],
        ]);

        $this->artisan('aeronance:vereinsflieger-sync')->assertSuccessful();

        $abrufe = 0;
        Http::assertSent(function (Request $request) use (&$abrufe): bool {
            if (str_ends_with($request->url(), 'user/list')) {
                $abrufe++;
            }

            return true;
        });

        $this->assertSame(1, $abrufe, 'user/list darf genau einmal abgerufen werden.');
    }

    #[Test]
    public function the_run_records_groups_and_statuses_together(): void
    {
        $this->fakeService([
            ['uid' => '1', 'msid' => '1', 'memberstatus' => 'aktiv', 'functions' => ['Zellenwart']],
            ['uid' => '2', 'msid' => '6', 'memberstatus' => 'sonstige'],
        ]);

        $this->artisan('aeronance:vereinsflieger-sync')->assertSuccessful();

        $this->assertTrue(
            ExternalGroup::query()->where('value', 'funktion:Zellenwart')->exists(),
            'Die Funktion muss in der Zuordnungsliste auftauchen.',
        );

        $this->assertSame(2, MemberStatus::count());
        $this->assertTrue(MemberStatus::query()->where('msid', '6')->sole()->isUndecided());
    }

    /**
     * Ohne Zugang ist nichts zu tun — und das ist kein Fehler.
     *
     * Sonst brächte der nächtliche Eintrag jeder Installation ohne
     * Vereinsflieger einen Fehlschlag, den niemand lesen will.
     */
    #[Test]
    public function without_credentials_the_run_is_quiet(): void
    {
        // Keine aktive Anbindung -- der Fall einer Installation, die das Modul
        // an hat, aber noch nichts eingerichtet.
        Connection::query()->update(['is_active' => false]);
        Http::fake();

        $this->artisan('aeronance:vereinsflieger-sync')->assertSuccessful();

        Http::assertNothingSent();
    }

    /**
     * Ein Fehlschlag nennt den Grund.
     *
     * „Abruf fehlgeschlagen" ohne Begründung hat in diesem Projekt schon zwei
     * Anmeldungen gegen ein produktives System gekostet.
     */
    #[Test]
    public function a_rejected_login_carries_its_reason_into_the_output(): void
    {
        Http::fake([
            '*auth/accesstoken' => Http::response(['accesstoken' => str_repeat('a', 64), 'httpstatuscode' => 200]),
            '*auth/signin' => Http::response(
                ['httpstatuscode' => 403, 'error' => 'Wrong User or wrong Password'],
                403,
            ),
            '*' => Http::response(['httpstatuscode' => 200]),
        ]);

        $this->artisan('aeronance:vereinsflieger-sync')
            ->expectsOutputToContain('Wrong User or wrong Password')
            ->assertFailed();
    }

    /**
     * Die Kategorien laufen im Abgleich mit -- fuer die Auswahlliste.
     *
     * Nutzlast wie GEMESSEN am echten Dienst: 'category' als Nummer, 'name'
     * mit HTML-Entities ("Wartung&#47;Werkstatt"), 'enabled' als '0'/'1' --
     * und die abgeschaltete 7813 ("Aeronance") bleibt drin, denn ueber die
     * Schnittstelle ist sie trotzdem beschreibbar.
     */
    #[Test]
    public function the_run_remembers_work_hour_categories(): void
    {
        Http::fake([
            '*auth/accesstoken' => Http::response(['accesstoken' => str_repeat('a', 64), 'httpstatuscode' => 200]),
            '*auth/signin' => Http::response(['httpstatuscode' => 200]),
            '*user/list' => Http::response([
                ['uid' => '1', 'msid' => '1', 'memberstatus' => 'aktiv'],
                'httpstatuscode' => 200,
            ]),
            '*workhourcategories/list' => Http::response([
                ['category' => '7265', 'name' => 'Wartung&#47;Werkstatt', 'enabled' => '1'],
                ['category' => '7813', 'name' => 'Aeronance', 'enabled' => '0'],
                'httpstatuscode' => 200,
            ]),
            '*' => Http::response(['httpstatuscode' => 200]),
        ]);

        $this->artisan('aeronance:vereinsflieger-sync')->assertSuccessful();

        $wartung = WorkHourCategory::query()->where('category', '7265')->sole();
        $this->assertSame('Wartung/Werkstatt', $wartung->name, 'Entities muessen dekodiert sein.');
        $this->assertTrue($wartung->enabled);

        $this->assertFalse(WorkHourCategory::query()->where('category', '7813')->sole()->enabled);
    }

    /**
     * Ein sterbender Kategorien-Abruf reisst den Lauf NICHT mehr ab.
     *
     * Gemessen am 12.08.: Nach einem Update ohne Migration fehlte die
     * Kategorien-Tabelle, die Ausnahme flog bis zum Aufrufer -- und die
     * Betriebszeiten, der eigentliche Zweck des Nachtlaufs, wurden zwei
     * Naechte lang nicht gelesen. Die Auswahlliste ist Komfort; sie hat
     * nicht das Recht, die Pflicht zu verhindern.
     */
    #[Test]
    public function a_failing_category_fetch_does_not_kill_the_run(): void
    {
        Http::fake([
            '*auth/accesstoken' => Http::response(['accesstoken' => str_repeat('a', 64), 'httpstatuscode' => 200]),
            '*auth/signin' => Http::response(['httpstatuscode' => 200]),
            '*user/list' => Http::response([
                ['uid' => '1', 'msid' => '1', 'memberstatus' => 'aktiv'],
                'httpstatuscode' => 200,
            ]),
            '*workhourcategories/list' => Http::response('kaputt', 500),
            '*' => Http::response(['httpstatuscode' => 200]),
        ]);

        $this->artisan('aeronance:vereinsflieger-sync')
            ->expectsOutputToContain('Kategorien nicht gelesen')
            ->assertSuccessful();

        // Der Lauf gilt als gelaufen, nicht als gescheitert -- der Fehler
        // stand in der Ausgabe, die Anbindung traegt keinen Dauerfehler.
        $this->assertNull(Connection::sole()->last_error);
    }

    /**
     * Der Knopf-Weg: derselbe Abgleich als Job, Ergebnis an der Anbindung.
     *
     * Rueckmeldung aus dem Betrieb: "es dauert und es wird nicht darauf
     * hingewiesen" -- deshalb laeuft der Klick als Job im Worker, und der
     * Erfolg wie der Fehlschlag stehen dort, wo auch der Nachtlauf sie
     * hinschreibt: an der Anbindung.
     */
    #[Test]
    public function the_sync_job_records_its_run_at_the_connection(): void
    {
        $this->fakeService([
            ['uid' => '1', 'msid' => '1', 'memberstatus' => 'aktiv'],
        ]);

        $anbindung = Connection::sole();
        $this->assertNull($anbindung->last_run_at);

        (new SyncConnectionJob($anbindung->getKey()))->handle();

        $anbindung->refresh();
        $this->assertNotNull($anbindung->last_run_at);
        $this->assertNull($anbindung->last_error);
    }

    #[Test]
    public function the_sync_job_records_the_failure_reason(): void
    {
        Http::fake([
            '*auth/accesstoken' => Http::response(['accesstoken' => str_repeat('a', 64), 'httpstatuscode' => 200]),
            '*auth/signin' => Http::response(
                ['httpstatuscode' => 403, 'error' => 'Wrong User or wrong Password'],
                403,
            ),
            '*' => Http::response(['httpstatuscode' => 200]),
        ]);

        (new SyncConnectionJob(Connection::sole()->getKey()))->handle();

        $this->assertStringContainsString(
            'Wrong User or wrong Password',
            (string) Connection::sole()->last_error,
            'Der Grund muss an der Anbindung stehen -- nicht nur im Log.',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $mitglieder
     */
    private function fakeService(array $mitglieder): void
    {
        Http::fake([
            '*auth/accesstoken' => Http::response(['accesstoken' => str_repeat('a', 64), 'httpstatuscode' => 200]),
            '*auth/signin' => Http::response(['httpstatuscode' => 200]),
            '*user/list' => Http::response($mitglieder + ['httpstatuscode' => 200]),
            '*' => Http::response(['httpstatuscode' => 200]),
        ]);
    }

    /**
     * Eine Anbindung, aus der Menschen kommen.
     *
     * Seit die Zugaenge Datensaetze sind (mehrere Vereine, CAO-Umfeld), reicht
     * es nicht mehr, config() zu setzen -- der Provider liest aus der Tabelle.
     */
    private function identityConnection(): Connection
    {
        return Connection::create([
            'name' => 'Testverein',
            'username' => 'test',
            'password' => 'geheim',
            'app_key' => 'schluessel',
            'provides_identities' => true,
            'is_active' => true,
        ]);
    }
}
