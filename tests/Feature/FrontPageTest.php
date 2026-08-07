<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Access\AccessSetup;
use App\Core\Access\CoreRoles;
use App\Core\Setup\InstallationState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Replaces Laravel's stock ExampleTest, which asserted that "/" returns 200.
 * It no longer does, and correctly so: an unfinished installation belongs in
 * the wizard, and a finished one in the panel.
 */
final class FrontPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_unfinished_installation_is_sent_to_the_wizard(): void
    {
        $this->removeMarker();

        $this->get('/')->assertRedirect(route('setup.index'));
    }

    #[Test]
    public function a_finished_installation_is_sent_to_the_panel(): void
    {
        app(AccessSetup::class)->run();
        User::factory()->create()->assignRole(CoreRoles::ADMIN);
        app(InstallationState::class)->markInstalled();

        try {
            $this->get('/')->assertRedirect('/verwaltung');
        } finally {
            $this->removeMarker();
        }
    }

    #[Test]
    public function the_panel_requires_a_login(): void
    {
        $this->get('/verwaltung')->assertRedirect('/verwaltung/login');
    }

    private function removeMarker(): void
    {
        $path = app(InstallationState::class)->markerPath();

        if (file_exists($path)) {
            unlink($path);
        }
    }
}
