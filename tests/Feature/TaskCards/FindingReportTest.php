<?php

declare(strict_types=1);

namespace Tests\Feature\TaskCards;

use App\Core\Access\AccessSetup;
use App\Core\Access\CoreRoles;
use App\Core\Models\Qualification;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Actions\RecordFinding;
use App\Modules\TaskCards\Enums\FindingState;
use App\Modules\TaskCards\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Der Befundbericht -- und was aus seinen Punkten wird.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Feldtest: "Ein Befundbericht sollte durch jeden P/O oder höher angelegt
 * werden können. Aus einzelnen oder mehreren Punkten soll dann eine
 * Arbeitskarte erstellt werden können."
 *
 * Beide Halbsätze stehen hier: das Melden hinter dem eigenen, grob
 * verteilbaren Recht -- abgezeichnet mit der Nummer, die zu Freigaben
 * berechtigt (Part-66 zuerst, sonst die P/O-Berechtigung für genau dieses
 * Luftfahrzeug) -- und mehrere Punkte, die zusammen auf EINER Karte landen.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class FindingReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(ModuleManager::class)->enable('fleet');
        app(ModuleManager::class)->enable('taskcards');
        app(ModuleManager::class)->forgetCache();
        app(AccessSetup::class)->run();
    }

    #[Test]
    public function a_pilot_owner_reports_two_points_as_two_findings(): void
    {
        $aircraft = $this->aircraft();
        $melder = $this->reporter($aircraft);

        $riss = app(RecordFinding::class)->report(
            aircraft: $aircraft,
            title: 'Riss in der Haubenverglasung',
            description: 'Vorne links, ca. 3 cm, vom Rahmen ausgehend.',
            user: $melder,
        );

        $reifen = app(RecordFinding::class)->report(
            aircraft: $aircraft,
            title: 'Reifen abgefahren',
            description: 'Hauptrad, Profil an der Verschleißgrenze.',
            user: $melder,
        );

        // Jeder Punkt ist ein eigener Befund mit eigener Nummer -- genau die
        // Einheit, die sich später einzeln oder gebündelt einplanen lässt.
        $this->assertNotSame($riss->number, $reifen->number);
        $this->assertSame(FindingState::Open, $riss->state);
        $this->assertSame($melder->name, $riss->found_by_name);

        // Die Abzeichnung, eingefroren als Kopie (E7): hier die
        // P/O-Berechtigung, weil keine Part-66-Lizenz vorliegt.
        $this->assertSame(Qualification::TYPE_PILOT_OWNER, $riss->reported_qualification_type);
        $this->assertSame('SPL-DE-4711', $riss->reported_qualification_reference);
    }

    #[Test]
    public function a_report_is_always_blocking(): void
    {
        // "Harmlos" ist eine Feststellung (E8) und nicht Teil der Meldung --
        // herabstufen können die, die dafür einstehen (zurückstellen/verwerfen).
        $aircraft = $this->aircraft();

        $finding = app(RecordFinding::class)->report(
            aircraft: $aircraft,
            title: 'Lackabplatzer',
            description: 'Rumpfunterseite, vermutlich kosmetisch.',
            user: $this->reporter($aircraft),
        );

        $this->assertTrue($finding->is_blocking);
    }

    #[Test]
    public function without_the_permission_the_report_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/findings\.report/');

        app(RecordFinding::class)->report(
            aircraft: $this->aircraft(),
            title: 'Riss',
            description: 'Irgendwo.',
            user: $this->userWith(),
        );
    }

    #[Test]
    public function the_workshop_permission_reports_as_well(): void
    {
        // Wer über Befunde entscheiden darf, darf sie erst recht erwähnen --
        // abgezeichnet wird trotzdem: hier mit der Part-66-Lizenz.
        $werkstatt = $this->userWith(Permissions::FINDINGS_RECORD);
        $this->part66($werkstatt);

        $finding = app(RecordFinding::class)->report(
            aircraft: $this->aircraft(),
            title: 'Spiel im Seitenruder',
            description: 'Fühlbar am Boden, Anlenkung prüfen.',
            user: $werkstatt->fresh(),
        );

        $this->assertSame(FindingState::Open, $finding->state);
        $this->assertSame(Qualification::TYPE_PART66, $finding->reported_qualification_type);
        $this->assertSame('DE.66.98765', $finding->reported_qualification_reference);
    }

    #[Test]
    public function several_points_raise_one_card(): void
    {
        $aircraft = $this->aircraft();
        $melder = $this->reporter($aircraft);
        $werkstatt = $this->userWith(Permissions::WORK_ORDERS_MANAGE);

        $a = app(RecordFinding::class)->report(
            aircraft: $aircraft, title: 'Riss in der Haube',
            description: 'Vorne links, 3 cm.', user: $melder,
        );
        $b = app(RecordFinding::class)->report(
            aircraft: $aircraft, title: 'Reifen abgefahren',
            description: 'Hauptrad, Verschleißgrenze.', user: $melder,
        );

        $order = app(ManageWorkOrder::class)->open(
            aircraft: $aircraft, title: 'Nachprüfung Herbst', user: $werkstatt,
        );

        $card = app(RecordFinding::class)->scheduleMany(
            findings: [$a, $b], order: $order, user: $werkstatt,
        );

        // EINE Karte, beide Punkte darauf ausgeschrieben -- die Karte muss an
        // der Werkbank für sich stehen.
        $this->assertSame(1, $order->fresh()->taskCards()->count());
        $this->assertStringContainsString($a->number, (string) $card->instruction);
        $this->assertStringContainsString('Reifen abgefahren', (string) $card->instruction);

        $this->assertSame(FindingState::Scheduled, $a->fresh()->state);
        $this->assertSame(FindingState::Scheduled, $b->fresh()->state);
        $this->assertSame($card->id, (int) $b->fresh()->resolving_task_card_id);
    }

    #[Test]
    public function a_single_point_keeps_its_own_title(): void
    {
        $aircraft = $this->aircraft();
        $werkstatt = $this->userWith(Permissions::WORK_ORDERS_MANAGE);

        $finding = app(RecordFinding::class)->report(
            aircraft: $aircraft, title: 'Riss in der Haube',
            description: 'Vorne links.', user: $this->reporter($aircraft),
        );

        $order = app(ManageWorkOrder::class)->open(
            aircraft: $aircraft, title: 'Reparatur', user: $werkstatt,
        );

        $card = app(RecordFinding::class)->scheduleMany(
            findings: [$finding], order: $order, user: $werkstatt,
        );

        $this->assertSame('Riss in der Haube', $card->title);
    }

    #[Test]
    public function a_point_of_another_aircraft_is_refused(): void
    {
        $werkstatt = $this->userWith(Permissions::WORK_ORDERS_MANAGE);

        $eigenes = app(RecordFinding::class)->report(
            aircraft: $this->aircraft(), title: 'Riss', description: 'Haube.',
            user: $this->reporter($this->aircraft()),
        );
        $fremdes = app(RecordFinding::class)->report(
            aircraft: $this->otherAircraft(), title: 'Delle', description: 'Fläche.',
            user: $this->reporter($this->otherAircraft()),
        );

        $order = app(ManageWorkOrder::class)->open(
            aircraft: $this->aircraft(), title: 'Reparatur', user: $werkstatt,
        );

        try {
            app(RecordFinding::class)->scheduleMany(
                findings: [$eigenes, $fremdes], order: $order, user: $werkstatt,
            );
            $this->fail('Eine gemischte Auswahl muss abgewiesen werden.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('different aircraft', $e->getMessage());
        }

        // Und zwar GANZ: auch der passende Punkt bleibt unangetastet, und es
        // entsteht keine halbe Karte.
        $this->assertSame(FindingState::Open, $eigenes->fresh()->state);
        $this->assertSame(0, $order->fresh()->taskCards()->count());
    }

    #[Test]
    public function the_new_permission_exists_and_the_admin_holds_it(): void
    {
        // Die Migration liefert AccessSetup nach -- was hier nach run() gilt,
        // gilt nach dem Update auch auf einer bestehenden Installation.
        $admin = Role::query()->where('name', CoreRoles::ADMIN)->firstOrFail();

        $this->assertTrue($admin->hasPermissionTo(Permissions::FINDINGS_REPORT));
    }

    #[Test]
    public function the_part66_licence_signs_before_the_pilot_owner(): void
    {
        // "wenn vorhanden die part66" -- wer beides hält, zeichnet mit der
        // breiteren Berechtigung ab (dieselbe Reihenfolge wie bei Freigaben).
        $aircraft = $this->aircraft();
        $beides = $this->reporter($aircraft);
        $this->part66($beides);

        $finding = app(RecordFinding::class)->report(
            aircraft: $aircraft, title: 'Riss', description: 'Haube, vorne links.',
            user: $beides->fresh(),
        );

        $this->assertSame(Qualification::TYPE_PART66, $finding->reported_qualification_type);
        $this->assertSame('DE.66.98765', $finding->reported_qualification_reference);
    }

    #[Test]
    public function below_the_po_tier_the_report_is_refused(): void
    {
        // Das Recht allein genügt nicht: "durch jeden P/O oder höher" heißt,
        // unterhalb dieser Stufe gibt es keinen Befundbericht.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Part-66|pilot-owner/i');

        app(RecordFinding::class)->report(
            aircraft: $this->aircraft(),
            title: 'Riss',
            description: 'Haube, vorne links.',
            user: $this->userWith(Permissions::FINDINGS_REPORT),
        );
    }

    #[Test]
    public function the_pilot_owner_signature_is_scoped_to_the_aircraft(): void
    {
        // Die P/O-Berechtigung gilt für das eine Luftfahrzeug, auf das sie
        // lautet -- für ein anderes zeichnet sie nichts ab.
        $this->expectException(RuntimeException::class);

        app(RecordFinding::class)->report(
            aircraft: $this->otherAircraft(),
            title: 'Delle',
            description: 'Fläche, links.',
            user: $this->reporter($this->aircraft()),
        );
    }

    #[Test]
    public function an_already_scheduled_point_is_not_scheduled_twice(): void
    {
        // Eine zweite Karte stähle der ersten die Spur: Deren Abzeichnung
        // löste dann nichts mehr, und ein Mangel lebte auf zwei Karten.
        $aircraft = $this->aircraft();
        $werkstatt = $this->userWith(Permissions::WORK_ORDERS_MANAGE);

        $finding = app(RecordFinding::class)->report(
            aircraft: $aircraft, title: 'Riss', description: 'Haube.',
            user: $this->reporter($aircraft),
        );

        $order = app(ManageWorkOrder::class)->open(
            aircraft: $aircraft, title: 'Reparatur', user: $werkstatt,
        );

        app(RecordFinding::class)->scheduleMany(
            findings: [$finding->fresh()], order: $order, user: $werkstatt,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already scheduled/');

        app(RecordFinding::class)->scheduleMany(
            findings: [$finding->fresh()], order: $order, user: $werkstatt,
        );
    }

    #[Test]
    public function a_long_selection_gets_a_counting_title(): void
    {
        // 15 Nummern sprengen die 160-Zeichen-Spalte -- dann zählt der Titel,
        // statt abgeschnitten zu werden; die Punkte stehen in der Anweisung.
        $aircraft = $this->aircraft();
        $melder = $this->reporter($aircraft);
        $werkstatt = $this->userWith(Permissions::WORK_ORDERS_MANAGE);

        $findings = [];

        for ($i = 0; $i < 15; $i++) {
            $findings[] = app(RecordFinding::class)->report(
                aircraft: $aircraft, title: 'Punkt '.$i,
                description: 'Beschreibung '.$i, user: $melder,
            );
        }

        $order = app(ManageWorkOrder::class)->open(
            aircraft: $aircraft, title: 'Grundüberholung', user: $werkstatt,
        );

        $card = app(RecordFinding::class)->scheduleMany(
            findings: $findings, order: $order, user: $werkstatt,
        );

        $this->assertLessThanOrEqual(160, mb_strlen($card->title));
        $this->assertStringContainsString('15', $card->title);
    }

    private function aircraft(): Aircraft
    {
        return Aircraft::firstOrCreate(
            ['registration' => 'D-KABC'],
            ['model' => 'ASK 21'],
        );
    }

    private function otherAircraft(): Aircraft
    {
        return Aircraft::firstOrCreate(
            ['registration' => 'D-KXYZ'],
            ['model' => 'DG-300'],
        );
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    /**
     * Jemand auf P/O-Stufe: Melderecht plus die Berechtigung für dieses eine
     * Luftfahrzeug, mit der Flugscheinnummer als Referenz.
     */
    private function reporter(Aircraft $aircraft): User
    {
        $user = $this->userWith(Permissions::FINDINGS_REPORT);

        Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PILOT_OWNER,
            'scope' => $aircraft->registration,
            'reference' => 'SPL-DE-4711',
            'valid_from' => now()->subYear()->toDateString(),
            'valid_until' => now()->addYear()->toDateString(),
        ]);

        return $user->fresh();
    }

    private function part66(User $user): void
    {
        Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.98765',
            'valid_from' => now()->subYear()->toDateString(),
            'valid_until' => now()->addYear()->toDateString(),
        ]);
    }
}
