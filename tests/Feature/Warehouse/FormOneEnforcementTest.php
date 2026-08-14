<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Access\AccessSetup;
use App\Core\Models\Qualification;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Warehouse\Actions\ChangeLotState;
use App\Modules\Warehouse\Actions\IssueStock;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Actions\RemovePartFromAircraft;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Filament\Pages\StockAttention;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Ohne Form 1 kein Einbau -- und zwar auf JEDEM Weg ins Regal.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Feldtest, wörtlich: "Das system hat mir bei einem seriennummer geführten
 * form 1 teil erlaubt dieses ohne nummer des form 1 und ohne scan anzulegen
 * und als verwendbar freizuschreiben. das darf nicht sein."
 *
 * Der Wareneingang wachte längst (ReceiveStock::refuseWithoutCertificate) --
 * die AUSGABE nicht. Damit half die Wache genau so lange, wie niemand einen
 * anderen Weg ins Regal fand: nachträglich gesetzte Form-1-Pflicht am
 * Bauteiltyp, Inventurkorrektur, Rückgabe. Die Prüfung steht deshalb jetzt
 * dort, wo das Teil ans Luftfahrzeug geht, und gilt damit für alle Wege
 * zugleich (ML.A.501).
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class FormOneEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(ModuleManager::class)->enable('warehouse');
        app(ModuleManager::class)->forgetCache();
        app(AccessSetup::class)->run();
    }

    #[Test]
    public function a_lot_without_its_form_one_cannot_be_issued(): void
    {
        [$teil, $los] = $this->lotWithoutCertificate();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Form-1-Nachweis/');

        app(IssueStock::class)->handle(
            partType: $teil,
            quantity: 1.0,
            user: $this->storekeeper(),
            aircraftReference: 'D-KABC',
            lot: $los,
        );
    }

    #[Test]
    public function nor_is_it_even_offered(): void
    {
        // Aus der Auswahl fällt es ebenfalls -- sonst klickt jemand darauf
        // und liest erst danach die Ablehnung.
        [$teil] = $this->lotWithoutCertificate();

        $this->assertSame(0, $teil->lots()->issuable()->count());
    }

    #[Test]
    public function with_the_number_on_file_it_may_be_issued(): void
    {
        // Die Nummer ist der Nachweis; der Scan ist die Vollständigkeit fürs
        // Audit -- und bleibt eine Mahnung, keine Sperre.
        [$teil, $los] = $this->lotWithoutCertificate();

        $los->forceFill([
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'EASA-F1-2026-0815',
        ])->save();

        $this->assertSame(1, $teil->lots()->issuable()->count());

        $bewegung = app(IssueStock::class)->handle(
            partType: $teil,
            quantity: 1.0,
            user: $this->storekeeper(),
            aircraftReference: 'D-KABC',
            lot: $los->fresh(),
        );

        $this->assertNotNull($bewegung);
    }

    #[Test]
    public function a_part_without_form_one_duty_is_untouched(): void
    {
        // Harz geht weiter ohne Papier durch -- verweigert wird nur, was auch
        // verlangt ist. Mit Haltbarkeit, damit ueberhaupt ein Los entsteht:
        // Ohne Los-Fuehrung gibt es nichts, was der Scope pruefen koennte.
        $teil = PartType::create([
            'name' => 'Harz L285',
            'classification' => PartClassification::ConsumableMaterial,
            'unit_of_measure' => 'kg',
            'requires_form_one' => false,
            'shelf_life_days' => 365,
        ]);

        app(ReceiveStock::class)->handle(
            partType: $teil,
            quantity: 10.0,
            receivedAt: now()->toDateString(),
            user: $this->storekeeper(),
        );

        $this->assertSame(1, $teil->lots()->issuable()->count());
    }

    #[Test]
    public function the_attention_screen_tells_the_two_cases_apart(): void
    {
        /*
         * "Nachweis erfasst, Dokument fehlt" stand vorher auch über Losen, für
         * die nie ein Nachweis erfasst wurde -- das las sich wie eine
         * Beruhigung, wo eine Sperre gilt.
         */
        [, $ohne] = $this->lotWithoutCertificate();

        $this->assertTrue(StockAttention::withoutCertificate()->contains(
            fn (StockLot $l): bool => $l->id === $ohne->id,
        ));
        $this->assertFalse(StockAttention::missingDocuments()->contains(
            fn (StockLot $l): bool => $l->id === $ohne->id,
        ));

        // Mit Nummer, ohne Scan: genau umgekehrt.
        $ohne->forceFill([
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'EASA-F1-2026-0815',
        ])->save();

        $this->assertFalse(StockAttention::withoutCertificate()->contains(
            fn (StockLot $l): bool => $l->id === $ohne->id,
        ));
        $this->assertTrue(StockAttention::missingDocuments()->contains(
            fn (StockLot $l): bool => $l->id === $ohne->id,
        ));
    }

    #[Test]
    public function a_quarantined_lot_cannot_be_released_without_its_certificate(): void
    {
        /*
         * Die Freigabe war das Loch hinter allen Wachen: Wareneingang,
         * Inventurfund und Reparaturrückkehr parken Ware ohne Nachweis in der
         * Quarantäne -- und ein Klick in der Losliste holte sie ohne jede
         * Papierprüfung wieder heraus.
         */
        [, $los] = $this->lotWithoutCertificate();

        app(ChangeLotState::class)->handle(
            lot: $los,
            target: LotState::Quarantined,
            reason: 'Nachweis fehlt',
            user: $this->quarantineOfficer(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Nachweis fehlt|Form-1-pflichtig/');

        app(ChangeLotState::class)->handle(
            lot: $los->fresh(),
            target: LotState::Serviceable,
            reason: 'Passt schon',
            user: $this->quarantineOfficer(),
        );
    }

    #[Test]
    public function a_removed_part_may_still_go_back_into_its_own_aircraft(): void
    {
        /*
         * REGRESSIONSPRÜFUNG AN DER EIGENEN SPERRE: Ein ausgebautes Teil trägt
         * keine Form 1 -- sein Nachweis ist die Feststellung beim Ausbau, und
         * genau dafür gibt es den Ausbau/Wiedereinbau. Die erste Fassung der
         * Sperre hätte ihn unmöglich gemacht (Review-Fund).
         */
        $teil = PartType::create([
            'name' => 'Höhenmesser',
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'Stk',
            'requires_form_one' => true,
            'serial_tracked' => true,
        ]);

        app(RemovePartFromAircraft::class)->handle(
            partType: $teil,
            quantity: 1.0,
            aircraft: 'D-KABC',
            user: $this->qualifiedMechanic(),
            reason: 'Zum Prüfen ausgebaut',
            determinedServiceable: true,
            lotData: ['serial_number' => 'SN-4711'],
        );

        $los = $teil->lots()->firstOrFail();

        // In sein eigenes Luftfahrzeug: erlaubt.
        $this->assertSame(1, $teil->lots()->issuable()->count());

        $bewegung = app(IssueStock::class)->handle(
            partType: $teil->fresh(),
            quantity: 1.0,
            user: $this->storekeeper(),
            aircraftReference: 'D-KABC',
            lot: $los,
        );

        $this->assertNotNull($bewegung);
    }

    #[Test]
    public function but_not_into_a_different_one(): void
    {
        $teil = PartType::create([
            'name' => 'Höhenmesser',
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'Stk',
            'requires_form_one' => true,
            'serial_tracked' => true,
        ]);

        app(RemovePartFromAircraft::class)->handle(
            partType: $teil,
            quantity: 1.0,
            aircraft: 'D-KABC',
            user: $this->qualifiedMechanic(),
            reason: 'Zum Prüfen ausgebaut',
            determinedServiceable: true,
            lotData: ['serial_number' => 'SN-4711'],
        );

        $this->expectException(RuntimeException::class);

        app(IssueStock::class)->handle(
            partType: $teil->fresh(),
            quantity: 1.0,
            user: $this->storekeeper(),
            aircraftReference: 'D-KXYZ',
            lot: $teil->lots()->firstOrFail(),
        );
    }

    /**
     * Ein serialisiertes Form-1-Teil, das ohne Nachweis im Regal steht.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * AUF DEM WEG, DEN ES WIRKLICH GIBT: Der Bauteiltyp wird zuerst ohne
     * Form-1-Pflicht angelegt und eingebucht -- da wacht niemand, zu Recht --,
     * und die Pflicht wird DANACH gesetzt. Bestehende Lose fasst das nicht an,
     * und schon steht ein Höhenmesser als "verwendbar" im Regal, dem der
     * Nachweis fehlt. Genau der gemeldete Zustand, ohne einen einzigen
     * Kunstgriff im Test.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @return array{0: PartType, 1: StockLot}
     */
    private function lotWithoutCertificate(): array
    {
        $teil = PartType::create([
            'name' => 'Höhenmesser',
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'Stk',
            'requires_form_one' => false,
            'serial_tracked' => true,
        ]);

        app(ReceiveStock::class)->handle(
            partType: $teil,
            quantity: 1.0,
            receivedAt: now()->toDateString(),
            user: $this->storekeeper(),
            lotData: ['serial_number' => 'SN-4711'],
        );

        // Und jetzt die Pflicht -- nachträglich, wie im Feld.
        $teil->update(['requires_form_one' => true]);

        $los = $teil->lots()->firstOrFail();

        $this->assertSame(LotState::Serviceable, $los->state);
        $this->assertFalse($los->hasRequiredDocument());

        return [$teil->fresh(), $los->fresh()];
    }

    private function quarantineOfficer(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::STOCK_QUARANTINE, Permissions::STOCK_QUARANTINE_RELEASE);

        return $user->fresh();
    }

    private function qualifiedMechanic(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::STOCK_RECEIVE, Permissions::STOCK_QUARANTINE_CERTIFY);

        Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.12345',
            'valid_from' => now()->subYear()->toDateString(),
            'valid_until' => now()->addYear()->toDateString(),
        ]);

        return $user->fresh();
    }

    private function storekeeper(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::STOCK_ISSUE, Permissions::STOCK_RECEIVE);

        return $user->fresh();
    }
}
