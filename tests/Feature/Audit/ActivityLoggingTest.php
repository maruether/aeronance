<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Core\Models\Activity;
use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StorageLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What actually reaches the audit trail.
 *
 * These exist because of a quiet failure that a smoke test caught: entries were
 * being written correctly, but the interface read them from the wrong place and
 * showed every one of them as empty. A log that records "something changed"
 * without saying what is barely better than no log at all -- so the content is
 * asserted here, not just the fact that a row appeared.
 */
final class ActivityLoggingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creating_a_part_type_is_recorded_with_its_values(): void
    {
        $part = PartType::create([
            'name' => 'Ölfilter',
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'St',
            'requires_form_one' => true,
        ]);

        $entry = Activity::query()->where('subject_id', $part->id)->sole();

        $this->assertSame('created', $entry->description);
        $this->assertSame('warehouse', $entry->log_name);

        $attributes = $entry->attribute_changes['attributes'] ?? [];
        $this->assertSame('Ölfilter', $attributes['name']);
        $this->assertTrue((bool) $attributes['requires_form_one']);
    }

    #[Test]
    public function a_change_records_both_the_old_and_the_new_value(): void
    {
        // Without the old value the entry says a thing changed but not from
        // what, which is the question anyone looking will actually have.
        $part = PartType::create([
            'name' => 'Alter Name',
            'classification' => PartClassification::StandardPart,
            'unit_of_measure' => 'St',
        ]);

        $part->update(['name' => 'Neuer Name']);

        $entry = Activity::query()->where('description', 'updated')->sole();
        $changes = $entry->attribute_changes;

        $this->assertSame('Neuer Name', $changes['attributes']['name']);
        $this->assertSame('Alter Name', $changes['old']['name']);
    }

    #[Test]
    public function saving_without_a_change_records_nothing(): void
    {
        // A trail full of no-op saves is one nobody reads.
        $part = PartType::create([
            'name' => 'Teil',
            'classification' => PartClassification::StandardPart,
            'unit_of_measure' => 'St',
        ]);

        $before = Activity::count();
        $part->update(['name' => 'Teil']);

        $this->assertSame($before, Activity::count());
    }

    #[Test]
    public function it_records_who_did_it(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        StorageLocation::create(['name' => 'Werkstatt']);

        $entry = Activity::query()->latest('id')->first();
        $this->assertTrue($entry->causer->is($user));
    }

    #[Test]
    public function qualification_changes_are_recorded(): void
    {
        // Who may certify what is exactly the sort of change an audit asks
        // about later.
        $user = User::factory()->create();

        $qualification = Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.12345',
            'valid_from' => now()->toDateString(),
        ]);

        $qualification->update(['valid_until' => now()->addYear()->toDateString()]);

        $entries = Activity::query()->where('log_name', 'core')->get();

        $this->assertCount(2, $entries);
        $this->assertSame(
            'DE.66.12345',
            $entries->first()->attribute_changes['attributes']['reference'],
        );
    }

    #[Test]
    public function entries_carry_a_timestamp(): void
    {
        // The legacy system's log tables had no timestamp column at all, which
        // is what made them useless as an audit trail.
        StorageLocation::create(['name' => 'Halle']);

        $this->assertNotNull(Activity::sole()->created_at);
    }
}
