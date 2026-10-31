<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Access\CoreRoles;
use App\Core\Access\PermissionDefinition;
use App\Core\Modules\Contracts\AeronanceModule;
use App\Core\Modules\Manifest;
use Filament\Panel;

/**
 * Abrechnung -- Kunden, Preise, Rechnungen. Für den gewerblichen Betrieb.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ES DOCKT AN DEN ARBEITSKARTEN AN, NICHT AM LAGER. E6 sagt „Lagerhaltung,
 * keine Warenwirtschaft", und das gilt weiter: Das Lager kennt keinen
 * Verkaufspreis. Abgerechnet wird auch kein Teil, sondern ein VORGANG -- die
 * Arbeit an einem Luftfahrzeug mit Zeiten, verbauten Teilen und
 * Fremdleistung. Das alles steht auf den Karten; die Rechnung ist eine
 * Auswertung des freigegebenen Vorgangs, so wie das Erfahrungslogbuch eine
 * Auswertung der Karten ist. Begründung und Entwurf: docs/ABRECHNUNG.md.
 *
 * `requires` nennt die Flotte ausdrücklich, obwohl die Arbeitskarten sie
 * ohnehin mitbringen: Ein Kunde kann an einen Halter geknüpft sein, und die
 * Modulgrenze zählt direkte Nennungen, nicht transitive.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE RECHNUNG IST NACH DEM STELLEN EINGEFROREN -- dieselbe Regel wie bei der
 * Freigabe, und aus demselben Grund: Ein Beleg, der sich nachträglich ändern
 * lässt, ist keiner (GoBD). Korrektur ist ein neues Dokument, das auf das
 * alte zeigt. Die Nummer entsteht erst beim Stellen, damit verworfene
 * Entwürfe keine Lücke reißen.
 */
final class BillingModule implements AeronanceModule
{
    public function getId(): string
    {
        return 'billing';
    }

    public function manifest(): Manifest
    {
        return new Manifest(
            name: 'billing',
            version: '0.1.0',
            title: __('billing.module.title'),
            description: __('billing.module.description'),
            requires: ['taskcards', 'fleet'],
        );
    }

    /**
     * Alles an den Werkstattleiter, nichts an den Administrator: Verwaltung ist
     * keine Fachrolle. Eine Werft legt sich eine Rolle „Buchhaltung" an.
     *
     * @return list<PermissionDefinition>
     */
    public function permissions(): array
    {
        return PermissionDefinition::fromGroupsWithRoles([
            'billing' => [
                CoreRoles::WORKSHOP_MANAGER => [
                    Permissions::VIEW,
                    Permissions::MANAGE,
                    Permissions::ISSUE,
                    Permissions::PAYMENTS,
                ],
            ],
        ]);
    }

    public function register(Panel $panel): void
    {
        $panel
            ->discoverResources(
                in: __DIR__.'/Filament/Resources',
                for: 'App\\Modules\\Billing\\Filament\\Resources',
            )
            ->discoverWidgets(
                in: __DIR__.'/Filament/Widgets',
                for: 'App\\Modules\\Billing\\Filament\\Widgets',
            );
    }

    public function boot(Panel $panel): void {}
}
