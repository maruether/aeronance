<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Access\AccessSetup;
use App\Core\Identity\ExternalIdentity;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Vereinsflieger\Actions\SyncMembers;
use App\Modules\Vereinsflieger\Enums\MemberStatusHandling;
use App\Modules\Vereinsflieger\Models\Connection;
use App\Modules\Vereinsflieger\Models\MemberStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Der Mitglieder-Abgleich — F38 beantwortet.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „wer fehlt ist weg."
 *
 * `memberend` taugt nicht (leer bei allen 394), `memberstatus` auch nicht
 * (229 stehen auf „sonstige"). Das Merkmal ist die Anwesenheit in der Liste.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class VereinsfliegerSyncMembersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();
        app(ModuleManager::class)->enable('vereinsflieger');

        // Ohne entschiedenen Status entsteht kein Konto -- das ist eine andere
        // Regel und hier nicht der Gegenstand.
        MemberStatus::create(['msid' => '1', 'handling' => MemberStatusHandling::Active]);
    }

    #[Test]
    public function members_become_accounts(): void
    {
        $this->fakeMembers([
            ['uid' => '1', 'firstname' => 'Erika', 'lastname' => 'Meier', 'msid' => '1'],
            ['uid' => '2', 'firstname' => 'Hans', 'lastname' => 'Schmidt', 'msid' => '1'],
        ]);

        $ergebnis = app(SyncMembers::class)->handle($this->connection());

        $this->assertSame(2, $ergebnis['created']);
        $this->assertSame(0, $ergebnis['deactivated']);
        $this->assertSame(2, ExternalIdentity::where('provider', 'vereinsflieger')->count());
    }

    /**
     * DIE ANTWORT AUF F38: wer fehlt, verliert den Zugang.
     */
    #[Test]
    public function whoever_is_gone_loses_access(): void
    {
        $this->fakeMembers([
            ['uid' => '1', 'firstname' => 'Erika', 'lastname' => 'Meier', 'msid' => '1'],
            ['uid' => '2', 'firstname' => 'Hans', 'lastname' => 'Schmidt', 'msid' => '1'],
        ]);
        app(SyncMembers::class)->handle($this->connection());

        // Hans tritt aus und verschwindet aus der Liste.
        $this->fakeMembers([
            ['uid' => '1', 'firstname' => 'Erika', 'lastname' => 'Meier', 'msid' => '1'],
        ]);
        $ergebnis = app(SyncMembers::class)->handle($this->connection());

        $this->assertSame(1, $ergebnis['deactivated']);

        $hans = $this->userFor('2');
        $this->assertFalse((bool) $hans->is_active);
    }

    /**
     * Deaktiviert, nicht gelöscht.
     *
     * Ein gelöschtes Konto reisst Löcher in die Nachweiskette: Wer vor drei
     * Jahren an einem Flugzeug gearbeitet hat, steht in Arbeitskarten und
     * Freigaben, und dieser Name muss auf ein Konto zeigen können.
     */
    #[Test]
    public function gone_means_deactivated_not_deleted(): void
    {
        $this->fakeMembers([['uid' => '2', 'firstname' => 'Hans', 'lastname' => 'Schmidt', 'msid' => '1']]);
        app(SyncMembers::class)->handle($this->connection());

        $this->fakeMembers([['uid' => '1', 'firstname' => 'Erika', 'lastname' => 'Meier', 'msid' => '1']]);
        app(SyncMembers::class)->handle($this->connection());

        $this->assertNotNull($this->userFor('2'), 'Das Konto muss bleiben.');
        $this->assertDatabaseHas('external_identities', ['provider' => 'vereinsflieger', 'subject' => '2']);
    }

    /**
     * Und wer wiederkommt, bekommt sein altes Konto zurück.
     *
     * Genau dafür bleibt die Kennung stehen: Sonst entstünde ein zweites,
     * leeres Konto — ohne Rollen und ohne Vergangenheit.
     */
    #[Test]
    public function somebody_returning_gets_the_same_account_back(): void
    {
        $this->fakeMembers([['uid' => '2', 'firstname' => 'Hans', 'lastname' => 'Schmidt', 'msid' => '1']]);
        app(SyncMembers::class)->handle($this->connection());
        $vorher = $this->userFor('2')->id;

        $this->fakeMembers([['uid' => '1', 'firstname' => 'Erika', 'lastname' => 'Meier', 'msid' => '1']]);
        app(SyncMembers::class)->handle($this->connection());

        $this->fakeMembers([['uid' => '2', 'firstname' => 'Hans', 'lastname' => 'Schmidt', 'msid' => '1']]);
        app(SyncMembers::class)->handle($this->connection());

        $wieder = $this->userFor('2');

        $this->assertSame($vorher, $wieder->id, 'Dasselbe Konto, nicht ein zweites.');
        $this->assertTrue((bool) $wieder->is_active);

        // Die Zusage ist "kein zweites Konto FUER HANS" -- Erika ist im
        // mittleren Lauf regulaer dazugekommen und zaehlt selbstverstaendlich
        // mit.
        $this->assertSame(1, ExternalIdentity::query()
            ->where('provider', 'vereinsflieger')
            ->where('subject', '2')
            ->count());
        $this->assertSame(2, User::count());
    }

    /**
     * Der lokale Zugang bleibt unangetastet.
     *
     * Sonst spertte man sich mit dem ersten Abgleich selbst aus — der
     * Break-glass-Admin steht in keiner externen Liste.
     */
    #[Test]
    public function local_accounts_are_never_touched(): void
    {
        $lokal = User::factory()->create(['is_active' => true]);

        $this->fakeMembers([['uid' => '1', 'firstname' => 'Erika', 'lastname' => 'Meier', 'msid' => '1']]);
        app(SyncMembers::class)->handle($this->connection());

        $this->assertTrue((bool) $lokal->fresh()->is_active);
    }

    /**
     * EINE LEERE LISTE DEAKTIVIERT NIEMANDEN.
     *
     * Das ist keine zweite Meinung zu „wer fehlt ist weg", sondern die
     * Abgrenzung gegen einen Zustand, der gar keine Aussage ist: Ein Verein
     * verliert nicht über Nacht alle Mitglieder — da ist etwas kaputt.
     */
    #[Test]
    public function an_empty_list_deactivates_nobody(): void
    {
        $this->fakeMembers([['uid' => '1', 'firstname' => 'Erika', 'lastname' => 'Meier', 'msid' => '1']]);
        app(SyncMembers::class)->handle($this->connection());

        $this->fakeMembers([]);

        try {
            app(SyncMembers::class)->handle($this->connection());
            $this->fail('Eine leere Liste muss als Störung gemeldet werden.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('keine Mitglieder', $e->getMessage());
        }

        $this->assertTrue((bool) $this->userFor('1')->is_active);
    }

    /**
     * Eine Anbindung ohne den Haken legt niemanden an.
     *
     * Vorgabe: „mit mehreren anbindungen geben wir ggf leuten zugriff auf ein
     * cao system."
     */
    #[Test]
    public function a_connection_without_the_flag_creates_nobody(): void
    {
        $anbindung = Connection::create([
            'name' => 'Nachbarverein',
            'username' => 'test',
            'password' => 'geheim',
            'app_key' => 'schluessel',
            'provides_identities' => false,
            'is_active' => true,
        ]);

        Http::fake();

        $this->assertSame(
            ['created' => 0, 'updated' => 0, 'deactivated' => 0],
            app(SyncMembers::class)->handle($anbindung),
        );

        Http::assertNothingSent();
    }

    // ── Aufbau ───────────────────────────────────────────────────────────────

    private function connection(): Connection
    {
        return Connection::firstOrCreate(
            ['name' => 'Testverein'],
            [
                'username' => 'test',
                'password' => 'geheim',
                'app_key' => 'schluessel',
                'provides_identities' => true,
                'is_active' => true,
            ],
        );
    }

    private function userFor(string $subject): ?User
    {
        $id = ExternalIdentity::query()
            ->where('provider', 'vereinsflieger')
            ->where('subject', $subject)
            ->value('user_id');

        return $id !== null ? User::find($id) : null;
    }

    /**
     * Die Mitgliederliste, die der nachgestellte Dienst liefert.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * EINE Registrierung, veraenderlicher Inhalt -- und das ist kein Stilfrage:
     * Http::fake() ERGAENZT die Stubs, statt sie zu ersetzen. Ein zweiter
     * Aufruf haette den ersten also nicht ueberschrieben, und jeder Lauf haette
     * dieselbe Liste gesehen. Genau darauf sind hier zwei Tests hereingefallen
     * -- ein Austritt kam nie an.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @var list<array<string, mixed>>
     */
    private array $mitglieder = [];

    /**
     * @param  list<array<string, mixed>>  $mitglieder
     */
    private function fakeMembers(array $mitglieder): void
    {
        $this->mitglieder = $mitglieder;

        Http::fake([
            '*auth/accesstoken' => Http::response(['accesstoken' => str_repeat('a', 64), 'httpstatuscode' => 200]),
            '*auth/signin' => Http::response(['httpstatuscode' => 200]),
            '*user/list' => fn () => Http::response($this->mitglieder + ['httpstatuscode' => 200]),
            '*' => Http::response(['httpstatuscode' => 200]),
        ]);
    }
}
