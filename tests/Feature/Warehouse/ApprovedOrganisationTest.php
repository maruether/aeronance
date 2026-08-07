<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Warehouse\Actions\DispatchForRepair;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\RepairDispatch;
use App\Modules\Warehouse\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Betriebe mit Zulassung.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER TEST, UM DEN ES GEHT: `a_lapsed_approval_refuses_the_dispatch`.
 *
 * Eine Bescheinigung ist genau so viel wert wie die Zulassung dessen, der sie
 * ausgestellt hat. Ging ein Teil an einen Betrieb, dessen Zulassung abgelaufen
 * war, ist das Papier, das zurückkommt, nichts wert — und das fällt sonst erst
 * auf, wenn Jahre später jemand danach fragt, rückwirkend für alles aus dieser
 * Zeit.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ApprovedOrganisationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();
        app(ModuleManager::class)->enable('warehouse');
        app(ModuleManager::class)->forgetCache();
    }

    /**
     * DER TEST, UM DEN ES GEHT.
     */
    #[Test]
    public function a_lapsed_approval_refuses_the_dispatch(): void
    {
        $betrieb = Supplier::create([
            'name' => 'Alte Werft',
            'approval_number' => 'EASA.145.9999',
            'approval_expires_at' => now()->subMonth()->toDateString(),
        ]);

        $this->expectException(RuntimeException::class);

        $this->dispatchTo($betrieb);
    }

    #[Test]
    public function a_valid_approval_lets_it_through_and_is_copied(): void
    {
        $betrieb = Supplier::create([
            'name' => 'Lange Aviation',
            'approval_number' => 'EASA.145.1234',
            'approval_scope' => 'Part-145',
            'approval_expires_at' => now()->addYear()->toDateString(),
        ]);

        $versand = $this->dispatchTo($betrieb);

        $this->assertSame($betrieb->id, $versand->supplier_id);

        // KOPIERT, nicht nur verwiesen: Wohin das Teil ging und unter welcher
        // Nummer, muss lesbar bleiben, wenn der Betrieb umbenannt wird.
        $this->assertSame('Lange Aviation', $versand->shop_name);
        $this->assertSame('EASA.145.1234', $versand->shop_approval);
    }

    /**
     * Eine unbefristete Zulassung läuft nie ab.
     *
     * Leer heißt ausdrücklich „unbefristet" und nicht „unbekannt" — viele
     * Zulassungen gelten, bis die Aufsicht sie entzieht.
     */
    #[Test]
    public function an_open_ended_approval_never_lapses(): void
    {
        $betrieb = Supplier::create([
            'name' => 'Unbefristet GmbH',
            'approval_number' => 'EASA.145.4321',
        ]);

        $this->assertTrue($betrieb->isApprovedOrganisation());
        $this->assertFalse($betrieb->approvalHasLapsed());
        $this->assertFalse($betrieb->approvalExpiresSoon());

        $this->assertSame($betrieb->id, $this->dispatchTo($betrieb)->supplier_id);
    }

    /**
     * Die Schraubenhandlung ist kein zugelassener Betrieb — und das ist kein
     * Mangel.
     */
    #[Test]
    public function a_plain_supplier_is_not_an_approved_organisation(): void
    {
        $handel = Supplier::create(['name' => 'Schraubenhandel Müller']);

        $this->assertFalse($handel->isApprovedOrganisation());
        $this->assertFalse($handel->approvalHasLapsed());

        // Und der Versand dorthin geht trotzdem -- nicht jede Instandsetzung
        // verlangt einen zugelassenen Betrieb.
        $this->assertNotNull($this->dispatchTo($handel));
    }

    /**
     * Bald ablaufend ist eine Vorwarnung, keine Sperre.
     */
    #[Test]
    public function an_approval_running_out_warns_but_does_not_block(): void
    {
        $betrieb = Supplier::create([
            'name' => 'Läuft bald ab',
            'approval_number' => 'EASA.145.5555',
            'approval_expires_at' => now()->addDays(20)->toDateString(),
        ]);

        $this->assertTrue($betrieb->approvalExpiresSoon());
        $this->assertFalse($betrieb->approvalHasLapsed());
        $this->assertNotNull($this->dispatchTo($betrieb));
    }

    /**
     * Ohne Betrieb aus dem Verzeichnis bleibt der Freitext, wie er war.
     */
    #[Test]
    public function free_text_still_works_without_a_register_entry(): void
    {
        $versand = app(DispatchForRepair::class)->handle(
            partType: $this->partWithStock(),
            quantity: 1,
            lot: null,
            user: $this->user(),
            reason: 'Undicht',
            shopName: 'Werkstatt ohne Eintrag',
            shopApproval: 'EASA.145.0000',
        );

        $this->assertNull($versand->supplier_id);
        $this->assertSame('Werkstatt ohne Eintrag', $versand->shop_name);
    }

    private function dispatchTo(Supplier $shop): RepairDispatch
    {
        return app(DispatchForRepair::class)->handle(
            partType: $this->partWithStock(),
            quantity: 1,
            lot: null,
            user: $this->user(),
            reason: 'Undicht',
            shop: $shop,
        );
    }

    private function partWithStock(): PartType
    {
        $teil = PartType::create([
            'name' => 'Bremszylinder '.uniqid(),
            'classification' => PartClassification::StandardPart,
            'unit' => 'Stk',
        ]);

        app(ReceiveStock::class)->handle($teil, 5, now()->toDateString());

        return $teil->fresh();
    }

    private function user(): User
    {
        return User::factory()->create(['is_active' => true]);
    }
}
