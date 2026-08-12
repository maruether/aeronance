<?php

declare(strict_types=1);

namespace Tests\Feature\TaskCards;

use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Enums\ActivityKind;
use App\Modules\TaskCards\Models\TaskCard;
use App\Modules\TaskCards\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Schnellreparatur: ein Zug, zwei Datensaetze, kein Umweg.
 *
 * Feldtest: "Eine einzelne Reparatur ist nur eine Arbeitskarte. Das ist
 * unnoetige Arbeit." Der Vorgang entsteht implizit mit -- abgekuerzt, nicht
 * uebersprungen: Er bleibt das Bindeglied zu Nummernkreis und Freigabe.
 */
final class QuickRepairTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function one_call_creates_visit_and_card_together(): void
    {
        $aircraft = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);
        $mechanic = User::factory()->create(['is_active' => true]);

        $card = app(ManageWorkOrder::class)->openQuick(
            aircraft: $aircraft,
            user: $mechanic,
            title: 'Reifen Hauptfahrwerk getauscht',
            kind: ActivityKind::Repair,
        );

        $order = $card->workOrder;

        $this->assertSame(1, WorkOrder::count());
        $this->assertSame(1, TaskCard::count());

        // Titel geteilt, Karte im Nummernkreis des Vorgangs, Kennzeichen kopiert.
        $this->assertSame('Reifen Hauptfahrwerk getauscht', $order->title);
        $this->assertSame('Reifen Hauptfahrwerk getauscht', $card->title);
        $this->assertStringStartsWith($order->number, $card->number);
        $this->assertSame('D-KABC', $card->aircraft_registration);
        $this->assertNotNull($order->counters_at_open);
    }

    #[Test]
    public function a_failing_card_takes_the_visit_down_with_it(): void
    {
        // Alles oder nichts: Ein Vorgang ohne seine Karte waere genau der
        // Leerlauf, den der Schnellweg abschaffen soll.
        $aircraft = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);
        $mechanic = User::factory()->create(['is_active' => true]);

        try {
            app(ManageWorkOrder::class)->openQuick(
                aircraft: $aircraft,
                user: $mechanic,
                title: 'Kritisch ohne Grund',
                critical: true,
                criticalReason: null,
            );
            $this->fail('Kritisch ohne Begruendung muss abgelehnt werden.');
        } catch (\Throwable) {
            // erwartet -- siehe addCard-Wache
        }

        $this->assertSame(0, WorkOrder::count(), 'Der implizite Vorgang darf nicht uebrig bleiben.');
        $this->assertSame(0, TaskCard::count());
    }
}
