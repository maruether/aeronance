<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules;

use App\Core\Access\PermissionDefinition;
use App\Core\Modules\Contracts\AeronanceModule;
use App\Core\Modules\Manifest;
use Filament\Panel;

/**
 * Depends on Alpha -- the "task cards need the fleet" shape.
 */
final class BetaModule implements AeronanceModule
{
    public function getId(): string
    {
        return 'beta';
    }

    public function manifest(): Manifest
    {
        return new Manifest(
            name: 'beta',
            version: '1.0.0',
            title: 'Beta',
            description: 'Baut auf Alpha auf.',
            requires: ['alpha'],
        );
    }

    /** @return list<PermissionDefinition> */
    public function permissions(): array
    {
        return [new PermissionDefinition('beta.view', 'beta')];
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}
}
