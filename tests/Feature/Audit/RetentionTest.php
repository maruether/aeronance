<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Core\Models\Activity;
use App\Models\User;
use App\Modules\Warehouse\Enums\MovementType;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StockMovement;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Retention -- decision E3 and F29.
 *
 * The point of these tests is mostly what the job must NOT do. Everything but
 * the two logs is either stock or evidence, and a retention job that could
 * reach the stock movements would destroy exactly the traceability the system
 * exists for.
 */
final class RetentionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function nothing_happens_while_it_is_switched_off(): void
    {
        // Off by default, on purpose: a club that never configures it loses
        // nothing.
        activity()->log('Alter Eintrag');
        Activity::query()->update(['created_at' => now()->subYears(10)]);

        $this->artisan('aeronance:retention')->assertSuccessful();

        $this->assertSame(1, Activity::count());
    }

    #[Test]
    public function it_removes_activity_entries_past_their_time(): void
    {
        config()->set('aeronance.retention.activity_log', ['enabled' => true, 'days' => 365 * 3]);

        activity()->log('Alt');
        Activity::query()->update(['created_at' => now()->subYears(4)]);
        activity()->log('Neu');

        $this->artisan('aeronance:retention')->assertSuccessful();

        $remaining = Activity::query()->where('description', '!=', 'retention.activity_log')->get();
        $this->assertCount(1, $remaining);
        $this->assertSame('Neu', $remaining->first()->description);
    }

    #[Test]
    public function the_clean_up_records_that_it_ran(): void
    {
        // Otherwise the gap in the log has no explanation, which is exactly the
        // ambiguity an append-only trail is meant to avoid.
        config()->set('aeronance.retention.activity_log', ['enabled' => true, 'days' => 30]);

        activity()->log('Alt');
        Activity::query()->update(['created_at' => now()->subYears(1)]);

        $this->artisan('aeronance:retention')->assertSuccessful();

        $this->assertTrue(
            Activity::query()->where('description', 'retention.activity_log')->exists(),
            'The retention run must leave a trace of itself.',
        );
    }

    #[Test]
    public function a_dry_run_changes_nothing(): void
    {
        config()->set('aeronance.retention.activity_log', ['enabled' => true, 'days' => 30]);

        activity()->log('Alt');
        Activity::query()->update(['created_at' => now()->subYears(1)]);

        $this->artisan('aeronance:retention', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(1, Activity::count());
    }

    #[Test]
    public function break_glass_records_outlive_the_activity_log(): void
    {
        // Five years against three, deliberately: a privileged access is the
        // thing one most wants to be able to reconstruct.
        $this->assertGreaterThan(
            config('aeronance.retention.activity_log.days'),
            config('aeronance.retention.break_glass_log.days'),
        );
    }

    #[Test]
    public function stock_movements_are_untouchable_by_retention(): void
    {
        // The most important test in this file. Movements ARE the stock;
        // removing one changes what the club believes it has.
        config()->set('aeronance.retention', [
            'activity_log' => ['enabled' => true, 'days' => 1],
            'break_glass_log' => ['enabled' => true, 'days' => 1],
            'pseudonymise_former_members' => ['enabled' => true, 'days' => 1],
        ]);

        $part = PartType::create([
            'name' => 'Uraltes Teil',
            'classification' => PartClassification::StandardPart,
            'unit_of_measure' => 'St',
        ]);

        $movement = StockMovement::create([
            'part_type_id' => $part->id,
            'type' => MovementType::Receipt,
            'quantity' => 100,
            'occurred_at' => now()->subYears(20),
        ]);
        StockMovement::query()->update(['created_at' => now()->subYears(20)]);

        $this->artisan('aeronance:retention')->assertSuccessful();

        $this->assertSame(1, StockMovement::count());
        $this->assertSame(100.0, $part->fresh()->currentStock());
    }

    #[Test]
    public function lots_and_their_certificates_are_untouchable(): void
    {
        config()->set('aeronance.retention', [
            'activity_log' => ['enabled' => true, 'days' => 1],
            'break_glass_log' => ['enabled' => true, 'days' => 1],
            'pseudonymise_former_members' => ['enabled' => true, 'days' => 1],
        ]);

        $part = PartType::create([
            'name' => 'Altes Bauteil',
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'St',
            'requires_form_one' => true,
        ]);

        StockLot::create([
            'part_type_id' => $part->id,
            'lot_number' => '200601-001',
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2006-1',
            'received_at' => now()->subYears(20)->toDateString(),
        ]);

        $this->artisan('aeronance:retention')->assertSuccessful();

        $this->assertSame(1, StockLot::count());
        $this->assertSame('F1-2006-1', StockLot::sole()->document_reference);
    }

    #[Test]
    public function pseudonymising_keeps_the_entries_and_drops_only_the_name(): void
    {
        config()->set('aeronance.retention.pseudonymise_former_members', ['enabled' => true, 'days' => 28]);

        $user = User::factory()->create([
            'name' => 'Ausgetretenes Mitglied',
            'email' => 'weg@example.org',
            'is_active' => false,
            'deactivated_at' => now()->subMonths(2),
        ]);

        activity()->causedBy($user)->log('Hat etwas getan');

        $this->artisan('aeronance:retention')->assertSuccessful();

        $user = $user->fresh();
        $this->assertStringContainsString('ehemaliges Mitglied', $user->name);
        $this->assertStringNotContainsString('weg@example.org', $user->email);

        // The entry survives -- only who it points at is gone.
        $entry = Activity::query()->where('description', 'Hat etwas getan')->sole();
        $this->assertNull($entry->causer_id);
    }

    #[Test]
    public function an_active_member_is_never_pseudonymised(): void
    {
        config()->set('aeronance.retention.pseudonymise_former_members', ['enabled' => true, 'days' => 1]);

        $user = User::factory()->create(['name' => 'Aktives Mitglied', 'is_active' => true]);

        $this->artisan('aeronance:retention')->assertSuccessful();

        $this->assertSame('Aktives Mitglied', $user->fresh()->name);
    }

    #[Test]
    public function someone_deactivated_yesterday_is_not_yet_due(): void
    {
        config()->set('aeronance.retention.pseudonymise_former_members', ['enabled' => true, 'days' => 28]);

        $user = User::factory()->create([
            'name' => 'Gerade ausgetreten',
            'is_active' => false,
            'deactivated_at' => now()->subDay(),
        ]);

        $this->artisan('aeronance:retention')->assertSuccessful();

        $this->assertSame('Gerade ausgetreten', $user->fresh()->name);
    }

    #[Test]
    public function the_rules_are_actually_scheduled(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * DER BEFEHL WAR GEBAUT UND GETESTET -- UND WURDE NIE AUFGERUFEN.
         *
         * routes/console.php plante den Verfallslauf des Lagers und den
         * LTA-Abruf, die Aufbewahrungsregeln aber nicht. Ein Verein konnte eine
         * Regel einschalten, und es passierte nichts: kein Fehler, kein Hinweis,
         * nur eine Einstellung, die aussieht wie eine Zusage.
         *
         * Diese Zusicherung prueft den Plan und nicht die Datei, damit ein
         * Umbenennen des Befehls sie ebenfalls faellt.
         * ─────────────────────────────────────────────────────────────────────
         */
        $befehle = array_map(
            static fn ($event): string => (string) $event->command,
            app(Schedule::class)->events(),
        );

        $treffer = array_filter(
            $befehle,
            static fn (string $c): bool => str_contains($c, 'aeronance:retention'),
        );

        $this->assertNotEmpty($treffer, 'aeronance:retention steht in keinem Zeitplan.');
    }
}
