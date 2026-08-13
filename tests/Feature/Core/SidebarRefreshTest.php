<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Access\AccessSetup;
use App\Core\Access\CoreRoles;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Die Seitenleiste erneuert sich selbst -- ohne Skript, ohne CSP-Loch.
 *
 * Feldtest: "Sidebar aktualisiert nur bei klick, sollte automatisch
 * geschehen." Die Lösung ist ein `wire:poll`-Element, das per Render-Hook in
 * Filaments Sidebar-Livewire-Komponente hängt. Diese Tests halten zweierlei
 * fest: Das Element ist da, wo die Seitenleiste ist -- und NUR dort. Auf der
 * Anmeldeseite gibt es keine Seitenleiste und darf auch kein Poller sein,
 * sonst fragte ein abgemeldeter Browser alle dreißig Sekunden ins Leere.
 */
final class SidebarRefreshTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_sidebar_carries_the_poll_element(): void
    {
        app(AccessSetup::class)->run();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(CoreRoles::ADMIN);

        $this->actingAs($admin->fresh())
            ->get('/verwaltung')
            ->assertOk()
            ->assertSee('wire:poll.30s', escape: false);
    }

    #[Test]
    public function the_login_page_does_not_poll(): void
    {
        $this->get('/verwaltung/login')
            ->assertOk()
            ->assertDontSee('wire:poll.30s', escape: false);
    }
}
