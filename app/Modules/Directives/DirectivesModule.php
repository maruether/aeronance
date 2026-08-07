<?php

declare(strict_types=1);

namespace App\Modules\Directives;

use App\Core\Access\PermissionDefinition;
use App\Core\Modules\Contracts\AeronanceModule;
use App\Core\Modules\Manifest;
use Filament\Panel;

/**
 * LTA/TM -- the line-by-line overview an operator confirms.
 *
 * Vorgabe: "da geht es vor allem um die Übersicht die ich dann bestätigen kann
 * (zeile für zeile). Die Übersichtsliste ändert sich herstellerseitig nicht oder
 * wird länger. daher sind abgehakte punkte so lange abgehakt bis ihre laufzeit
 * kickt."
 *
 * Requires the fleet, because a directive without aircraft to apply it to is a
 * document nobody needs a database for. Task cards are OPTIONAL: a compliance can
 * point at a card when that module is enabled, and works as a plain tick when it
 * is not.
 */
final class DirectivesModule implements AeronanceModule
{
    public function getId(): string
    {
        return 'directives';
    }

    public function manifest(): Manifest
    {
        return new Manifest(
            name: 'directives',
            version: '0.1.0',
            title: __('directives.module.title'),
            description: __('directives.module.description'),
            requires: ['fleet'],
        );
    }

    /** @return list<PermissionDefinition> */
    public function permissions(): array
    {
        return PermissionDefinition::fromGroups([
            'directives' => [
                Permissions::DIRECTIVES_VIEW,
                Permissions::DIRECTIVES_MANAGE,
                Permissions::DIRECTIVES_ASSESS,
            ],
        ]);
    }

    public function register(Panel $panel): void
    {
        $panel
            ->discoverResources(
                in: __DIR__.'/Filament/Resources',
                for: 'App\\Modules\\Directives\\Filament\\Resources',
            )
            /*
             * The pages were missing here, and every one of them was
             * unreachable because of it: the aircraft overview an inspection
             * works down, the manufacturer credentials, the general-notes list.
             * They existed, they were tested, and no navigation led anywhere
             * near them.
             *
             * Every other module discovers both. This one discovered resources
             * only -- a single missing call, invisible in tests because a test
             * instantiates a page class directly and never asks the panel for a
             * route to it.
             */
            ->discoverPages(
                in: __DIR__.'/Filament/Pages',
                for: 'App\\Modules\\Directives\\Filament\\Pages',
            );
    }

    public function boot(Panel $panel): void
    {
        /*
         * NOTHING module-wide is registered here any more, and that is the
         * point.
         *
         * The manufacturer sources and the airworthiness contribution both used
         * to live in this method. A Filament plugin's boot() runs for a browser
         * request and for nothing else, so the weekly refresh found no sources
         * and an aircraft asked outside a request reported no open items. Both
         * now live in the container, where every caller reaches them.
         */
    }
}
