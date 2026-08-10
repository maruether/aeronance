<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Core\Access\AccessSetup;
use App\Core\Access\CorePermissions;
use App\Core\Filament\Pages\AuditTrail;
use App\Core\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Decision E3: the audit trail is append-only.
 *
 * These tests exist because the property is worth nothing if it holds only by
 * convention. A log that can be edited proves nothing -- every missing entry
 * might be a deleted one.
 */
final class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_records_an_entry(): void
    {
        $user = User::factory()->create();

        activity()
            ->causedBy($user)
            ->withProperties(['module' => 'warehouse'])
            ->log('Bauteiltyp angelegt');

        $entry = Activity::sole();

        $this->assertSame('Bauteiltyp angelegt', $entry->description);
        $this->assertTrue($entry->causer->is($user));
        $this->assertNotNull($entry->created_at, 'An entry without a timestamp is not an audit trail.');
    }

    #[Test]
    public function an_entry_cannot_be_changed(): void
    {
        activity()->log('Ursprünglicher Eintrag');

        $entry = Activity::sole();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot be changed/');

        $entry->update(['description' => 'Nachträglich geschönt']);
    }

    #[Test]
    public function an_entry_cannot_be_deleted(): void
    {
        activity()->log('Unbequemer Eintrag');

        $entry = Activity::sole();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot be deleted/');

        $entry->delete();
    }

    #[Test]
    public function the_entry_survives_the_attempt(): void
    {
        activity()->log('Unbequemer Eintrag');

        try {
            Activity::sole()->delete();
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(1, Activity::count(), 'The entry must still be there after a refused delete.');
    }

    #[Test]
    public function retention_can_remove_an_expired_entry(): void
    {
        // The one legitimate way out, named for what it is and reserved for the
        // scheduled job -- not something a person reaches through the interface.
        activity()->log('Alter Eintrag');

        Activity::sole()->forceRetentionDelete();

        $this->assertSame(0, Activity::count());
    }

    /**
     * Die Seite baut sich -- leer UND mit einem Array in den Eigenschaften.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Beides von test.aeronance.de: Die Protokollseite starb mit 500, Ausloeser
     * war (string) auf einen Array-Wert in format() -- ein JSON-Cast am Modell
     * oder ein withProperties() mit Array reicht, und EIN solcher Eintrag
     * reisst die ganze Seite. Der Leer-Fall steht mit dabei, weil er der
     * Zustand jeder frischen Installation ist: Der erste Blick eines Vereins
     * ins Protokoll darf nicht der erste Fehler sein.
     * ─────────────────────────────────────────────────────────────────────────
     */
    #[Test]
    public function the_page_builds_when_the_trail_is_empty(): void
    {
        $this->actingAs($this->auditViewer());

        Livewire::test(AuditTrail::class)->assertSuccessful();
    }

    #[Test]
    public function the_page_builds_with_array_properties(): void
    {
        $this->actingAs($this->auditViewer());

        activity()
            ->withProperties(['modules' => ['warehouse', 'fleet'], 'reason' => 'setup'])
            ->log('updated');

        Livewire::test(AuditTrail::class)
            ->assertSuccessful()
            ->assertSee('warehouse');
    }

    private function auditViewer(): User
    {
        app(AccessSetup::class)->run();

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(CorePermissions::AUDIT_VIEW);

        return $user->fresh();
    }

    #[Test]
    public function there_is_no_permission_to_delete_audit_entries(): void
    {
        // The absence is the mechanism, so it is worth a test: a permission
        // added later by accident would be caught here.
        $permissions = array_map(
            static fn ($definition): string => $definition->name,
            CorePermissions::all(),
        );

        foreach ($permissions as $permission) {
            $this->assertStringNotContainsString(
                'delete',
                $permission,
                'No permission may allow deleting from the audit trail. See E3.',
            );
            $this->assertStringNotContainsString('purge', $permission);
        }
    }
}
