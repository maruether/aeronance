<?php

declare(strict_types=1);

namespace App\Modules\Vereinsflieger;

use App\Core\Access\PermissionDefinition;
use App\Core\Identity\IdentityProviderRegistry;
use App\Core\Modules\Contracts\AeronanceModule;
use App\Core\Modules\Manifest;
use Filament\Panel;

/**
 * Vereinsflieger als Identitätsquelle.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE ZUORDNUNG VON FUNKTIONEN AUF ROLLEN LIEGT IM KERN, weil sie für jeden
 * Provider gleich abläuft: LDAP hätte sonst seine eigene Zuordnungsmaske, OIDC
 * eine dritte, und spätestens beim zweiten Provider gäbe es zwei Orte, an denen
 * Rechte vergeben werden.
 *
 * EINEN eigenen Bildschirm gibt es trotzdem -- die Mitgliedsstatus. Der ist
 * keine Rollenfrage, sondern die Frage davor: Bekommt dieser Status überhaupt
 * Konten? Mitgliedsstatus sind eine Eigenheit von Vereinsflieger; ein LDAP hat
 * keine. Deshalb gehört diese eine Seite hierher und nicht in den Kern.
 *
 * Ist dieses Modul aus, verschwindet der Connector aus der Registry. Der Kern
 * blendet die Zuordnungen dann selbst aus (RoleMappingResource) -- vorhandene
 * Zeilen bleiben in der Datenbank stehen, denn Abschalten ist kein
 * Deinstallieren.
 *
 * KEINE EIGENEN RECHTE: Wer Zuordnungen pflegen darf, entscheidet
 * core.roles.manage. Ein modul-eigenes Recht dafür wäre ein zweiter Schalter für
 * dieselbe Tür.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class VereinsfliegerModule implements AeronanceModule
{
    public function getId(): string
    {
        return 'vereinsflieger';
    }

    public function manifest(): Manifest
    {
        return new Manifest(
            name: 'vereinsflieger',
            version: '0.1.0',
            title: __('vereinsflieger.module.title'),
            description: __('vereinsflieger.module.description'),
            provides: ['identity-provider'],
        );
    }

    /** @return list<PermissionDefinition> */
    public function permissions(): array
    {
        return [];
    }

    public function register(Panel $panel): void
    {
        app(IdentityProviderRegistry::class)->register(new VereinsfliegerProvider);

        /*
         * EIN Bildschirm, und er hat einen Grund: Die Frage „bekommt dieser
         * Mitgliedsstatus ueberhaupt Konten" ist keine Rollenfrage, sondern
         * eine Eigenheit von Vereinsflieger. Ein LDAP hat keine Mitgliedsstatus.
         * Die Rollenzuordnung selbst bleibt im Kern.
         */
        $panel
            ->discoverPages(
                in: __DIR__.'/Filament/Pages',
                for: 'App\\Modules\\Vereinsflieger\\Filament\\Pages',
            )
            ->discoverResources(
                in: __DIR__.'/Filament/Resources',
                for: 'App\\Modules\\Vereinsflieger\\Filament\\Resources',
            );
    }

    public function boot(Panel $panel): void {}
}
