<?php

declare(strict_types=1);

namespace Tests\Feature\TaskCards;

use App\Core\Enums\MaintenanceSubject;
use App\Core\Models\Qualification;
use App\Core\Models\QualificationLimitation;
use App\Models\User;
use App\Modules\Fleet\Enums\AirframeConstruction;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Actions\CertifyTaskCard;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Enums\ParticipationKind;
use App\Modules\TaskCards\Models\TaskCard;
use App\Modules\TaskCards\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * How far a signature reaches -- three steps, not two.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The existing rule answers WHOSE work somebody may sign for, and the brief settled
 * it long ago: "crs darf fremdarbeiten freigeben. PO explizit nur das was er
 * selbst gemacht hat."
 *
 * These tests are about the second question underneath it -- WHAT may they sign
 * for -- which only became visible with two things the requirement described:
 *
 *   "das non-complex ist die eintragung 'no maintance exeding MA.803(b)', also
 *   P/O. Die Leute dürfen damit nicht mehr Sachen freigeben als ein P/O, aber
 *   für Fremdarbeiten."
 *
 *   "Die Zellentypen können eingeschränkt werden und zählen über die gesamte
 *   Lizenz. Wenn ich beantrage bekomme ich z.B. die Einschränkung 'ausgenommen
 *   Zellen in Metallbauweise', da ist egal ob das L1 oder L2 ist."
 *
 * Almost every test here asserts a REFUSAL. That is deliberate: a privilege
 * wrongly granted is invisible until an auditor finds it, while a privilege
 * wrongly withheld gets reported the same afternoon. Only the negative direction
 * needs guarding.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class CertifyingScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([Permissions::CARDS_CERTIFY, Permissions::CARDS_WORK] as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    #[Test]
    public function an_unrestricted_licence_signs_for_other_peoples_work(): void
    {
        // The baseline the rest is measured against. Without it a refusal test
        // proves nothing -- everything would refuse.
        $aircraft = $this->aircraft([AirframeConstruction::Composite]);
        $card = $this->card($aircraft, $this->mechanic());

        $certified = app(CertifyTaskCard::class)->certify($card, $this->licensed());

        $this->assertNotNull($certified->certified_at);
    }

    #[Test]
    public function the_ma803b_cap_still_covers_other_peoples_work(): void
    {
        /*
         * The middle row of the table, and the one easiest to get wrong. The cap
         * limits the SCOPE and leaves the privilege to sign for other people's
         * work untouched -- reading it as a pilot-owner authorisation would take
         * away something the licence grants.
         */
        $aircraft = $this->aircraft([AirframeConstruction::Composite]);
        $card = $this->card($aircraft, $this->mechanic());
        $card->update(['within_pilot_owner_scope' => true]);

        $certified = app(CertifyTaskCard::class)->certify($card, $this->licensed(capped: true));

        $this->assertNotNull($certified->certified_at);
    }

    #[Test]
    public function the_ma803b_cap_refuses_work_beyond_pilot_owner_scope(): void
    {
        $aircraft = $this->aircraft([AirframeConstruction::Composite]);
        $card = $this->card($aircraft, $this->mechanic());
        $card->update(['within_pilot_owner_scope' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/MA\.803\(b\)/');

        app(CertifyTaskCard::class)->certify($card, $this->licensed(capped: true));
    }

    #[Test]
    public function the_cap_refuses_a_card_nobody_has_assessed(): void
    {
        /*
         * Unassessed is not permission. A capped licence has no second rule to
         * fall back on -- unlike a pilot-owner, whose ownership requirement is
         * already doing the work -- so "nobody wrote down whether this is within
         * scope" has to stop the signature rather than wave it through.
         */
        $aircraft = $this->aircraft([AirframeConstruction::Composite]);
        $card = $this->card($aircraft, $this->mechanic());

        $this->assertNull($card->within_pilot_owner_scope);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nicht vermerkt|MA\.803\(b\)/');

        app(CertifyTaskCard::class)->certify($card, $this->licensed(capped: true));
    }

    #[Test]
    public function an_airframe_exclusion_refuses_that_construction(): void
    {
        // "ausgenommen Zellen in Metallbauweise" on a metal aircraft.
        $aircraft = $this->aircraft([AirframeConstruction::Metal]);
        $card = $this->card($aircraft, $this->mechanic());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Einschränkung/');

        app(CertifyTaskCard::class)->certify(
            $card,
            $this->licensed(excluding: MaintenanceSubject::Metal),
        );
    }

    #[Test]
    public function the_same_exclusion_leaves_other_constructions_alone(): void
    {
        // The exclusion has to bite on metal and ONLY on metal -- an exclusion
        // that refuses everything is indistinguishable from a broken licence.
        $aircraft = $this->aircraft([AirframeConstruction::Composite]);
        $card = $this->card($aircraft, $this->mechanic());

        $certified = app(CertifyTaskCard::class)->certify(
            $card,
            $this->licensed(excluding: MaintenanceSubject::Metal),
        );

        $this->assertNotNull($certified->certified_at);
    }

    #[Test]
    public function an_unrecorded_airframe_refuses_rather_than_assumes(): void
    {
        /*
         * An airframe is always made of SOMETHING; an empty field can only mean
         * nobody wrote it down. Reading that as "not metal" would let a
         * restricted licence sign for an aircraft nobody has checked -- so the
         * undecidable case refuses, and the fix is to record the construction.
         */
        $aircraft = $this->aircraft(null);
        $card = $this->card($aircraft, $this->mechanic());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Einschränkung/');

        app(CertifyTaskCard::class)->certify(
            $card,
            $this->licensed(excluding: MaintenanceSubject::Metal),
        );
    }

    #[Test]
    public function an_exclusion_applies_across_the_whole_licence(): void
    {
        /*
         * Vorgabe: "da ist egal ob das L1 oder L2 ist". The limitation hangs on
         * the licence, not on a category, so holding a second category cannot
         * route around it.
         */
        $aircraft = $this->aircraft([AirframeConstruction::Metal]);
        $card = $this->card($aircraft, $this->mechanic());

        $user = $this->licensed(excluding: MaintenanceSubject::Metal, categories: ['L1', 'L2']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Einschränkung/');

        app(CertifyTaskCard::class)->certify($card, $user);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /** @param list<AirframeConstruction>|null $constructions */
    private function aircraft(?array $constructions): Aircraft
    {
        return Aircraft::create([
            'registration' => 'D-KABC',
            'model' => 'ASK 21',
            'is_active' => true,
            'airframe_constructions' => $constructions,
        ]);
    }

    private function card(Aircraft $aircraft, User $worker): TaskCard
    {
        $order = app(ManageWorkOrder::class)->open($aircraft, 'Jahresnachprüfung', $worker);
        $card = app(ManageWorkOrder::class)->addCard($order, 'Ruderanschluss prüfen');

        app(ManageWorkOrder::class)->recordTime(
            $card, $worker, 60, ParticipationKind::Executed, now()->toDateString(),
        );

        // COMPLETE before CERTIFY -- the two signatures are separate acts, and
        // certifying an unfinished card is refused for its own reasons.
        app(CertifyTaskCard::class)->complete($card, $worker, 'Geprüft und in Ordnung');

        return $card->fresh();
    }

    /** @param list<string> $categories */
    private function licensed(
        bool $capped = false,
        ?MaintenanceSubject $excluding = null,
        array $categories = ['B1.2'],
    ): User {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::CARDS_CERTIFY);

        $qualification = Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.12345',
            'category' => $categories[0],
            'categories' => $categories,
            'no_maintenance_exceeding_ma803b' => $capped,
            'valid_from' => now()->subYear()->toDateString(),
        ]);

        if ($excluding !== null) {
            QualificationLimitation::create([
                'qualification_id' => $qualification->id,
                'subject' => $excluding,
            ]);
        }

        return $user->fresh();
    }

    private function mechanic(): User
    {
        return tap(User::factory()->create(['is_active' => true]))
            ->givePermissionTo(Permissions::CARDS_WORK);
    }
}
