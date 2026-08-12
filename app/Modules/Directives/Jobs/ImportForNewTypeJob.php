<?php

declare(strict_types=1);

namespace App\Modules\Directives\Jobs;

use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Directives\Actions\ImportDirectives;
use App\Modules\Directives\Sources\UnknownType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Eine Herstellerliste fuer ein frisch angelegtes Muster anziehen.
 *
 * Als Job, weil dahinter fremde Server stehen (eine Quelle kann Dutzende
 * Seiten laden) -- das gehoert nicht in den Klick, der das Muster anlegt.
 * UnknownType ist hier KEIN Fehler: Die Quelle passt zum Hersteller, fuehrt
 * dieses eine Muster aber nicht -- dieselbe stille Antwort wie im
 * Sonntagslauf. Kein Retry: Der Sonntagslauf kommt ohnehin.
 */
final class ImportForNewTypeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public string $sourceName,
        public string $designation,
        public int $userId,
    ) {}

    public function handle(): void
    {
        if (! app(ModuleManager::class)->isEnabled('directives')) {
            return;
        }

        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        try {
            app(ImportDirectives::class)->fromSource(
                $this->sourceName,
                $user,
                ['model' => $this->designation],
            );
        } catch (UnknownType) {
            // Die Quelle fuehrt dieses Muster nicht -- keine Meldung wert.
        }
    }
}
