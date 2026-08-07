<?php

declare(strict_types=1);

namespace App\Modules\Part66;

use App\Core\Access\CoreRoles;
use App\Core\Access\PermissionDefinition;
use App\Core\Modules\Contracts\AeronanceModule;
use App\Core\Modules\Manifest;
use Filament\Panel;

/**
 * The Part-66 experience log.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THIS WAS THE ORIGINAL REQUEST. Vorgabe: "ich habe ein halbfertiges lagertool und
 * will einen besseren weg mein part 66 log zu führen."
 *
 * Everything else -- warehouse, fleet, task cards -- turned out to be the
 * scaffolding needed to derive this cleanly. Which is why it is the smallest
 * module in the project: it adds no tables and writes nothing. All of the work
 * was done earlier, by putting the Part-66 fields on the very first card instead
 * of adding them once there was a year of cards without them.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class Part66Module implements AeronanceModule
{
    public function getId(): string
    {
        return 'part66';
    }

    public function manifest(): Manifest
    {
        return new Manifest(
            name: 'part66',
            version: '0.1.0',
            title: __('part66.module.title'),
            description: __('part66.module.description'),
            // fleet is declared explicitly now, not merely inherited through
            // taskcards: this module reads airworthiness_reviews directly, and a
            // dependency you rely on belongs in the manifest whether or not
            // something else happens to pull it in.
            requires: ['taskcards', 'fleet'],
        );
    }

    /**
     * One permission, and it goes to the workshop leadership by default.
     *
     * the call, and the right one: reading somebody else's experience log is
     * exactly what a workshop manager has to do to confirm their experience, and
     * exactly what nobody else has business doing. Note what is NOT here -- no
     * permission for reading your own, because that needs none.
     *
     * @return list<PermissionDefinition>
     */
    public function permissions(): array
    {
        return PermissionDefinition::fromGroupsWithRoles([
            'part66.logs' => [
                CoreRoles::WORKSHOP_MANAGER => [
                    Permissions::LOGS_VIEW_ALL,
                ],
            ],
        ]);
    }

    public function register(Panel $panel): void
    {
        $panel->discoverPages(
            in: __DIR__.'/Filament/Pages',
            for: 'App\\Modules\\Part66\\Filament\\Pages',
        );
    }

    public function boot(Panel $panel): void {}
}
