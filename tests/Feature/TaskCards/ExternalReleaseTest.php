<?php

declare(strict_types=1);

namespace Tests\Feature\TaskCards;

use App\Core\Access\AccessSetup;
use App\Core\Models\Qualification;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Actions\CertifyTaskCard;
use App\Modules\TaskCards\Actions\IssueRelease;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Enums\FindingState;
use App\Modules\TaskCards\Enums\ParticipationKind;
use App\Modules\TaskCards\Enums\TaskCardState;
use App\Modules\TaskCards\Models\Finding;
use App\Modules\TaskCards\Models\ReleaseToService;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Die Freigabe durch jemanden, der nicht im Verein ist.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Feldtest: "wir brauchen noch die möglichkeit eines ‚Freigegeben durch',
 * falls der prüfer nicht im verein ist." Im kleinen Verein der Regelfall --
 * ein freiberuflicher Part-66-Prüfer oder ein LTB zeichnet ab, ohne je ein
 * Konto hier zu haben.
 *
 * Was diese Tests festhalten, ist vor allem die EHRLICHKEIT der Bescheinigung:
 * Sie trägt den Namen des Prüfers, daneben den des Erfassenden, und sie sagt,
 * dass die Nummer abgeschrieben und nicht geprüft wurde.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ExternalReleaseTest extends TestCase
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
    public function an_outside_inspector_signs_and_the_recorder_is_named_too(): void
    {
        $order = $this->readyOrder();
        $erfasser = $this->recorder();

        $release = app(IssueRelease::class)->recordExternal(
            order: $order->fresh(),
            recordedBy: $erfasser,
            signatoryName: 'Hans Meier',
            licenceReference: 'DE.66.98765',
            organisation: 'LTB Muster GmbH',
        );

        // Die Bescheinigung gehört dem Prüfer ...
        $this->assertSame('Hans Meier', $release->released_by_name);
        $this->assertNull($release->released_by, 'Kein Konto darf als Unterzeichner erscheinen.');
        $this->assertTrue($release->is_external);
        $this->assertSame('DE.66.98765', $release->qualification_reference);
        $this->assertSame(ReleaseToService::CREDENTIAL_EXTERNAL, $release->qualification_type);
        $this->assertSame('LTB Muster GmbH', $release->external_organisation);

        // ... und daneben steht, wer sie eingetragen hat.
        $this->assertSame($erfasser->id, $release->recorded_by);
        $this->assertSame($erfasser->name, $release->recorded_by_name);

        // Wie jede Freigabe beendet sie den Vorgang.
        $this->assertSame(WorkOrder::STATE_CLOSED, $order->fresh()->state);
    }

    #[Test]
    public function it_needs_its_own_permission(): void
    {
        // Wer selbst freigeben darf, darf nicht automatisch behaupten, ein
        // anderer habe freigegeben -- das sind zwei Handlungen.
        $order = $this->readyOrder();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/record_external/');

        app(IssueRelease::class)->recordExternal(
            order: $order->fresh(),
            recordedBy: $this->userWith(Permissions::CARDS_CERTIFY),
            signatoryName: 'Hans Meier',
            licenceReference: 'DE.66.98765',
        );
    }

    #[Test]
    public function without_a_name_and_a_number_it_says_nothing(): void
    {
        $order = $this->readyOrder();

        $this->expectException(InvalidArgumentException::class);

        app(IssueRelease::class)->recordExternal(
            order: $order->fresh(),
            recordedBy: $this->recorder(),
            signatoryName: 'Hans Meier',
            licenceReference: '   ',
        );
    }

    #[Test]
    public function the_same_guards_apply_as_to_our_own_release(): void
    {
        /*
         * Die Sache ist dieselbe, nur der Unterschreibende steht außerhalb:
         * Eine Karte, die niemand abgezeichnet hat, verhindert auch die
         * externe Freigabe.
         */
        $aircraft = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);
        $mechaniker = $this->userWith(Permissions::CARDS_WORK, Permissions::WORK_ORDERS_MANAGE);

        $order = app(ManageWorkOrder::class)->open(
            aircraft: $aircraft, title: 'Jahresnachprüfung', user: $mechaniker,
        );
        app(ManageWorkOrder::class)->addCard($order, 'Ölwechsel');

        $this->expectException(RuntimeException::class);

        app(IssueRelease::class)->recordExternal(
            order: $order->fresh(),
            recordedBy: $this->recorder(),
            signatoryName: 'Hans Meier',
            licenceReference: 'DE.66.98765',
        );
    }

    #[Test]
    public function the_certificate_names_both_people(): void
    {
        $order = $this->readyOrder();
        $release = app(IssueRelease::class)->recordExternal(
            order: $order->fresh(),
            recordedBy: $this->recorder(),
            signatoryName: 'Hans Meier',
            licenceReference: 'DE.66.98765',
        );

        $leser = $this->userWith(Permissions::WORK_ORDERS_VIEW);

        $this->actingAs($leser)
            ->get(route('taskcards.release', $release))
            ->assertSuccessful()
            ->assertSee('Hans Meier')
            ->assertSee('DE.66.98765')
            // Der Erfassende und der Hinweis, dass abgeschrieben wurde.
            ->assertSee($release->recorded_by_name)
            ->assertSee(__('taskcards.release.external.print_note'), escape: false);
    }

    /**
     * ─────────────────────────────────────────────────────────────────────────
     * "wie gesagt, die gesamtfreigabe zeichnet die karten mit ab. Das gilt
     * natürlich auch für den prüfer."
     *
     * Der Kern dieses Tests ist nicht, DASS die Karte abgezeichnet wird,
     * sondern MIT WESSEN Unterschrift: der des Prüfers, nicht der des
     * Erfassers. Wer abschreibt, hat die Arbeit nicht beurteilt.
     * ─────────────────────────────────────────────────────────────────────────
     */
    #[Test]
    public function the_outside_signature_carries_down_to_the_open_cards(): void
    {
        $order = $this->orderWithCardOnlyReportedDone();
        $karte = $order->taskCards()->first();

        $this->assertSame(TaskCardState::Completed, $karte->state);

        app(IssueRelease::class)->recordExternal(
            order: $order->fresh(),
            recordedBy: $this->recorder(),
            signatoryName: 'Hans Meier',
            licenceReference: 'DE.66.98765',
        );

        $karte->refresh();

        $this->assertSame(TaskCardState::Certified, $karte->state);
        $this->assertSame('Hans Meier', $karte->certified_by_name);
        $this->assertSame('DE.66.98765', $karte->qualification_reference);
        $this->assertSame(ReleaseToService::CREDENTIAL_EXTERNAL, $karte->qualification_type);

        // KEIN Konto als Unterzeichner: Der Prüfer hat hier keins, und das
        // des Erfassers einzutragen wäre eine Behauptung über ihn.
        $this->assertNull($karte->certified_by);
    }

    /**
     * Mehrere Karten, und der Befund, der an einer davon hängt.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Der Befund ist hier der eigentliche Prüfstein, und zwar wegen einer
     * Schleife, die sich sonst schliesst: Sein Zustand „eingeplant" sperrt die
     * Freigabe, aufgelöst wird er aber erst durch das Abzeichnen der Karte --
     * das ohne Freigabe nie kommt. Für den fremden Prüfer gäbe es keinen
     * Umweg: Ein Verein, der ihn holt, hat niemanden, der den Befund
     * stattdessen beurteilen dürfte.
     * ─────────────────────────────────────────────────────────────────────────
     */
    #[Test]
    public function every_open_card_is_signed_and_its_finding_closed_in_the_signatory_name(): void
    {
        $order = $this->orderWithCardOnlyReportedDone();
        $mechaniker = $this->userWith(Permissions::CARDS_WORK, Permissions::WORK_ORDERS_MANAGE);

        $zweite = app(ManageWorkOrder::class)->addCard($order->fresh(), 'Bowdenzug prüfen');
        app(CertifyTaskCard::class)->complete($zweite->fresh(), $mechaniker, 'Gemacht', minutes: 30);

        // Ein blockierender Befund, den genau diese Karte beheben soll.
        $befund = Finding::create([
            'aircraft_id' => $order->aircraft_id,
            'number' => 'B-2026-001',
            'title' => 'Spiel im Bowdenzug',
            'description' => 'Spürbares Spiel an der Höhenruderanlenkung.',
            'state' => FindingState::Scheduled,
            'is_blocking' => true,
            'found_on' => now()->toDateString(),
            'resolving_task_card_id' => $zweite->id,
        ]);

        app(IssueRelease::class)->recordExternal(
            order: $order->fresh(),
            recordedBy: $this->recorder(),
            signatoryName: 'Hans Meier',
            licenceReference: 'DE.66.98765',
        );

        foreach ($order->fresh()->taskCards as $karte) {
            $this->assertSame(TaskCardState::Certified, $karte->state, $karte->title);
            $this->assertSame('Hans Meier', $karte->certified_by_name);
        }

        $befund->refresh();

        $this->assertSame(FindingState::Resolved, $befund->state);
        $this->assertStringContainsString('Hans Meier', (string) $befund->resolution);
    }

    #[Test]
    public function a_critical_card_without_the_second_pair_of_eyes_stops_the_outside_release_too(): void
    {
        $order = $this->orderWithCardOnlyReportedDone(critical: true);

        $this->expectException(RuntimeException::class);

        try {
            app(IssueRelease::class)->recordExternal(
                order: $order->fresh(),
                recordedBy: $this->recorder(),
                signatoryName: 'Hans Meier',
                licenceReference: 'DE.66.98765',
            );
        } finally {
            // Die Bescheinigung darf auch nicht halb entstanden sein.
            $this->assertNull($order->fresh()->released_at);
            $this->assertSame(0, ReleaseToService::query()->count());
        }
    }

    /** Ein Vorgang, dessen Karte fertiggemeldet, aber nicht abgezeichnet ist. */
    private function orderWithCardOnlyReportedDone(bool $critical = false): WorkOrder
    {
        $aircraft = Aircraft::create(['registration' => 'D-KXYZ', 'model' => 'ASK 21']);
        $mechaniker = $this->userWith(Permissions::CARDS_WORK, Permissions::WORK_ORDERS_MANAGE);

        $order = app(ManageWorkOrder::class)->open(
            aircraft: $aircraft, title: 'Jahresnachprüfung', user: $mechaniker,
        );
        $card = app(ManageWorkOrder::class)->addCard(
            order: $order,
            title: 'Ölwechsel',
            critical: $critical,
            criticalReason: $critical ? 'Steuerungsarbeit' : null,
        );

        app(CertifyTaskCard::class)->complete($card->fresh(), $mechaniker, 'Gemacht', minutes: 60);

        return $order->fresh();
    }

    private function readyOrder(): WorkOrder
    {
        $aircraft = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);
        $mechaniker = $this->userWith(Permissions::CARDS_WORK, Permissions::WORK_ORDERS_MANAGE);
        $pruefer = $this->qualifiedInspector();

        $order = app(ManageWorkOrder::class)->open(
            aircraft: $aircraft, title: 'Jahresnachprüfung', user: $mechaniker,
        );
        $card = app(ManageWorkOrder::class)->addCard($order, 'Ölwechsel');

        app(ManageWorkOrder::class)->recordTime($card, $mechaniker, 60, ParticipationKind::Executed);
        app(CertifyTaskCard::class)->complete($card->fresh(), $mechaniker, 'Gemacht');
        app(CertifyTaskCard::class)->certify($card->fresh(), $pruefer);

        return $order->fresh();
    }

    /**
     * Der Vereinsprüfer, der die KARTEN einzeln abzeichnet.
     *
     * Die eigene Unterschrift unter eine Karte verlangt weiterhin eine
     * hinterlegte Qualifikation -- geprüft, nicht abgeschrieben. Was von außen
     * kommt, geht den Weg in certifyExternally() und sagt das auch.
     */
    private function qualifiedInspector(): User
    {
        $user = $this->userWith(Permissions::CARDS_WORK, Permissions::CARDS_CERTIFY);

        Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.12345',
            'valid_from' => now()->subYear()->toDateString(),
            'valid_until' => now()->addYear()->toDateString(),
        ]);

        return $user->fresh();
    }

    private function recorder(): User
    {
        return $this->userWith(
            Permissions::RELEASES_RECORD_EXTERNAL,
            Permissions::WORK_ORDERS_VIEW,
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
}
