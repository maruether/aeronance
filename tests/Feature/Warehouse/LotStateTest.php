<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\Warehouse\Actions\ChangeLotState;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\LotStateChange;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Where three decisions meet.
 *
 * E5/4.6 -- the chain runs one way and unsalvageable is the end of it.
 * E8      -- determinations need a qualification, precautionary blocking does not.
 * E7      -- a determination is frozen into the record, name and credential copied.
 */
final class LotStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([Permissions::STOCK_QUARANTINE, Permissions::STOCK_QUARANTINE_CERTIFY, Permissions::STOCK_QUARANTINE_RELEASE, Permissions::STOCK_SCRAP] as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    #[Test]
    public function anyone_may_set_a_lot_aside_as_a_precaution(): void
    {
        // Missing paperwork is reason enough, and no licence is needed to notice.
        $lot = $this->lot();
        $storeman = $this->userWith(Permissions::STOCK_QUARANTINE);

        $change = app(ChangeLotState::class)->handle(
            $lot, LotState::Quarantined, 'Form 1 liegt nicht vor', $storeman,
        );

        $this->assertSame(LotState::Quarantined, $lot->fresh()->state);
        $this->assertFalse($change->isDetermination());
        $this->assertNull($change->qualification_type);
    }

    #[Test]
    public function setting_aside_issues_a_quarantine_tag(): void
    {
        $lot = $this->lot();

        $change = app(ChangeLotState::class)->handle(
            $lot, LotState::Quarantined, 'Verdacht auf Beschädigung',
            $this->userWith(Permissions::STOCK_QUARANTINE),
        );

        $this->assertMatchesRegularExpression('/^\d{6}-\d{3}$/', $change->quarantine_tag);
        $this->assertStringStartsWith(now()->format('Ym'), $change->quarantine_tag);
    }

    #[Test]
    public function tag_numbers_run_consecutively_and_are_never_reused(): void
    {
        // The slip was printed and hung on the part -- a number that comes round
        // again would point at two different things.
        $action = app(ChangeLotState::class);
        $user = $this->userWith(Permissions::STOCK_QUARANTINE);

        $first = $action->handle($this->lot(), LotState::Quarantined, 'Grund', $user);
        $second = $action->handle($this->lot(), LotState::Quarantined, 'Grund', $user);

        $this->assertSame(now()->format('Ym').'-001', $first->quarantine_tag);
        $this->assertSame(now()->format('Ym').'-002', $second->quarantine_tag);
    }

    /**
     * Die Rueckkehr aus der Eingangs-Quarantaene ist seit dem Feldtest eine
     * RECHTEFRAGE (stock.quarantine.release), keine Lizenzfrage: "sollte
     * jeder mit berechtigung duerfen." Ohne das Recht bleibt sie verwehrt --
     * und die Ablehnung nennt das Recht, nicht die Lizenz.
     */
    #[Test]
    public function releasing_the_incoming_hold_is_a_permission_matter(): void
    {
        $lot = $this->lot();
        $storeman = $this->userWith(Permissions::STOCK_QUARANTINE, Permissions::STOCK_QUARANTINE_CERTIFY);

        app(ChangeLotState::class)->handle($lot, LotState::Quarantined, 'Papier fehlt', $storeman);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/permission/');

        app(ChangeLotState::class)->handle(
            $lot->fresh(), LotState::Serviceable, 'Form 1 nachgereicht', $storeman,
        );
    }

    #[Test]
    public function the_release_right_suffices_and_the_name_is_still_recorded(): void
    {
        $lot = $this->lot();
        $storeman = $this->userWith(Permissions::STOCK_QUARANTINE, Permissions::STOCK_QUARANTINE_RELEASE);

        app(ChangeLotState::class)->handle($lot, LotState::Quarantined, 'Papier fehlt', $storeman);

        $change = app(ChangeLotState::class)->handle(
            $lot->fresh(), LotState::Serviceable, 'Form 1 nachgereicht', $storeman,
        );

        $this->assertSame(LotState::Serviceable, $lot->fresh()->state);

        // Auch ohne Lizenzpflicht eine Feststellung MIT Namen -- nur die
        // Lizenzfelder bleiben leer. Wer angenommen hat, steht im Satz.
        $this->assertTrue($change->isDetermination());
        $this->assertSame($storeman->name, $change->determined_by_name);
        $this->assertNull($change->qualification_reference);
    }

    /**
     * Was qualifiziert BLEIBT: das Urteil ueber den Zustand. Der Weg zurueck
     * aus "unbrauchbar" verlangt weiterhin Recht UND Part-66 -- der
     * Feldtest-Umbau betrifft nur die Eingangs-Quarantaene.
     */
    #[Test]
    public function qualified_staff_determinations_still_freeze_the_record(): void
    {
        $lot = $this->lot();
        $mechanic = $this->userWith(Permissions::STOCK_QUARANTINE, Permissions::STOCK_QUARANTINE_CERTIFY);
        $this->givePart66($mechanic, 'DE.66.12345', 'B1.2');

        $change = app(ChangeLotState::class)->handle(
            $lot, LotState::Unserviceable, 'Riss festgestellt', $mechanic,
        );

        $this->assertSame(LotState::Unserviceable, $lot->fresh()->state);
        $this->assertTrue($change->isDetermination());

        // Copied, not referenced -- E7.
        $this->assertSame($mechanic->name, $change->determined_by_name);
        $this->assertSame('DE.66.12345', $change->qualification_reference);
        $this->assertSame('B1.2', $change->qualification_category);
    }

    #[Test]
    public function the_frozen_record_survives_the_account_being_changed(): void
    {
        // The whole point of copying rather than referencing: the determination
        // has to stay readable after the person's data has moved on.
        $lot = $this->lot();
        $mechanic = $this->userWith(Permissions::STOCK_SCRAP, Permissions::STOCK_QUARANTINE_CERTIFY);
        $qualification = $this->givePart66($mechanic, 'DE.66.12345', 'B1.2');

        $change = app(ChangeLotState::class)->handle(
            $lot, LotState::Unserviceable, 'Riss festgestellt', $mechanic,
        );

        // The member leaves: the account is pseudonymised and the licence gone.
        $mechanic->update(['name' => 'ehemaliges Mitglied #'.$mechanic->id]);
        $qualification->delete();

        $change = $change->fresh();
        $this->assertSame('DE.66.12345', $change->qualification_reference);
        $this->assertStringNotContainsString('ehemaliges', (string) $change->determined_by_name);
    }

    #[Test]
    public function a_determination_cannot_be_edited_or_deleted(): void
    {
        $mechanic = $this->userWith(Permissions::STOCK_SCRAP, Permissions::STOCK_QUARANTINE_CERTIFY);
        $this->givePart66($mechanic);

        $change = app(ChangeLotState::class)->handle(
            $this->lot(), LotState::Unserviceable, 'Riss', $mechanic,
        );

        try {
            $change->update(['reason' => 'doch nicht']);
            $this->fail('A determination must not be editable.');
        } catch (RuntimeException) {
        }

        try {
            $change->delete();
            $this->fail('A determination must not be deletable.');
        } catch (RuntimeException) {
        }

        $this->assertSame('Riss', $change->fresh()->reason);
    }

    #[Test]
    public function scrapping_is_a_one_way_street(): void
    {
        // 145.A.42: a part beyond repair must never re-enter the supply system.
        // The transition simply does not exist -- not for an administrator either.
        $lot = $this->lot();
        $mechanic = $this->userWith(Permissions::STOCK_SCRAP, Permissions::STOCK_QUARANTINE_CERTIFY);
        $this->givePart66($mechanic);

        app(ChangeLotState::class)->handle($lot, LotState::Unserviceable, 'Riss', $mechanic);
        app(ChangeLotState::class)->handle($lot->fresh(), LotState::Unsalvageable, 'Nicht reparabel', $mechanic);

        $this->assertSame(LotState::Unsalvageable, $lot->fresh()->state);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/never re-enter/');

        app(ChangeLotState::class)->handle(
            $lot->fresh(), LotState::Serviceable, 'War doch in Ordnung', $mechanic,
        );
    }

    #[Test]
    public function disposal_is_a_movement_and_keeps_the_record(): void
    {
        // "I do not keep every bit of rubbish" -- but the evidence that it
        // existed does not go out with it.
        $part = $this->lotTrackedPart();
        app(ReceiveStock::class)->handle($part, 4, '2026-07-01');
        $lot = StockLot::sole();

        $mechanic = $this->userWith(Permissions::STOCK_SCRAP, Permissions::STOCK_QUARANTINE_CERTIFY);
        $this->givePart66($mechanic);

        $action = app(ChangeLotState::class);
        $action->handle($lot, LotState::Unserviceable, 'Abgelaufen', $mechanic);
        $action->handle($lot->fresh(), LotState::Unsalvageable, 'Nicht verwendbar', $mechanic);
        $action->handle($lot->fresh(), LotState::Disposed, 'Entsorgt am 28.07.', $mechanic);

        $lot = $lot->fresh();
        $this->assertSame(LotState::Disposed, $lot->state);
        $this->assertSame(0.0, $lot->remainingQuantity());

        // The lot and its history are still there.
        $this->assertSame(1, StockLot::count());
        $this->assertSame(3, LotStateChange::where('stock_lot_id', $lot->id)->count());
        $this->assertNotNull($lot->document_reference ?? true);
    }

    #[Test]
    public function every_change_needs_a_reason(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(ChangeLotState::class)->handle(
            $this->lot(), LotState::Quarantined, '   ',
            $this->userWith(Permissions::STOCK_QUARANTINE),
        );
    }

    #[Test]
    public function the_history_of_a_lot_stays_complete(): void
    {
        // A single set of columns on the lot would only remember the last
        // change, and the awkward questions are about earlier ones.
        $lot = $this->lot();
        $mechanic = $this->userWith(
            Permissions::STOCK_QUARANTINE,
            Permissions::STOCK_QUARANTINE_CERTIFY,
            Permissions::STOCK_QUARANTINE_RELEASE,
        );
        $this->givePart66($mechanic);

        $action = app(ChangeLotState::class);
        $action->handle($lot, LotState::Quarantined, 'Erster Verdacht', $mechanic);
        $action->handle($lot->fresh(), LotState::Serviceable, 'Verdacht ausgeräumt', $mechanic);
        $action->handle($lot->fresh(), LotState::Quarantined, 'Erneut aufgefallen', $mechanic);

        $history = $lot->fresh()->stateChanges;

        $this->assertCount(3, $history);
        $this->assertSame('Erneut aufgefallen', $history->first()->reason);
    }

    private function lot(): StockLot
    {
        $part = $this->lotTrackedPart();
        app(ReceiveStock::class)->handle($part, 4, '2026-07-01');

        return StockLot::where('part_type_id', $part->id)->sole();
    }

    private function lotTrackedPart(): PartType
    {
        return PartType::create([
            'name' => 'Teil '.uniqid(),
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'St',
            'shelf_life_days' => 365,
        ]);
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function givePart66(User $user, string $reference = 'DE.66.00000', string $category = 'B1'): Qualification
    {
        return Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => $reference,
            'category' => $category,
            'valid_from' => now()->subYear()->toDateString(),
        ]);
    }
}
