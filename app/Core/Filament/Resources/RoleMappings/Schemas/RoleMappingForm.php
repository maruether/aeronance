<?php

declare(strict_types=1);

namespace App\Core\Filament\Resources\RoleMappings\Schemas;

use App\Core\Access\CoreRoles;
use App\Core\Identity\DiscoversGroups;
use App\Core\Identity\ExternalGroup;
use App\Core\Identity\IdentityProvider;
use App\Core\Identity\IdentityProviderRegistry;
use App\Core\Identity\RoleMapping;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Role;

/**
 * Eine Zuordnung anlegen: externe Gruppe -> interne Rolle.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE AUSWAHL KOMMT AUS DEM VEREIN, NICHT AUS DEM CODE.
 *
 * Vorgabe: „es geht bei der zuordnung um die funktionen die der verein angelegt
 * hat. die ui muss entsprechend dynamisch sein."
 *
 * Also keine eingebaute Liste. Angeboten wird, was beim Provider tatsaechlich
 * gefunden wurde -- und wo nichts gefunden wurde, sagt das Formular, dass zuerst
 * abgerufen werden muss, statt eine leere Auswahl hinzustellen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * VON HAND BLEIBT MOEGLICH, UND ZWAR AUS EINEM FACHLICHEN GRUND.
 *
 * Bei Vereinsflieger entsteht die Funktionsliste aus den MITGLIEDERN. Eine
 * frisch angelegte Funktion, die noch niemand traegt, kommt in keiner Antwort
 * vor. Wer nur die Auswahl zuliesse, koennte die Rechte fuer eine neue Funktion
 * erst vergeben, nachdem der erste Mensch sie hat -- also genau dann nicht, wenn
 * man sie braucht. Der von Hand angelegte Eintrag wird als „unbestaetigt"
 * gefuehrt, bis der Provider ihn zum ersten Mal meldet.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class RoleMappingForm
{
    public static function configure(Schema $schema): Schema
    {
        $registry = app(IdentityProviderRegistry::class);

        $provider = array_map(
            static fn (IdentityProvider $p): string => $p->label(),
            $registry->all(),
        );

        return $schema->components([
            Select::make('provider')
                ->label(__('identity.mapping.field.provider'))
                ->options($provider)
                ->required()
                // live(), weil die Werteliste darunter vom Provider abhaengt.
                ->live()
                ->disabled(fn (?RoleMapping $record): bool => $record !== null)
                ->helperText($provider === [] ? __('identity.mapping.no_provider') : null),

            Select::make('kind')
                ->label(__('identity.mapping.field.kind'))
                ->options([
                    RoleMapping::KIND_GROUP => __('identity.mapping.kind.group'),
                    RoleMapping::KIND_USER => __('identity.mapping.kind.user'),
                ])
                ->default(RoleMapping::KIND_GROUP)
                ->required()
                ->live()
                ->helperText(__('identity.mapping.help.kind')),

            /*
             * Eine EINZELNE Person zuzuordnen ist eine Ausnahme und sieht auch so
             * aus: ein Feld fuer die Kennung des Providers, ohne Auswahl. Wer
             * eine Liste aller Mitglieder zum Anklicken bekaeme, wuerde
             * Einzelzuordnungen zur Gewohnheit machen -- und damit ein
             * Rechtemodell bauen, das niemand mehr ueberblickt.
             */
            TextInput::make('value')
                ->label(__('identity.mapping.field.subject'))
                ->required()
                ->maxLength(191)
                ->helperText(__('identity.mapping.help.subject'))
                ->visible(fn (Get $get): bool => $get('kind') === RoleMapping::KIND_USER),

            Select::make('value')
                ->label(__('identity.mapping.field.group'))
                ->required()
                ->searchable()
                ->visible(fn (Get $get): bool => $get('kind') !== RoleMapping::KIND_USER)
                ->options(fn (Get $get): array => self::groupOptions((string) $get('provider')))
                ->helperText(fn (Get $get): ?string => self::groupHint((string) $get('provider')))
                /*
                 * "Neu" legt keine Gruppe beim Provider an -- das kann diese
                 * Anwendung nicht und soll es nicht. Es merkt sich nur einen
                 * Wert, den der Abgleich ab sofort erkennt.
                 */
                ->createOptionForm([
                    TextInput::make('value')
                        ->label(__('identity.mapping.field.group_manual'))
                        ->required()
                        ->maxLength(191)
                        ->helperText(__('identity.mapping.help.group_manual')),
                ])
                ->createOptionUsing(function (array $data, Get $get): string {
                    $wert = trim((string) $data['value']);

                    ExternalGroup::firstOrCreate([
                        'provider' => (string) $get('provider'),
                        'value' => $wert,
                    ]);

                    // Zurueck kommt der WERT, nicht die ID: In role_mappings
                    // steht der Vergleichswert, nicht ein Fremdschluessel --
                    // sonst haenge die Zuordnung an einer Zeile, die es nur
                    // gibt, weil jemand einmal abgerufen hat.
                    return $wert;
                }),

            Select::make('role_id')
                ->label(__('identity.mapping.field.role'))
                ->required()
                ->options(self::roleOptions())
                ->helperText(__('identity.mapping.help.role')),
        ])->columns(1);
    }

    /**
     * Die gefundenen Gruppen dieses Providers.
     *
     * @return array<string, string>
     */
    private static function groupOptions(string $provider): array
    {
        if ($provider === '') {
            return [];
        }

        return ExternalGroup::query()
            ->ofProvider($provider)
            ->orderByDesc('member_count')
            ->orderBy('value')
            ->get()
            ->mapWithKeys(static fn (ExternalGroup $g): array => [
                $g->value => $g->member_count !== null
                    ? sprintf('%s (%d)', $g->displayName(), $g->member_count)
                    : $g->displayName(),
            ])
            ->all();
    }

    /**
     * Der Satz unter dem Feld -- er beantwortet „warum ist da nichts".
     */
    private static function groupHint(string $provider): ?string
    {
        if ($provider === '') {
            return null;
        }

        $registry = app(IdentityProviderRegistry::class);

        if (! $registry->has($provider)) {
            return null;
        }

        if (! $registry->get($provider) instanceof DiscoversGroups) {
            return __('identity.mapping.help.group_free');
        }

        return ExternalGroup::query()->ofProvider($provider)->exists()
            ? __('identity.mapping.help.group')
            : __('identity.mapping.help.group_empty');
    }

    /**
     * Alle Rollen ausser denen, die nie von aussen kommen duerfen.
     *
     * Das ist die dritte Stelle, an der dieselbe Regel steht -- Formular, Model,
     * Anwendung. Absicht: Hier verschwindet die Rolle aus der AUSWAHL, damit
     * niemand eine Zuordnung baut, die spaeter abgewiesen wird. Die beiden
     * anderen Riegel bleiben trotzdem, weil ein Formular kein Schutz ist.
     *
     * @return array<int, string>
     */
    private static function roleOptions(): array
    {
        return Role::query()
            ->whereNotIn('name', CoreRoles::neverFromProvider())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->map(static fn (string $name): string => __('roles.'.$name))
            ->all();
    }
}
