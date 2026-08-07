<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Modules\Vereinsflieger\Actions\RememberMemberStatuses;
use App\Modules\Vereinsflieger\Enums\MemberStatusHandling;
use App\Modules\Vereinsflieger\Models\Connection;
use App\Modules\Vereinsflieger\Models\MemberStatus;
use App\Modules\Vereinsflieger\VereinsfliegerProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Der Mitgliedsstatus entscheidet, ob es die Person überhaupt gibt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „bei memberstatus interessieren mich initial nur 1 und 2. alle
 * anderen soll das modul initial abrufen und den admin entscheiden lassen was
 * damit passiert (als aktives oder passives mitglied führen oder als nicht
 * vorhanden ignorieren)."
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class VereinsfliegerMemberStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->identityConnection();
    }

    #[Test]
    public function only_one_and_two_are_decided_in_advance(): void
    {
        $this->remember();

        $this->assertSame(MemberStatusHandling::Active, MemberStatus::handlingFor('1'));
        $this->assertSame(MemberStatusHandling::Passive, MemberStatus::handlingFor('2'));
    }

    /**
     * Alles andere wartet — auch der Status mit den meisten Menschen.
     *
     * Ein geratener Vorschlag wäre hier schlimmer als keiner: Wer eine
     * Voreinstellung sieht, nickt sie ab.
     */
    #[Test]
    public function every_other_status_waits_for_a_decision(): void
    {
        $this->remember();

        foreach (['6', '101', '102'] as $msid) {
            $this->assertNull(MemberStatus::handlingFor($msid), "msid {$msid}");
            $this->assertTrue(MemberStatus::query()->where('msid', $msid)->sole()->isUndecided());
        }
    }

    #[Test]
    public function the_head_count_is_recorded_with_it(): void
    {
        // Wer entscheidet, ob ein Status Konten bekommt, soll sehen, um wie
        // viele Menschen es geht -- BEVOR er entscheidet.
        $this->remember();

        $this->assertSame(229, MemberStatus::query()->where('msid', '6')->sole()->member_count);
        $this->assertSame('sonstige', MemberStatus::query()->where('msid', '6')->sole()->label);
    }

    /**
     * Eine getroffene Entscheidung überlebt den nächsten Abruf.
     *
     * Auch bei 1 und 2: Wer „aktiv" bewusst auf „ignorieren" gestellt hat, will
     * das behalten.
     */
    #[Test]
    public function a_decision_is_never_overwritten(): void
    {
        $this->remember();

        MemberStatus::query()->where('msid', '1')->update(['handling' => MemberStatusHandling::Ignore]);
        MemberStatus::query()->where('msid', '6')->update(['handling' => MemberStatusHandling::Active]);

        $this->remember();

        $this->assertSame(MemberStatusHandling::Ignore, MemberStatus::handlingFor('1'));
        $this->assertSame(MemberStatusHandling::Active, MemberStatus::handlingFor('6'));
    }

    // ── Wirkung auf den Abgleich ─────────────────────────────────────────────

    #[Test]
    public function an_undecided_status_produces_no_account(): void
    {
        $this->fakeService([
            ['uid' => '1', 'firstname' => 'A', 'lastname' => 'Eins', 'msid' => '6', 'memberstatus' => 'sonstige'],
        ]);

        $this->assertCount(0, iterator_to_array((new VereinsfliegerProvider)->members(), false));
    }

    #[Test]
    public function an_ignored_status_produces_no_account_either(): void
    {
        MemberStatus::create(['msid' => '6', 'handling' => MemberStatusHandling::Ignore]);

        $this->fakeService([
            ['uid' => '1', 'firstname' => 'A', 'lastname' => 'Eins', 'msid' => '6'],
        ]);

        $this->assertCount(0, iterator_to_array((new VereinsfliegerProvider)->members(), false));
    }

    #[Test]
    public function an_active_status_produces_an_account_with_access(): void
    {
        MemberStatus::create(['msid' => '1', 'handling' => MemberStatusHandling::Active]);

        $this->fakeService([
            ['uid' => '4711', 'firstname' => 'Erika', 'lastname' => 'Meier', 'msid' => '1'],
        ]);

        $subjekte = iterator_to_array((new VereinsfliegerProvider)->members(), false);

        $this->assertCount(1, $subjekte);
        $this->assertTrue($subjekte[0]->active);
    }

    /**
     * Passiv darf sich anmelden -- der Unterschied liegt in der Zuordnung.
     *
     * Vorgabe: „passiv darf sich anmelden, die rechte werden nach memberstatus
     * und funktion gemappt." Meine erste Auslegung („Konto ohne Zugang") war
     * falsch und ist hier festgeschrieben, damit sie nicht zurueckkommt.
     */
    #[Test]
    public function a_passive_status_may_sign_in_too(): void
    {
        MemberStatus::create(['msid' => '2', 'handling' => MemberStatusHandling::Passive]);

        $this->fakeService([
            ['uid' => '4712', 'firstname' => 'Hans', 'lastname' => 'Meier', 'msid' => '2'],
        ]);

        $subjekte = iterator_to_array((new VereinsfliegerProvider)->members(), false);

        $this->assertCount(1, $subjekte);
        $this->assertTrue($subjekte[0]->active);
        $this->assertContains('mitglied:passiv', $subjekte[0]->groups);
    }

    /**
     * Die Einordnung ist eine SAMMELGRUPPE, und das ist ihr Zweck.
     *
     * Wer „Ehrenmitglied" (101) als aktives Mitglied fuehrt, schreibt seine
     * Regel einmal fuer „aktiv" statt fuer jede Statusnummer neu. Die genaue
     * Nummer bleibt trotzdem dabei, falls jemand doch unterscheiden will.
     */
    #[Test]
    public function a_status_led_as_active_joins_the_active_group(): void
    {
        MemberStatus::create(['msid' => '101', 'handling' => MemberStatusHandling::Active]);

        $this->fakeService([
            ['uid' => '9', 'firstname' => 'Alt', 'lastname' => 'Meister', 'msid' => '101',
                'memberstatus' => 'Ehrenmitglied'],
        ]);

        $subjekte = iterator_to_array((new VereinsfliegerProvider)->members(), false);

        $this->assertContains('mitglied:aktiv', $subjekte[0]->groups);
        $this->assertContains('status:101', $subjekte[0]->groups);
    }

    /**
     * Ein Konto allein kann nichts.
     *
     * Vorgabe: „Wenn irgendwann eine funktion dazu kommt hat sie einfach keine
     * rechte und kann nachgemappt werden." Genau dieser Zustand -- angemeldet,
     * aber ohne Rechte -- muss der Normalfall sein und darf nicht wehtun.
     */
    #[Test]
    public function an_account_without_a_mapping_carries_no_rights(): void
    {
        MemberStatus::create(['msid' => '1', 'handling' => MemberStatusHandling::Active]);

        $this->fakeService([
            ['uid' => '7', 'firstname' => 'Neue', 'lastname' => 'Funktion', 'msid' => '1',
                'functions' => ['Kaffeewart']],
        ]);

        $subjekte = iterator_to_array((new VereinsfliegerProvider)->members(), false);

        // Die Funktion wird gemeldet -- was sie bedeutet, entscheidet der Kern,
        // und ohne Zuordnung bedeutet sie nichts.
        $this->assertContains('funktion:Kaffeewart', $subjekte[0]->groups);
        $this->assertTrue($subjekte[0]->active);
    }

    /**
     * ÜBER VEREINSFLIEGER MELDET SICH NIEMAND AN.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Vorgabe: „eine anmeldung über den VF geht nicht. das bietet der nicht an
     * soweit ich weiß." Nachgeprüft und bestätigt: `auth/signin` verlangt den
     * appkey der ANWENDUNG — es ist ein API-Zugang, kein Anmeldeverfahren für
     * Dritte. Kein OAuth, kein Token, und das Passwort müsste durch Aeronance
     * fließen.
     *
     * Hier stand vorher ein Test, der genau das prüfte („ohne msid gilt die
     * Anmeldung selbst als Nachweis"). Er ist ersetzt, nicht gelöscht: Die
     * Zusage lautet jetzt umgekehrt, und sie gehört festgeschrieben, damit
     * niemand den Weg versehentlich wieder aufmacht.
     * ─────────────────────────────────────────────────────────────────────────
     */
    #[Test]
    public function vereinsflieger_offers_no_password_login(): void
    {
        $provider = new VereinsfliegerProvider;

        $this->assertFalse($provider->supportsPassword());
        $this->assertNull($provider->authenticate('emeier', 'geheim'));
    }

    #[Test]
    public function the_statuses_are_read_off_the_member_list(): void
    {
        $this->fakeService([
            ['uid' => '1', 'msid' => '1', 'memberstatus' => 'aktiv'],
            ['uid' => '2', 'msid' => '1', 'memberstatus' => 'aktiv'],
            ['uid' => '3', 'msid' => '102', 'memberstatus' => 'Externer Pilot'],
        ]);

        $gefunden = (new VereinsfliegerProvider)->memberStatuses();

        $this->assertSame(
            [
                ['msid' => '1', 'label' => 'aktiv', 'count' => 2],
                ['msid' => '102', 'label' => 'Externer Pilot', 'count' => 1],
            ],
            $gefunden,
        );
    }

    /**
     * Ein neuer Status faellt beim naechsten Lauf von selbst auf.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Vorgabe: „denke daran das die anderen IDs auf aktiv, passiv oder
     * ignorieren gemappt sein müssen. das macht der admin manuell."
     *
     * Damit er das kann, muss er den Status SEHEN. Legt der Verein spaeter
     * einen an, fiele derjenige, der ihn traegt, sonst still aus dem Abgleich
     * -- kein Konto, kein Hinweis, und niemand kann etwas zuordnen, das er
     * nicht sieht.
     * ─────────────────────────────────────────────────────────────────────────
     */
    #[Test]
    public function a_status_seen_during_a_sync_lands_in_the_list(): void
    {
        $this->assertSame(0, MemberStatus::count());

        $this->fakeService([
            ['uid' => '1', 'firstname' => 'A', 'lastname' => 'Eins', 'msid' => '1', 'memberstatus' => 'aktiv'],
            ['uid' => '2', 'firstname' => 'B', 'lastname' => 'Zwei', 'msid' => '77', 'memberstatus' => 'Neuer Status'],
        ]);

        iterator_to_array((new VereinsfliegerProvider)->members(), false);

        $neu = MemberStatus::query()->where('msid', '77')->sole();

        $this->assertSame('Neuer Status', $neu->label);
        $this->assertSame(1, $neu->member_count);
        $this->assertTrue($neu->isUndecided(), 'Geraten wird nichts -- der Mensch entscheidet.');
    }

    /**
     * Und der Mensch dahinter bekommt trotzdem kein Konto.
     *
     * Sichtbar heisst nicht zugelassen. Beides zusammen ist der Punkt: Der
     * Status taucht auf und wartet, statt dass jemand unbemerkt hereinkommt
     * oder unbemerkt fehlt.
     */
    #[Test]
    public function but_that_person_still_gets_no_account(): void
    {
        $this->fakeService([
            ['uid' => '2', 'firstname' => 'B', 'lastname' => 'Zwei', 'msid' => '77', 'memberstatus' => 'Neuer Status'],
        ]);

        $this->assertCount(0, iterator_to_array((new VereinsfliegerProvider)->members(), false));
        $this->assertSame(1, MemberStatus::query()->whereNull('handling')->count());
    }

    /**
     * @return array{seen: int, new: int, undecided: int}
     */
    private function remember(): array
    {
        return app(RememberMemberStatuses::class)->handle([
            ['msid' => '1', 'label' => 'aktiv', 'count' => 91],
            ['msid' => '2', 'label' => 'passiv', 'count' => 60],
            ['msid' => '6', 'label' => 'sonstige', 'count' => 229],
            ['msid' => '101', 'label' => 'Ehrenmitglied', 'count' => 3],
            ['msid' => '102', 'label' => 'Externer Pilot', 'count' => 11],
        ]);
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
