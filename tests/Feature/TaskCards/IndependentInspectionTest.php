<?php

declare(strict_types=1);

namespace Tests\Feature\TaskCards;

use App\Core\Access\AccessSetup;
use App\Core\Models\Qualification;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Actions\CertifyTaskCard;
use App\Modules\TaskCards\Actions\InspectCriticalTask;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Enums\ParticipationKind;
use App\Modules\TaskCards\Enums\TaskCardState;
use App\Modules\TaskCards\Models\TaskCard;
use App\Modules\TaskCards\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Die unabhängige Kontrolle kritischer Arbeiten.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER TEST, UM DEN ES GEHT: `the_person_who_did_the_work_cannot_inspect_it`.
 *
 * Im Segelflug ist der falsch angeschlossene Steuerungsanschluss beim Aufrüsten
 * der klassische tödliche Fehler. Wer ihn angeschlossen hat, sieht ihn beim
 * Nachsehen nicht — er bringt dieselbe Erwartung mit. Genau deshalb muss es
 * jemand anderes sein, und genau das hält dieser Test fest.
 *
 * Der zweite mit Zähnen ist `a_critical_card_cannot_be_certified_uninspected`:
 * Ohne ihn wäre „kritisch" eine Notiz, die man überliest.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class IndependentInspectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();
        app(ModuleManager::class)->enable('fleet');
        app(ModuleManager::class)->enable('taskcards');
        app(ModuleManager::class)->forgetCache();
    }

    /**
     * DER TEST, UM DEN ES GEHT.
     */
    #[Test]
    public function the_person_who_did_the_work_cannot_inspect_it(): void
    {
        $mechaniker = $this->userWith(Permissions::CARDS_WORK, Permissions::CARDS_INSPECT);
        $karte = $this->criticalCard();

        $this->completeBy($karte, $mechaniker);

        $this->expectException(RuntimeException::class);

        app(InspectCriticalTask::class)->handle($karte->fresh(), $mechaniker, 'Anlenkung gezogen.');
    }

    /**
     * Auch wer nur Stunden gebucht hat, ist raus.
     *
     * Die weiche Lesart — „er hat ja nur zehn Minuten daran gemacht" — ist genau
     * die Konstruktion, gegen die es die Regel gibt.
     */
    #[Test]
    public function even_ten_minutes_of_recorded_time_disqualifies(): void
    {
        $mechaniker = $this->userWith(Permissions::CARDS_WORK);
        $helfer = $this->userWith(Permissions::CARDS_WORK, Permissions::CARDS_INSPECT);

        $karte = $this->criticalCard();
        $this->completeBy($karte, $mechaniker);

        // Der Helfer hat zehn Minuten mitgemacht.
        app(ManageWorkOrder::class)->recordTime(
            $karte->fresh(), $helfer, 10, ParticipationKind::Assisted,
        );

        $this->assertFalse(
            app(InspectCriticalTask::class)->mayInspect($karte->fresh(), $helfer),
            'Wer Stunden gebucht hat, hat das Werkstück in der Hand gehabt.',
        );
    }

    #[Test]
    public function a_second_person_may_inspect_and_it_is_recorded(): void
    {
        $mechaniker = $this->userWith(Permissions::CARDS_WORK);
        $pruefer = $this->userWith(Permissions::CARDS_INSPECT);

        $karte = $this->criticalCard();
        $this->completeBy($karte, $mechaniker);

        $ergebnis = app(InspectCriticalTask::class)->handle(
            $karte->fresh(),
            $pruefer,
            'Querruderanschluss beidseitig gezogen, Sicherung sichtbar.',
        );

        $this->assertTrue($ergebnis->wasIndependentlyInspected());
        $this->assertSame($pruefer->id, $ergebnis->inspected_by);
        $this->assertSame($pruefer->name, $ergebnis->inspected_by_name);
        $this->assertStringContainsString('Sicherung sichtbar', $ergebnis->inspection_note);
    }

    /**
     * DER ZWEITE MIT ZÄHNEN: ohne Kontrolle keine Freigabe.
     */
    #[Test]
    public function a_critical_card_cannot_be_certified_uninspected(): void
    {
        $mechaniker = $this->userWith(Permissions::CARDS_WORK);
        $karte = $this->criticalCard();
        $this->completeBy($karte, $mechaniker);

        $freigeber = $this->certifier();

        try {
            app(CertifyTaskCard::class)->certify($karte->fresh(), $freigeber);
            $this->fail('Eine kritische Karte ohne Kontrolle darf nicht freigegeben werden.');
        } catch (RuntimeException) {
            // So gewollt.
        }

        $this->assertSame(TaskCardState::Completed, $karte->fresh()->state);
    }

    /**
     * Und mit Kontrolle geht sie durch.
     */
    #[Test]
    public function after_the_inspection_certification_works(): void
    {
        $mechaniker = $this->userWith(Permissions::CARDS_WORK);
        $pruefer = $this->userWith(Permissions::CARDS_INSPECT);
        $karte = $this->criticalCard();
        $this->completeBy($karte, $mechaniker);

        app(InspectCriticalTask::class)->handle($karte->fresh(), $pruefer, 'Anschluss geprüft.');

        app(CertifyTaskCard::class)->certify($karte->fresh(), $this->certifier());

        $this->assertSame(TaskCardState::Certified, $karte->fresh()->state);
    }

    /**
     * Eine gewöhnliche Karte bleibt unberührt.
     *
     * Sonst hätte das Modul jede Werkstatt verlangsamt, um einen Fall
     * abzudecken, der selten ist.
     */
    #[Test]
    public function an_ordinary_card_needs_no_inspection(): void
    {
        $mechaniker = $this->userWith(Permissions::CARDS_WORK);
        $karte = $this->card(critical: false);
        $this->completeBy($karte, $mechaniker);

        app(CertifyTaskCard::class)->certify($karte->fresh(), $this->certifier());

        $this->assertSame(TaskCardState::Certified, $karte->fresh()->state);
    }

    #[Test]
    public function an_inspection_without_a_word_is_refused(): void
    {
        $mechaniker = $this->userWith(Permissions::CARDS_WORK);
        $pruefer = $this->userWith(Permissions::CARDS_INSPECT);
        $karte = $this->criticalCard();
        $this->completeBy($karte, $mechaniker);

        $this->expectException(InvalidArgumentException::class);

        app(InspectCriticalTask::class)->handle($karte->fresh(), $pruefer, '   ');
    }

    /**
     * Vor der Fertigmeldung gibt es nichts zu kontrollieren.
     */
    #[Test]
    public function an_open_card_cannot_be_inspected(): void
    {
        $pruefer = $this->userWith(Permissions::CARDS_INSPECT);

        $this->expectException(RuntimeException::class);

        app(InspectCriticalTask::class)->handle($this->criticalCard(), $pruefer, 'Angesehen.');
    }

    /**
     * Zweimal kontrollieren geht nicht — die zweite wäre von der ersten nicht
     * zu unterscheiden.
     */
    #[Test]
    public function a_card_is_inspected_once(): void
    {
        $mechaniker = $this->userWith(Permissions::CARDS_WORK);
        $karte = $this->criticalCard();
        $this->completeBy($karte, $mechaniker);

        app(InspectCriticalTask::class)->handle($karte->fresh(), $this->userWith(Permissions::CARDS_INSPECT), 'Erste.');

        $this->expectException(RuntimeException::class);

        app(InspectCriticalTask::class)->handle($karte->fresh(), $this->userWith(Permissions::CARDS_INSPECT), 'Zweite.');
    }

    /**
     * Ohne Lizenz kontrollieren ist ausdrücklich erlaubt.
     *
     * Verlangte die Kontrolle eine Lizenz, fiele sie in kleinen Vereinen aus —
     * dort ist der einzige Lizenzinhaber meist derjenige, der gearbeitet hat.
     */
    #[Test]
    public function an_inspector_needs_no_licence(): void
    {
        $mechaniker = $this->userWith(Permissions::CARDS_WORK);
        $karte = $this->criticalCard();
        $this->completeBy($karte, $mechaniker);

        $ohneLizenz = $this->userWith(Permissions::CARDS_INSPECT);

        $ergebnis = app(InspectCriticalTask::class)->handle($karte->fresh(), $ohneLizenz, 'Geprüft.');

        $this->assertTrue($ergebnis->wasIndependentlyInspected());
        $this->assertNull($ergebnis->inspection_qualification_reference);
    }

    private function criticalCard(): TaskCard
    {
        return $this->card(critical: true);
    }

    private function card(bool $critical): TaskCard
    {
        $flugzeug = Aircraft::create([
            'registration' => 'D-K'.strtoupper(substr(uniqid(), -3)),
            'model' => 'ASK 21',
            'is_active' => true,
        ]);

        $anleger = $this->userWith(Permissions::WORK_ORDERS_MANAGE);
        $vorgang = app(ManageWorkOrder::class)->open($flugzeug, 'Jahresnachprüfung', $anleger);

        return app(ManageWorkOrder::class)->addCard(
            order: $vorgang,
            title: 'Querruder anschließen',
            critical: $critical,
            criticalReason: $critical ? 'Querruderanschluss' : null,
        );
    }

    private function completeBy(TaskCard $card, User $user): void
    {
        app(ManageWorkOrder::class)->recordTime(
            $card->fresh(), $user, 60, ParticipationKind::Executed,
        );

        app(CertifyTaskCard::class)->complete($card->fresh(), $user, 'Angeschlossen und gesichert.');
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function certifier(): User
    {
        $user = $this->userWith(Permissions::CARDS_CERTIFY);

        Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.00000',
            'category' => 'B1',
            'valid_from' => now()->subYear()->toDateString(),
        ]);

        return $user->fresh();
    }
}
