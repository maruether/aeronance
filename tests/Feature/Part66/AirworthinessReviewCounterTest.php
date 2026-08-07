<?php

declare(strict_types=1);

namespace Tests\Feature\Part66;

use App\Core\Access\AccessSetup;
use App\Core\Models\Qualification;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AirworthinessReview;
use App\Modules\Part66\Support\ExperienceLog;
use App\Modules\Part66\Support\RecencyReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The ARS counter -- CLAUDE.md's "Lizenz-/ARS-Zähler".
 *
 * the reading: the number of ARCs and the hours behind them over two years,
 * as an overview for somebody holding a Part-66 licence. Figures, no verdict --
 * what number keeps an ARS qualification alive is not decided here.
 */
final class AirworthinessReviewCounterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function reviews_a_person_issued_are_their_own_record(): void
    {
        // The third kind of act in this module: working on an aircraft, releasing
        // work, and reviewing airworthiness are three different things, and only
        // the last keeps an ARS qualification alive.
        $reviewer = $this->reviewer();

        $this->arc($reviewer, 'D-KABC', now()->subMonths(3));
        $this->arc($reviewer, 'D-KXYZ', now()->subMonths(8));

        $reviews = app(ExperienceLog::class)->reviewsBy($reviewer);

        $this->assertCount(2, $reviews);
        $this->assertSame('D-KABC', $reviews->last()->aircraft->registration);
    }

    #[Test]
    public function the_recency_window_counts_them(): void
    {
        $reviewer = $this->reviewer();

        $this->arc($reviewer, 'D-KABC', now()->subMonths(6));
        $this->arc($reviewer, 'D-KXYZ', now()->subMonths(20));

        // Outside the two years -- must not count.
        $this->arc($reviewer, 'D-KOLD', now()->subMonths(30));

        $this->assertSame(2, app(RecencyReport::class)->for($reviewer)['reviews']);
    }

    #[Test]
    public function an_expired_certificate_still_counts_as_an_act_of_review(): void
    {
        // Same reasoning as superseded releases: this is an experience record,
        // not a validity record. An ARC that has since run out was still a review
        // somebody carried out.
        $reviewer = $this->reviewer();

        AirworthinessReview::create([
            'aircraft_id' => $this->aircraft('D-KABC')->id,
            'issued_at' => now()->subMonths(18)->toDateString(),
            'valid_until' => now()->subMonths(6)->toDateString(),  // expired
            'user_id' => $reviewer->id,
            'issued_by_name' => $reviewer->name,
        ]);

        $this->assertSame(1, app(RecencyReport::class)->for($reviewer)['reviews']);
    }

    #[Test]
    public function somebody_elses_reviews_are_not_counted(): void
    {
        $reviewer = $this->reviewer();
        $other = $this->reviewer();

        $this->arc($other, 'D-KABC', now()->subMonth());

        $this->assertSame(0, app(RecencyReport::class)->for($reviewer)['reviews']);
        $this->assertCount(0, app(ExperienceLog::class)->reviewsBy($reviewer));
    }

    #[Test]
    public function the_printed_sheet_lists_registration_and_date(): void
    {
        // More use with the registrations on it than as a bare number.
        app(AccessSetup::class)->run();
        app(ModuleManager::class)->enable('part66');
        app(ModuleManager::class)->forgetCache();

        $reviewer = $this->reviewer();
        $this->arc($reviewer, 'D-KABC', now()->subMonths(2), 'ARC-2026-007');

        $this->actingAs($reviewer)
            ->get(route('part66.log'))
            ->assertSuccessful()
            ->assertSee('Lufttüchtigkeitsprüfungen', false)
            ->assertSee('D-KABC')
            ->assertSee('ARC-2026-007');
    }

    private function aircraft(string $registration): Aircraft
    {
        return Aircraft::firstOrCreate(
            ['registration' => $registration],
            ['model' => 'ASK 21'],
        );
    }

    private function arc(User $by, string $registration, $issuedAt, ?string $reference = null): AirworthinessReview
    {
        return AirworthinessReview::create([
            'aircraft_id' => $this->aircraft($registration)->id,
            'certificate_reference' => $reference,
            'issued_at' => $issuedAt->toDateString(),
            'valid_until' => $issuedAt->copy()->addYear()->toDateString(),
            'user_id' => $by->id,
            'issued_by_name' => $by->name,
        ]);
    }

    private function reviewer(): User
    {
        $user = User::factory()->create(['is_active' => true]);

        Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.'.$user->id,
            'category' => 'B1',
            'valid_from' => now()->subYears(3)->toDateString(),
        ]);

        return $user->fresh();
    }
}
