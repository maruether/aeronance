<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Core\Access\Authority;
use App\Core\Models\Qualification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Decision E8: a permission says what someone may operate, a qualification says
 * what they may answer for. Both are needed for the acts that carry
 * airworthiness consequences.
 *
 * These are the negative tests the security guardrails ask for: every case here
 * is one where the system must say no.
 */
final class AuthorityTest extends TestCase
{
    use RefreshDatabase;

    private Authority $authority;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authority = new Authority;

        foreach (['stock.issue', 'stock.scrap', 'releases.issue'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    #[Test]
    public function an_ordinary_action_needs_only_the_permission(): void
    {
        $user = $this->userWith('stock.issue');

        $this->assertTrue($this->authority->permits($user, 'stock.issue'));
        $this->assertTrue($this->authority->certifies($user, 'stock.issue'));
    }

    #[Test]
    public function without_the_permission_nothing_is_allowed(): void
    {
        $user = $this->userWith();

        $this->assertFalse($this->authority->permits($user, 'stock.issue'));
    }

    #[Test]
    public function a_deactivated_account_may_do_nothing_even_with_the_permission(): void
    {
        $user = $this->userWith('stock.issue');
        $user->update(['is_active' => false]);

        $this->assertFalse($this->authority->permits($user->fresh(), 'stock.issue'));
    }

    #[Test]
    public function scrapping_a_part_needs_a_qualification_on_top_of_the_permission(): void
    {
        // The permission alone is not enough: declaring a part unsalvageable
        // takes it out of service for good and is reserved for Part-66 staff.
        $user = $this->userWith('stock.scrap');

        $this->assertTrue($this->authority->permits($user, 'stock.scrap'));
        $this->assertFalse($this->authority->certifies($user, 'stock.scrap'));
    }

    #[Test]
    public function scrapping_is_allowed_with_a_valid_part66_licence(): void
    {
        $user = $this->userWith('stock.scrap');
        $this->giveQualification($user, Qualification::TYPE_PART66);

        $this->assertTrue($this->authority->certifies($user, 'stock.scrap'));
    }

    #[Test]
    public function an_expired_licence_does_not_cover_the_act(): void
    {
        $user = $this->userWith('stock.scrap');
        $this->giveQualification(
            $user,
            Qualification::TYPE_PART66,
            validFrom: now()->subYears(5)->toDateString(),
            validUntil: now()->subDay()->toDateString(),
        );

        $this->assertFalse(
            $this->authority->certifies($user, 'stock.scrap'),
            'A licence that lapsed yesterday must not cover an act today.',
        );
    }

    #[Test]
    public function a_licence_that_has_not_started_yet_does_not_cover_the_act(): void
    {
        $user = $this->userWith('stock.scrap');
        $this->giveQualification(
            $user,
            Qualification::TYPE_PART66,
            validFrom: now()->addWeek()->toDateString(),
        );

        $this->assertFalse($this->authority->certifies($user, 'stock.scrap'));
    }

    #[Test]
    public function a_licence_without_an_end_date_keeps_applying(): void
    {
        $user = $this->userWith('stock.scrap');
        $this->giveQualification($user, Qualification::TYPE_PART66, validUntil: null);

        $this->assertTrue($this->authority->certifies($user, 'stock.scrap'));
    }

    #[Test]
    public function a_pilot_owner_may_release_only_the_aircraft_they_are_entered_for(): void
    {
        // The heart of E8: the same person is authorised for one aircraft and
        // not for another. A role model that only knows "has role X" cannot say
        // this -- which is why qualifications are a separate concept.
        $user = $this->userWith('releases.issue');
        $this->giveQualification($user, Qualification::TYPE_PILOT_OWNER, scope: 'D-KABC');

        $this->assertTrue($this->authority->certifiesFor($user, 'releases.issue', 'D-KABC'));
        $this->assertFalse($this->authority->certifiesFor($user, 'releases.issue', 'D-KXYZ'));
    }

    #[Test]
    public function a_part66_licence_is_not_tied_to_one_aircraft(): void
    {
        $user = $this->userWith('releases.issue');
        $this->giveQualification($user, Qualification::TYPE_PART66);

        $this->assertTrue($this->authority->certifiesFor($user, 'releases.issue', 'D-KABC'));
        $this->assertTrue($this->authority->certifiesFor($user, 'releases.issue', 'D-KXYZ'));
    }

    #[Test]
    public function the_wrong_kind_of_qualification_does_not_help(): void
    {
        // A pilot-owner authorisation does not permit scrapping a part.
        $user = $this->userWith('stock.scrap');
        $this->giveQualification($user, Qualification::TYPE_PILOT_OWNER, scope: 'D-KABC');

        $this->assertFalse($this->authority->certifies($user, 'stock.scrap'));
    }

    #[Test]
    public function it_returns_the_qualification_that_was_relied_upon(): void
    {
        // Needed for the record: without type, number and validity at the time,
        // it cannot later be established whether the act was covered. See E7.
        $user = $this->userWith('stock.scrap');
        $this->giveQualification($user, Qualification::TYPE_PART66, reference: 'DE.66.12345');

        $qualification = $this->authority->qualificationFor($user, 'stock.scrap');

        $this->assertNotNull($qualification);
        $this->assertSame('DE.66.12345', $qualification->reference);

        $snapshot = $qualification->toSnapshot();
        $this->assertSame(Qualification::TYPE_PART66, $snapshot['qualification_type']);
        $this->assertSame('DE.66.12345', $snapshot['qualification_reference']);
    }

    #[Test]
    public function it_returns_nothing_when_the_permission_is_missing(): void
    {
        $user = $this->userWith();
        $this->giveQualification($user, Qualification::TYPE_PART66);

        $this->assertNull($this->authority->qualificationFor($user, 'stock.scrap'));
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function giveQualification(
        User $user,
        string $type,
        ?string $scope = null,
        ?string $reference = 'TEST-1',
        ?string $validFrom = null,
        ?string $validUntil = null,
    ): Qualification {
        return Qualification::create([
            'user_id' => $user->id,
            'type' => $type,
            'reference' => $reference,
            'scope' => $scope,
            'valid_from' => $validFrom ?? now()->subYear()->toDateString(),
            'valid_until' => $validUntil,
        ]);
    }
}
