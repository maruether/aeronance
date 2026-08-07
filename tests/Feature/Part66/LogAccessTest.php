<?php

declare(strict_types=1);

namespace Tests\Feature\Part66;

use App\Core\Access\AccessSetup;
use App\Core\Models\Qualification;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Part66\Filament\Pages\ExperienceLogPage;
use App\Modules\Part66\Permissions;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Enums\ParticipationKind;
use App\Modules\TaskCards\Permissions as CardPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who may read whose log.
 *
 * The split is unusual for this project and deliberate: your own log needs no
 * permission -- it is a record of how you spent your own Saturdays, and having to
 * be granted access to that would be absurd. Reading somebody else's is personal
 * data and needs one.
 */
final class LogAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();
        app(ModuleManager::class)->enable('part66');
        app(ModuleManager::class)->forgetCache();
    }

    #[Test]
    public function anybody_logged_in_may_read_their_own(): void
    {
        $this->actingAs($this->plainMember());

        $this->assertTrue(ExperienceLogPage::canAccess());

        $this->get(route('part66.log'))->assertSuccessful();
    }

    #[Test]
    public function nobody_reads_somebody_elses_without_the_permission(): void
    {
        $other = $this->plainMember();

        $this->actingAs($this->plainMember())
            ->get(route('part66.log', ['person' => $other->id]))
            ->assertForbidden();
    }

    #[Test]
    public function a_workshop_manager_may(): void
    {
        $other = $this->plainMember();

        $this->actingAs($this->userWith(Permissions::LOGS_VIEW_ALL))
            ->get(route('part66.log', ['person' => $other->id]))
            ->assertSuccessful()
            ->assertSee($other->name);
    }

    #[Test]
    public function a_tampered_parameter_shows_your_own_log_not_an_error(): void
    {
        // On the screen, where a Livewire property is trivially editable. Falling
        // back to your own log is both safe and less confusing than a refusal --
        // and it can never show somebody else's.
        $me = $this->plainMember();
        $other = $this->plainMember();

        $this->actingAs($me);

        $page = Livewire::test(ExperienceLogPage::class)->set('personId', $other->id);

        $this->assertSame($me->id, $page->instance()->person()->id);
    }

    #[Test]
    public function the_printed_sheet_carries_the_licence_and_the_recency_figures(): void
    {
        // The actual deliverable of the original request: something to hand to an
        // authority, with the person's name and licence on it.
        $mechanic = $this->userWith(CardPermissions::CARDS_WORK, CardPermissions::WORK_ORDERS_MANAGE);

        Qualification::create([
            'user_id' => $mechanic->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.98765',
            'category' => 'B1',
            'valid_from' => now()->subYears(2)->toDateString(),
        ]);

        $aircraft = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);
        $order = app(ManageWorkOrder::class)->open($aircraft, 'Jahresnachprüfung', $mechanic->fresh());
        $card = app(ManageWorkOrder::class)->addCard($order, 'Ölwechsel');
        app(ManageWorkOrder::class)->recordTime($card, $mechanic->fresh(), 90, ParticipationKind::Executed);

        $this->actingAs($mechanic->fresh())
            ->get(route('part66.log'))
            ->assertSuccessful()
            ->assertSee('Erfahrungsnachweis nach Part-66', false)
            ->assertSee('DE.66.98765')
            ->assertSee('D-KABC')
            ->assertSee('1:30')
            // And it says the recency figures are figures, not a verdict.
            ->assertSee('nicht entschieden', false);
    }

    #[Test]
    public function nothing_is_served_while_the_module_is_off(): void
    {
        app(ModuleManager::class)->disable('part66');
        app(ModuleManager::class)->forgetCache();

        $this->actingAs($this->plainMember())
            ->get(route('part66.log'))
            ->assertNotFound();
    }

    private function plainMember(): User
    {
        return User::factory()->create(['is_active' => true])->fresh();
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }
}
