<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Identity\ExternalIdentity;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AircraftType;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Enums\ParticipationKind;
use App\Modules\Vereinsflieger\Actions\TransferWorkHours;
use App\Modules\Vereinsflieger\Models\Connection;
use App\Modules\Vereinsflieger\Models\WorkHourTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Arbeitsstunden nach Vereinsflieger.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DREI GEMESSENE TATSACHEN BESTIMMEN DIESEN TEST:
 *
 *  1. `workhours/add` nimmt `status` entgegen — eine Stunde kann gleich als
 *     „Akzeptiert" ankommen und ist damit drüben unveränderlich.
 *  2. Identische Daten erzeugen einen ZWEITEN Eintrag. Vereinsflieger prüft
 *     nichts.
 *  3. Löschen und Ändern gibt es nicht. Eine Doppelbuchung ist dauerhaft.
 *
 * Aus 2 und 3 folgt die Sperrtabelle — und dass sie hier geprüft wird.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class VereinsfliegerWorkHoursTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(ModuleManager::class)->enable('vereinsflieger');
        app(ModuleManager::class)->enable('taskcards');
        app(ModuleManager::class)->enable('fleet');

        config()->set('aeronance.vereinsflieger.workhours.enabled', true);
        config()->set('aeronance.vereinsflieger.workhours.category', '7813');
        config()->set('aeronance.vereinsflieger.workhours.status', '2');
    }

    /**
     * die Vorgabe für den Text: Kennzeichen | Workorder | Tätigkeit.
     */
    #[Test]
    public function the_entry_carries_registration_work_order_and_task(): void
    {
        $this->arrange();
        $this->fakeAdd();

        app(TransferWorkHours::class)->handle($this->connection());

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), 'workhours/add')) {
                return false;
            }

            return $request['jobtext'] === 'D-KEWW | '.$this->workOrderNumber.' | Ölwechsel';
        });
    }

    /**
     * Der Status kommt aus der Einstellung.
     *
     * „Akzeptiert" spart dem Werkstattleiter die Freigabe UND macht den Eintrag
     * drüben unveränderlich — solange er „nicht bewertet" ist, kann das
     * Mitglied ihn dort noch ändern.
     */
    #[Test]
    public function the_status_comes_from_the_setting(): void
    {
        $this->arrange();
        $this->fakeAdd();

        app(TransferWorkHours::class)->handle($this->connection());

        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), 'workhours/add')
            && $r['status'] === '2'
            && $r['category'] === '7813');
    }

    /** Minuten werden zu „HH:MM" — gemessen an der Antwort des Dienstes. */
    #[Test]
    public function minutes_become_hours_and_minutes(): void
    {
        $this->assertSame('01:00', TransferWorkHours::asHoursMinutes(60));
        $this->assertSame('01:45', TransferWorkHours::asHoursMinutes(105));
        $this->assertSame('00:15', TransferWorkHours::asHoursMinutes(15));
        $this->assertSame('12:30', TransferWorkHours::asHoursMinutes(750));
    }

    /**
     * DIE WICHTIGSTE ZUSAGE: keine Doppelbuchung.
     *
     * Vereinsflieger würde einen zweiten Eintrag anlegen und könnte ihn nicht
     * mehr löschen. Zwei Läufe dürfen also genau eine Buchung ergeben.
     */
    #[Test]
    public function a_second_run_books_nothing_twice(): void
    {
        $this->arrange();
        $this->fakeAdd();

        $erster = app(TransferWorkHours::class)->handle($this->connection());
        $zweiter = app(TransferWorkHours::class)->handle($this->connection());

        $this->assertSame(1, $erster['sent']);
        $this->assertSame(0, $zweiter['sent']);

        $anfragen = 0;
        Http::assertSent(function (Request $r) use (&$anfragen): bool {
            if (str_ends_with($r->url(), 'workhours/add')) {
                $anfragen++;
            }

            return true;
        });

        $this->assertSame(1, $anfragen, 'workhours/add darf genau einmal gerufen werden.');
    }

    #[Test]
    public function what_was_sent_is_kept(): void
    {
        // Nach dem Senden kann drüben niemand mehr etwas ändern — also ist das
        // hier die einzige Stelle, an der steht, was dort ankam.
        $this->arrange();
        $this->fakeAdd();

        app(TransferWorkHours::class)->handle($this->connection());

        $beleg = WorkHourTransfer::sole();

        $this->assertSame('2169897', $beleg->whid);
        $this->assertSame('01:00', $beleg->hours);
        $this->assertSame('D-KEWW | '.$this->workOrderNumber.' | Ölwechsel', $beleg->job_text);
        $this->assertTrue($beleg->succeeded());
    }

    /**
     * Ein Fehlschlag hinterlässt eine Zeile — und wird nicht ewig wiederholt.
     *
     * Sonst versuchte es jede Nacht erneut, was gestern schon scheiterte, gegen
     * einen mengenbegrenzten Dienst und ohne dass es jemand merkt.
     */
    #[Test]
    public function a_failure_is_recorded_and_not_retried_forever(): void
    {
        $this->arrange();

        Http::fake([
            '*auth/accesstoken' => Http::response(['accesstoken' => str_repeat('a', 64), 'httpstatuscode' => 200]),
            '*auth/signin' => Http::response(['httpstatuscode' => 200]),
            '*workhours/add' => Http::response(['error' => 'category unknown'], 400),
            '*' => Http::response(['httpstatuscode' => 200]),
        ]);

        // Drei Laeufe, drei Versuche -- und beim vierten ist Schluss.
        foreach ([1, 2, 3] as $lauf) {
            $ergebnis = app(TransferWorkHours::class)->handle($this->connection());
            $this->assertSame(1, $ergebnis['failed'], "Lauf {$lauf}");
        }

        $vierter = app(TransferWorkHours::class)->handle($this->connection());

        $this->assertSame(0, $vierter['failed'], 'Nach drei Versuchen wird nicht mehr gesendet.');

        $beleg = WorkHourTransfer::sole();

        $this->assertSame(3, $beleg->attempts);
        $this->assertTrue($beleg->gaveUp());
        $this->assertFalse($beleg->mayRetry());
        $this->assertStringContainsString('category unknown', (string) $beleg->last_error);
    }

    /**
     * DER FALL, DEN DAS NACHSEHEN LOEST: keine Antwort, Eintrag trotzdem da.
     *
     * Vorgabe: „nach eintagung der stunden muss das tool einmal alles abrufen
     * und prüfen ob die einträge da sind." Ein Timeout heisst nicht „nicht
     * angekommen" — er heisst „unbekannt". Wer daraufhin blind wiederholt,
     * bucht doppelt, und löschen kann Vereinsflieger nicht.
     */
    #[Test]
    public function an_entry_that_arrived_despite_a_lost_answer_is_recognised(): void
    {
        $this->arrange();

        $text = 'D-KEWW | '.$this->workOrderNumber.' | Ölwechsel';

        Http::fake([
            '*auth/accesstoken' => Http::response(['accesstoken' => str_repeat('a', 64), 'httpstatuscode' => 200]),
            '*auth/signin' => Http::response(['httpstatuscode' => 200]),

            // Die Antwort geht verloren -- der Eintrag drüben aber nicht.
            '*workhours/add' => Http::response(['error' => 'gateway timeout'], 504),

            '*workhours/list/daterange' => Http::response([
                // Wie der echte Dienst antwortet -- mit Kategorie und Status.
                // Beide gehen in die Wiedererkennung ein (die Idee), damit
                // ein von Hand angelegter Eintrag nicht faelschlich als der
                // eigene durchgeht.
                ['whid' => 2169897, 'uid' => '90344', 'jobdate' => '2026-08-04',
                    'category' => '7813', 'status' => '2', 'jobtext' => $text],
                'httpstatuscode' => 200,
            ]),
            '*' => Http::response(['httpstatuscode' => 200]),
        ]);

        $ergebnis = app(TransferWorkHours::class)->handle($this->connection());

        // Nicht als Fehlschlag gezählt -- er ist ja da.
        $this->assertSame(1, $ergebnis['sent']);
        $this->assertSame(0, $ergebnis['failed']);

        $beleg = WorkHourTransfer::sole();

        $this->assertSame('2169897', $beleg->whid);
        $this->assertTrue($beleg->succeeded());
        $this->assertNotNull($beleg->verified_at);
    }

    /**
     * Und dann wird auch nicht mehr gesendet.
     *
     * Das ist der Punkt der ganzen Übung: Eine wiedergefundene Buchung darf
     * keine zweite nach sich ziehen.
     */
    #[Test]
    public function a_recovered_entry_is_never_sent_again(): void
    {
        $this->arrange();

        $text = 'D-KEWW | '.$this->workOrderNumber.' | Ölwechsel';

        Http::fake([
            '*auth/accesstoken' => Http::response(['accesstoken' => str_repeat('a', 64), 'httpstatuscode' => 200]),
            '*auth/signin' => Http::response(['httpstatuscode' => 200]),
            '*workhours/add' => Http::response(['error' => 'gateway timeout'], 504),
            '*workhours/list/daterange' => Http::response([
                // Wie der echte Dienst antwortet -- mit Kategorie und Status.
                // Beide gehen in die Wiedererkennung ein (die Idee), damit
                // ein von Hand angelegter Eintrag nicht faelschlich als der
                // eigene durchgeht.
                ['whid' => 2169897, 'uid' => '90344', 'jobdate' => '2026-08-04',
                    'category' => '7813', 'status' => '2', 'jobtext' => $text],
                'httpstatuscode' => 200,
            ]),
            '*' => Http::response(['httpstatuscode' => 200]),
        ]);

        app(TransferWorkHours::class)->handle($this->connection());
        app(TransferWorkHours::class)->handle($this->connection());

        $anfragen = 0;
        Http::assertSent(function (Request $r) use (&$anfragen): bool {
            if (str_ends_with($r->url(), 'workhours/add')) {
                $anfragen++;
            }

            return true;
        });

        $this->assertSame(1, $anfragen, 'Nach dem Wiederfinden darf nicht erneut gesendet werden.');
        $this->assertSame(1, WorkHourTransfer::count());
    }

    /**
     * Ein fremder Eintrag wird NICHT für den eigenen gehalten.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * die Idee: „check doch einfach die kategorie noch mit." Hier steht
     * derselbe Mensch, derselbe Tag, derselbe Wortlaut — aber eine andere
     * Kategorie. Das ist ein von Hand angelegter Eintrag und nicht der eigene;
     * er darf die Wiederholung nicht unterdrücken.
     * ─────────────────────────────────────────────────────────────────────────
     */
    #[Test]
    public function a_hand_written_entry_in_another_category_is_not_mistaken_for_ours(): void
    {
        $this->arrange();

        $text = 'D-KEWW | '.$this->workOrderNumber.' | Ölwechsel';

        Http::fake([
            '*auth/accesstoken' => Http::response(['accesstoken' => str_repeat('a', 64), 'httpstatuscode' => 200]),
            '*auth/signin' => Http::response(['httpstatuscode' => 200]),
            '*workhours/add' => Http::response(['error' => 'gateway timeout'], 504),
            '*workhours/list/daterange' => Http::response([
                // Alles gleich -- ausser der Kategorie. Also jemand, der es von
                // Hand eingetragen hat.
                ['whid' => 999999, 'uid' => '90344', 'jobdate' => '2026-08-04',
                    'category' => '7265', 'status' => '2', 'jobtext' => $text],
                'httpstatuscode' => 200,
            ]),
            '*' => Http::response(['httpstatuscode' => 200]),
        ]);

        $ergebnis = app(TransferWorkHours::class)->handle($this->connection());

        $this->assertSame(1, $ergebnis['failed'], 'Der eigene Eintrag fehlt weiterhin.');

        $beleg = WorkHourTransfer::sole();

        $this->assertFalse($beleg->succeeded());
        $this->assertNotSame('999999', $beleg->whid, 'Die fremde Nummer darf nicht übernommen werden.');
        $this->assertTrue($beleg->mayRetry());
    }

    /**
     * Fehlt er wirklich, wird wiederholt.
     */
    #[Test]
    public function a_genuinely_missing_entry_is_retried(): void
    {
        $this->arrange();

        Http::fake([
            '*auth/accesstoken' => Http::response(['accesstoken' => str_repeat('a', 64), 'httpstatuscode' => 200]),
            '*auth/signin' => Http::response(['httpstatuscode' => 200]),
            '*workhours/add' => Http::response(['error' => 'gateway timeout'], 504),
            // Die Liste ist leer -- der Eintrag kam wirklich nicht an.
            '*workhours/list/daterange' => Http::response(['httpstatuscode' => 200]),
            '*' => Http::response(['httpstatuscode' => 200]),
        ]);

        app(TransferWorkHours::class)->handle($this->connection());
        app(TransferWorkHours::class)->handle($this->connection());

        $anfragen = 0;
        Http::assertSent(function (Request $r) use (&$anfragen): bool {
            if (str_ends_with($r->url(), 'workhours/add')) {
                $anfragen++;
            }

            return true;
        });

        $this->assertSame(2, $anfragen);
        $this->assertSame(2, WorkHourTransfer::sole()->attempts);
    }

    /** Ohne VF-Kennung gibt es niemanden, dem die Stunde gehört. */
    #[Test]
    public function somebody_without_a_vereinsflieger_identity_is_skipped(): void
    {
        $this->arrange(withIdentity: false);
        $this->fakeAdd();

        $ergebnis = app(TransferWorkHours::class)->handle($this->connection());

        $this->assertSame(0, $ergebnis['sent']);
        $this->assertSame(1, $ergebnis['skipped']);
        $this->assertSame(0, WorkHourTransfer::count());
    }

    /** Ab Werk aus: Es schreibt in ein fremdes, produktives System. */
    #[Test]
    public function nothing_happens_while_the_setting_is_off(): void
    {
        config()->set('aeronance.vereinsflieger.workhours.enabled', false);

        $this->arrange();
        Http::fake();

        app(TransferWorkHours::class)->handle($this->connection());

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

    private function arrange(bool $withIdentity = true): void
    {
        $this->connection();

        $muster = AircraftType::create(['designation' => 'ASK 21', 'manufacturer' => 'Schleicher']);

        $flugzeug = Aircraft::create([
            'registration' => 'D-KEWW',
            'model' => 'ASK 21',
            'aircraft_type_id' => $muster->id,
        ]);

        $mensch = User::factory()->create(['name' => 'Erika Mustermann']);

        /*
         * Ueber die Aktion und nicht ueber die Models: Die Vorgangsnummer wird
         * dort erzeugt, und ein Test, der sie selbst setzt, prueft eine Welt,
         * die es nicht gibt.
         */
        $verwaltung = app(ManageWorkOrder::class);

        $vorgang = $verwaltung->open($flugzeug, 'Jahresnachprüfung', $mensch, openedAt: '2026-08-01');
        $karte = $verwaltung->addCard($vorgang, 'Ölwechsel');

        if ($withIdentity) {
            ExternalIdentity::create([
                'provider' => 'vereinsflieger',
                'subject' => '90344',
                'user_id' => $mensch->id,
            ]);
        }

        $verwaltung->recordTime($karte, $mensch, 60, ParticipationKind::Executed, '2026-08-04');

        $this->workOrderNumber = (string) $vorgang->number;
    }

    private string $workOrderNumber = '';

    private function fakeAdd(): void
    {
        Http::fake([
            '*auth/accesstoken' => Http::response(['accesstoken' => str_repeat('a', 64), 'httpstatuscode' => 200]),
            '*auth/signin' => Http::response(['httpstatuscode' => 200]),
            '*workhours/add' => Http::response([
                'whid' => 2169897,
                'status' => '2',
                'statusinfo' => 'Akzeptiert',
                'httpstatuscode' => 200,
            ]),
            '*' => Http::response(['httpstatuscode' => 200]),
        ]);
    }
}
