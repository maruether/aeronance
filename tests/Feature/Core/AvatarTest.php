<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Filament\Auth\EditProfile;
use App\Core\Filament\InitialsAvatarProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Das Profilbild -- Initialen statt Fremddienst, Upload statt Sackgasse.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Der Anlass von test.aeronance.de: "ich kann keinen setzen und seh dort ein
 * 'nicht gefunden' bild." Filaments Vorgabe laedt Platzhalter von
 * ui-avatars.com; die eigene CSP blockt den Fremddienst (zu Recht), also
 * stand neben jedem Konto ein kaputtes Bild -- und einen Weg, ein eigenes zu
 * setzen, gab es nicht.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class AvatarTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_placeholder_is_drawn_locally(): void
    {
        $bild = (new InitialsAvatarProvider)->get(
            User::factory()->make(['name' => 'Marvin Rüther']),
        );

        $this->assertStringStartsWith(
            'data:image/svg+xml',
            $bild,
            'Der Platzhalter muss als data-URI entstehen -- jede fremde Adresse blockt die CSP.',
        );
        $this->assertStringContainsString(rawurlencode('MR'), $bild);
    }

    #[Test]
    public function without_an_uploaded_picture_the_panel_gets_no_url(): void
    {
        $user = User::factory()->create();

        $this->assertNull(
            $user->getFilamentAvatarUrl(),
            'Ohne Bild entscheidet der InitialsAvatarProvider -- nicht eine tote Adresse.',
        );
    }

    #[Test]
    public function an_uploaded_picture_is_served_only_to_signed_in_members(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        // Ein echtes 1x1-PNG, ohne GD-Abhaengigkeit im Test.
        $pfad = tempnam(sys_get_temp_dir(), 'avatar');
        file_put_contents($pfad, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        ));

        $user->addMedia($pfad)
            ->usingFileName('gesicht.png')
            ->toMediaCollection(User::AVATAR);

        $url = $user->fresh()->getFilamentAvatarUrl();
        $this->assertNotNull($url);

        // Nicht angemeldet: kein Bild. Die Disk ist privat, die Route ist der
        // einzige Weg -- und sie verlangt eine Sitzung.
        $this->get($url)->assertRedirect();

        $betrachter = User::factory()->create(['is_active' => true]);
        $this->actingAs($betrachter);

        $this->get($url)->assertSuccessful();
    }

    #[Test]
    public function the_profile_page_offers_the_upload(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true]));

        Livewire::test(EditProfile::class)
            ->assertSuccessful()
            ->assertSee(__('users.field.avatar'));
    }
}
