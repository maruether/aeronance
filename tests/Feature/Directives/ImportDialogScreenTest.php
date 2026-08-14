<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Access\AccessSetup;
use App\Models\User;
use App\Modules\Directives\Filament\Resources\Directives\Pages\ListDirectives;
use App\Modules\Directives\Models\Directive;
use App\Modules\Directives\Permissions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RendersModulePages;
use Tests\TestCase;

/**
 * Der Import-Dialog, wirklich abgesendet.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Feldtest beim DR300-Abruf: Der Import lief durch, und DANACH riss die
 * Erfolgsmeldung die Seite ab -- __() bekam das ganze Ergebnis-Array samt
 * Listen (rows, collisions), der Übersetzer ruft ucfirst() auf jeden Wert,
 * TypeError, "Beim Laden dieser Seite ist ein Fehler aufgetreten". Kein
 * Aktionstest hatte den Dialog je abgesendet -- der Fehler wohnte NACH der
 * Fachlogik, im letzten Schritt vor dem Nutzer.
 * ─────────────────────────────────────────────────────────────────────────────
 */
#[Group('rendering')]
final class ImportDialogScreenTest extends TestCase
{
    use RendersModulePages;

    /** @return list<string> */
    protected function modulesUnderTest(): array
    {
        return ['fleet', 'directives'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootWithModules();

        app(AccessSetup::class)->run();
    }

    #[Test]
    public function a_pasted_list_imports_and_the_success_notification_survives(): void
    {
        $this->actingAs($this->manager());

        Livewire::test(ListDirectives::class)
            ->callAction('import', data: [
                'source' => 'csv',
                'body' => "TM 300/12;Höhenruderanlenkung prüfen\nSB 090702;Installation of battery",
                'model' => 'ASK 21',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame(2, Directive::query()->count());

        // Und die Art kam aus der Nummer, nicht aus einem Pflichtfeld.
        $this->assertSame('tm', Directive::query()->where('number', 'TM 300/12')->firstOrFail()->kind->value);
        $this->assertSame('sb', Directive::query()->where('number', 'SB 090702')->firstOrFail()->kind->value);
    }

    private function manager(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::DIRECTIVES_MANAGE, Permissions::DIRECTIVES_VIEW);

        return $user->fresh();
    }
}
