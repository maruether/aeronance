<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Identity\ExternalSubject;
use App\Modules\Vereinsflieger\Models\Connection;
use App\Modules\Vereinsflieger\VereinsfliegerProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Der Vereinsflieger-Connector -- gegen einen nachgestellten Dienst.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WARUM NICHT GEGEN DEN ECHTEN: „Die Daten sind live und darauf läuft der
 * verein", und der Dienst ist mengenbegrenzt. Ein Test, der bei jedem Lauf
 * anklopft, ist bei einem solchen System kein Test, sondern ein Risiko.
 *
 * Geprüft wird deshalb das, was hier zu verantworten ist: dass aus der Antwort
 * die richtigen Funktionen entstehen -- und dass es EINE Anfrage bleibt.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class VereinsfliegerProviderTest extends TestCase
{
    /*
     * ─────────────────────────────────────────────────────────────────────────
     * DIESER TEST KAM OHNE DATENBANK AUS -- BIS ER ES NICHT MEHR TAT.
     *
     * Seit rawMembers() die Statusliste mitfuehrt, schreibt jeder Abruf in
     * vereinsflieger_member_statuses. Ohne Isolation blieben die Zeilen stehen
     * und tauchten in SPAETEREN Tests wieder auf -- ein "Gastpilot" aus diesem
     * Test liess den Zaehler eines anderen auf 3 statt 2 stehen.
     *
     * Aufgefallen ist das NUR im vollen Durchlauf. Einzeln war alles gruen --
     * dieselbe Lehre wie beim Backup: Ein isolierter Lauf beweist wenig.
     * ─────────────────────────────────────────────────────────────────────────
     */
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->identityConnection();
    }

    #[Test]
    public function functions_become_groups_with_their_head_count(): void
    {
        $this->fakeService([
            ['uid' => '1', 'firstname' => 'A', 'lastname' => 'Eins', 'functions' => ['Vorstand', 'Werkstattleiter']],
            ['uid' => '2', 'firstname' => 'B', 'lastname' => 'Zwei', 'functions' => ['Werkstattleiter']],
            ['uid' => '3', 'firstname' => 'C', 'lastname' => 'Drei', 'functions' => ['']],
        ]);

        $gezaehlt = $this->counted();

        $this->assertSame(1, $gezaehlt['funktion:Vorstand'] ?? null);
        $this->assertSame(2, $gezaehlt['funktion:Werkstattleiter'] ?? null);
    }

    /**
     * DER GRUND FUER DIE PRAEFIXE, und er ist gemessen.
     *
     * In der Referenzinstallation kommen VIER Namen in beiden Listen vor --
     * Fluglehrer, Werkstattleiter, Schriftfuehrer, Jugendleiter. Ohne Trennung
     * bekaeme „Fluglehrer als Vereinsamt" dieselben Rechte wie „Fluglehrer als
     * VF-Berechtigung", und niemand koennte die beiden auseinanderhalten.
     */
    #[Test]
    public function the_same_name_in_both_lists_stays_two_things(): void
    {
        $this->fakeService([
            ['uid' => '1', 'firstname' => 'A', 'lastname' => 'Eins',
                'functions' => ['Fluglehrer'], 'roles' => ['Fluglehrer']],
        ]);

        $gezaehlt = $this->counted();

        $this->assertArrayHasKey('funktion:Fluglehrer', $gezaehlt);
        $this->assertArrayHasKey('rolle:Fluglehrer', $gezaehlt);
    }

    #[Test]
    public function roles_are_a_level_of_their_own(): void
    {
        // Vorgabe: „es gibt rollen und funktionen, die brauchen wir auch."
        $this->fakeService([
            ['uid' => '1', 'firstname' => 'A', 'lastname' => 'Eins', 'roles' => ['Standard (Administrator)']],
        ]);

        $this->assertArrayHasKey('rolle:Standard (Administrator)', $this->counted());
    }

    /**
     * Der Status haengt an der NUMMER, nicht am Wort.
     *
     * Gemessen: msid ist fest -- aktiv=1, passiv=2, sonstige=6,
     * Ehrenmitglied=101, Externer Pilot=102. Ein Verein, der „Externer Pilot"
     * in „Gastpilot" umbenennt, behaelt die 102. Eine Zuordnung auf das Wort
     * waere am naechsten Tag still wirkungslos -- und still wirkungslose
     * Rechte sind die schlimmste Sorte.
     */
    #[Test]
    public function the_member_status_hangs_on_its_number(): void
    {
        $this->fakeService([
            ['uid' => '1', 'firstname' => 'A', 'lastname' => 'Eins',
                'msid' => '102', 'memberstatus' => 'Externer Pilot'],
        ]);

        $gruppen = iterator_to_array((new VereinsfliegerProvider)->groups(), false);

        $status = null;
        foreach ($gruppen as $gruppe) {
            if (str_starts_with($gruppe->value, 'status:')) {
                $status = $gruppe;
            }
        }

        $this->assertNotNull($status);
        $this->assertSame('status:102', $status->value);
        // Das Wort steht im Anzeigenamen -- lesbar, aber nicht tragend.
        $this->assertStringContainsString('Externer Pilot', (string) $status->label);
    }

    #[Test]
    public function a_renamed_status_keeps_its_mapping(): void
    {
        $this->fakeService([
            ['uid' => '1', 'firstname' => 'A', 'lastname' => 'Eins',
                'msid' => '102', 'memberstatus' => 'Gastpilot'],
        ]);

        $this->assertArrayHasKey('status:102', $this->counted());
    }

    /**
     * @return array<string, int|null>
     */
    private function counted(): array
    {
        $gezaehlt = [];

        foreach ((new VereinsfliegerProvider)->groups() as $gruppe) {
            $gezaehlt[$gruppe->value] = $gruppe->memberCount;
        }

        return $gezaehlt;
    }

    /**
     * Die Sparsamkeit ist hier eine Zusage, keine Absicht.
     *
     * Vereinsflieger hat keinen Endpunkt für Vereinsfunktionen -- sie entstehen
     * aus den Mitgliedern. Würden members() und groups() je selbst abrufen,
     * kostete ein Abgleich das Doppelte für dieselben Daten.
     */
    #[Test]
    public function members_and_groups_share_a_single_request(): void
    {
        $this->fakeService([
            ['uid' => '1', 'firstname' => 'A', 'lastname' => 'Eins', 'functions' => 'Vorstand'],
        ]);

        $provider = new VereinsfliegerProvider;

        iterator_to_array($provider->members(), false);
        iterator_to_array($provider->groups(), false);

        Http::assertSentCount(4); // accesstoken, signin, user/list, signout

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
    public function a_member_becomes_a_subject_with_its_functions(): void
    {
        $this->fakeService([
            ['uid' => '4711', 'username' => 'emeier', 'firstname' => 'Erika', 'lastname' => 'Meier',
                'email' => 'erika@example.org', 'functions' => ['Werkstattleiter', 'Vorstand']],
        ]);

        /** @var list<ExternalSubject> $subjekte */
        $subjekte = iterator_to_array((new VereinsfliegerProvider)->members(), false);

        $this->assertCount(1, $subjekte);
        $this->assertSame('4711', $subjekte[0]->id);
        $this->assertSame('Erika Meier', $subjekte[0]->name);
        /*
         * Ohne msid gilt die Anmeldung selbst als Nachweis -- auth/getuser
         * liefert eine schmalere Antwort als user/list. Deshalb steht hier
         * "mitglied:aktiv" mit dabei: Wer sich gerade erfolgreich angemeldet
         * hat, ist offensichtlich niemand, den man ignorieren will.
         */
        $this->assertSame(
            ['funktion:Werkstattleiter', 'funktion:Vorstand', 'mitglied:aktiv'],
            array_values($subjekte[0]->groups),
        );
    }

    /**
     * Eine abgelehnte Anmeldung nennt den Grund.
     *
     * Genau das Wegwerfen dieser Begründung hat in der Entwicklung zwei
     * Versuche gegen ein mengenbegrenztes Produktivsystem gekostet.
     */
    #[Test]
    public function a_rejected_instance_login_carries_the_reason(): void
    {
        Http::fake([
            '*auth/accesstoken' => Http::response(['accesstoken' => str_repeat('a', 64), 'httpstatuscode' => 200]),
            '*auth/signin' => Http::response(
                ['httpstatuscode' => 403, 'need_2fa' => 0, 'error' => 'Wrong User or wrong Password'],
                403,
            ),
            '*' => Http::response(['httpstatuscode' => 200]),
        ]);

        $this->expectExceptionMessageMatches('/Wrong User or wrong Password/');

        iterator_to_array((new VereinsfliegerProvider)->members(), false);
    }

    /**
     * @param  list<array<string, string>>  $mitglieder
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
