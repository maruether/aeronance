<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Directives\Jobs\ImportForNewTypeJob;
use App\Modules\Directives\Permissions;
use App\Modules\Fleet\Models\AircraftType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ein neues Muster zieht seine Herstellerlisten an -- herstellergebunden.
 *
 * Feldtest: "Liste sollte automatisch zu einem angelegten Muster gezogen
 * werden, ohne user interaktion." Freigegebene Strategie: an den Hersteller
 * binden -- und der Praezedenzfall ist wortwoertlich vorgegeben: "wenn an
 * den hersteller binden so funktioniert das bei Robin auch ceapr abgefragt
 * wird ok." Genau das prueft der erste Test.
 */
final class AutoFetchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(ModuleManager::class)->enable('fleet');
        app(ModuleManager::class)->enable('directives');
        app(ModuleManager::class)->forgetCache();
        app(AccessSetup::class)->run();

        Queue::fake();
    }

    #[Test]
    public function a_robin_type_reaches_the_ceapr_source(): void
    {
        $this->actingAs($this->manager());

        AircraftType::create(['designation' => 'DR 400/180', 'manufacturer' => 'Robin']);

        Queue::assertPushed(
            ImportForNewTypeJob::class,
            fn (ImportForNewTypeJob $job): bool => $job->sourceName === 'ceapr'
                && $job->designation === 'DR 400/180',
        );
    }

    #[Test]
    public function an_unrelated_manufacturer_stays_quiet(): void
    {
        $this->actingAs($this->manager());

        AircraftType::create(['designation' => 'ASK 21', 'manufacturer' => 'Schleicher']);

        Queue::assertNotPushed(
            ImportForNewTypeJob::class,
            fn (ImportForNewTypeJob $job): bool => $job->sourceName === 'ceapr',
        );
    }

    #[Test]
    public function without_the_import_permission_the_fetch_is_deferred(): void
    {
        // Die Quelle passt, aber der Import liefe unter einem Namen, der ihn
        // nicht ausfuehren duerfte -- kein Job, der Sonntagslauf holt die
        // Liste regulaer. (Der Zugangsdaten-Fall laesst sich hier nicht
        // umgebungsfest pruefen: Auf dem Entwicklungsrechner liegen echte
        // Schempp-Zugangsdaten in der .env, und dann ist der Job RICHTIG.)
        $ohneRecht = User::factory()->create(['is_active' => true]);
        $this->actingAs($ohneRecht->fresh());

        AircraftType::create(['designation' => 'DR 400/180', 'manufacturer' => 'Robin']);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function without_a_signed_in_user_nothing_is_fetched(): void
    {
        // Konsole und Import legen Muster ohne Person an -- der Sonntagslauf
        // holt die Listen dann regulaer.
        AircraftType::create(['designation' => 'DR 400/180', 'manufacturer' => 'Robin']);

        Queue::assertNothingPushed();
    }

    private function manager(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::DIRECTIVES_MANAGE);

        return $user->fresh();
    }
}
