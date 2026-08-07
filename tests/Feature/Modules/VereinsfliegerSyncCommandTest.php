<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Identity\ExternalGroup;
use App\Core\Modules\ModuleManager;
use App\Modules\Vereinsflieger\Models\Connection;
use App\Modules\Vereinsflieger\Models\MemberStatus;
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
