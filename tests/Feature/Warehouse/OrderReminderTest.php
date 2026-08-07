<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Mail\OverdueOrdersMail;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\PurchaseOrder;
use App\Modules\Warehouse\Models\PurchaseOrderLine;
use App\Modules\Warehouse\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Die Erinnerung an überfällige Lieferungen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DAS IST DER ZWECK DES GANZEN BESTELLTEILS. Vorgabe: „Der Hintergrund ist das
 * ich gerade erst mit einem Lieferanten auf die nase gefallen bin der sich
 * nicht gemeldet hatte. Das hätte mir fast einen Termin gerissen."
 *
 * Alles andere — Positionen, Mengen, Zustände — ist nur, woran diese Mail
 * hängt. Entsprechend ist der wichtigste Test hier
 * `it_does_not_remind_every_single_day`: Eine Erinnerung, die man wegwischt,
 * ohne sie zu lesen, ist keine.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class OrderReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(ModuleManager::class)->enable('warehouse');
        app(ModuleManager::class)->forgetCache();

        config()->set('mail.mailers.smtp.host', 'smtp.example.org');
        config()->set('mail.from.address', 'werkstatt@example.org');
    }

    #[Test]
    public function an_overdue_delivery_is_reported(): void
    {
        Mail::fake();

        $bestellung = $this->overdueOrder();

        $this->artisan('aeronance:remind-orders')->assertSuccessful();

        Mail::assertSent(OverdueOrdersMail::class);
        $this->assertNotNull($bestellung->fresh()->reminded_at);
    }

    /**
     * DER TEST, UM DEN ES GEHT: nicht jeden Tag dasselbe.
     *
     * Ohne Abstand schriebe der tägliche Lauf jeden Morgen dieselbe Mail, bis
     * die Lieferung kommt — und die vierte identische Nachricht wischt jeder
     * weg, ohne sie zu lesen.
     */
    #[Test]
    public function it_does_not_remind_every_single_day(): void
    {
        Mail::fake();
        $this->overdueOrder();

        $this->artisan('aeronance:remind-orders')->assertSuccessful();
        $this->artisan('aeronance:remind-orders')->assertSuccessful();
        $this->artisan('aeronance:remind-orders')->assertSuccessful();

        Mail::assertSentCount(1);
    }

    /**
     * Nach Ablauf des Abstands erinnert es wieder.
     *
     * Einmal erinnern und dann schweigen wäre der andere Fehler: Wer die Mail
     * übersieht, hört nie wieder davon.
     */
    #[Test]
    public function after_the_interval_it_reminds_again(): void
    {
        Mail::fake();
        $bestellung = $this->overdueOrder();

        $this->artisan('aeronance:remind-orders')->assertSuccessful();

        $bestellung->fresh()->update([
            'reminded_at' => now()->subDays(config('aeronance.orders.reminder_interval_days') + 1),
        ]);

        $this->artisan('aeronance:remind-orders')->assertSuccessful();

        Mail::assertSentCount(2);
    }

    /**
     * EINE MAIL JE MENSCH, nicht je Bestellung.
     *
     * Wer drei Sachen bestellt hat, soll eine Liste bekommen — bei drei
     * Nachrichten liest er die dritte nicht mehr.
     */
    #[Test]
    public function several_overdue_orders_become_one_mail(): void
    {
        Mail::fake();

        $besteller = $this->user();
        $this->overdueOrder($besteller);
        $this->overdueOrder($besteller);
        $this->overdueOrder($besteller);

        $this->artisan('aeronance:remind-orders')->assertSuccessful();

        Mail::assertSentCount(1);
        Mail::assertSent(OverdueOrdersMail::class, fn (OverdueOrdersMail $m): bool => $m->orders->count() === 3);
    }

    /**
     * Ohne Mailweg wird das GESAGT, nicht stillschweigend übergangen.
     *
     * Genau hier wäre ein stiller Fehlschlag am teuersten: Wer sich auf die
     * Erinnerung verlässt, verlässt sich darauf, dass sie kommt. Der Befehl
     * meldet trotzdem Erfolg — ein geplanter Lauf, der an einer fehlenden
     * Einstellung scheitert, weckt jemanden ohne Grund.
     */
    #[Test]
    public function without_a_mailer_it_says_so(): void
    {
        Mail::fake();
        config()->set('mail.mailers.smtp.host', null);

        $bestellung = $this->overdueOrder();

        $this->artisan('aeronance:remind-orders')
            ->expectsOutputToContain('kein Mailversand')
            ->assertSuccessful();

        Mail::assertNothingSent();

        // Und es gilt NICHT als erinnert -- sonst bliebe es fuer immer still.
        $this->assertNull($bestellung->fresh()->reminded_at);
    }

    /**
     * Eine Lieferung, die noch nicht fällig ist, erinnert an nichts.
     */
    #[Test]
    public function a_delivery_still_in_time_is_left_alone(): void
    {
        Mail::fake();

        $this->order($this->user(), now()->addDays(5)->toDateString());

        $this->artisan('aeronance:remind-orders')->assertSuccessful();

        Mail::assertNothingSent();
    }

    private function overdueOrder(?User $besteller = null): PurchaseOrder
    {
        return $this->order($besteller ?? $this->user(), now()->subDays(4)->toDateString());
    }

    private function order(User $besteller, string $expected): PurchaseOrder
    {
        $teil = PartType::query()->firstOrCreate(
            ['name' => 'Bremsklotz'],
            ['classification' => PartClassification::StandardPart],
        );

        $bestellung = PurchaseOrder::create([
            'order_number' => 'B-'.uniqid(),
            'supplier_id' => Supplier::firstOrCreate(['name' => 'Firma'])->getKey(),
            'ordered_at' => now()->subDays(20)->toDateString(),
            'expected_at' => $expected,
            'created_by_id' => $besteller->getKey(),
        ]);

        PurchaseOrderLine::create([
            'purchase_order_id' => $bestellung->getKey(),
            'part_type_id' => $teil->getKey(),
            'quantity_ordered' => 5,
        ]);

        return $bestellung->fresh();
    }

    private function user(): User
    {
        return User::factory()->create(['is_active' => true, 'email' => uniqid().'@example.org']);
    }
}
