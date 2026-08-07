<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules;

use App\Core\Access\PermissionDefinition;
use App\Core\Modules\Contracts\AeronanceModule;
use App\Core\Modules\Manifest;
use Filament\Panel;

/**
 * A standalone module, used to exercise the config-driven path end to end.
 */
final class AlphaModule implements AeronanceModule
{
    public function getId(): string
    {
        return 'alpha';
    }

    public function manifest(): Manifest
    {
        return new Manifest(
            name: 'alpha',
            version: '1.0.0',
            title: 'Alpha',
            description: 'Ein eigenständiges Modul ohne Abhängigkeiten.',
        );
    }

    /** @return list<PermissionDefinition> */
    public function permissions(): array
    {
        return [new PermissionDefinition('alpha.view', 'alpha')];
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}
}
