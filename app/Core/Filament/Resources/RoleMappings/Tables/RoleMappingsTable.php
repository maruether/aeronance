<?php

declare(strict_types=1);

namespace App\Core\Filament\Resources\RoleMappings\Tables;

use App\Core\Identity\ExternalGroup;
use App\Core\Identity\IdentityProvider;
use App\Core\Identity\IdentityProviderRegistry;
use App\Core\Identity\RoleMapping;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class RoleMappingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')
                    ->label(__('identity.mapping.field.provider'))
                    ->formatStateUsing(static function (string $state): string {
                        $registry = app(IdentityProviderRegistry::class);

                        // Ein Connector kann abgeschaltet sein -- die Zuordnung
                        // bleibt trotzdem stehen und muss lesbar sein.
                        return $registry->has($state) ? $registry->get($state)->label() : $state;
                    })
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('kind')
                    ->label(__('identity.mapping.field.kind'))
                    ->formatStateUsing(static fn (string $state): string => __('identity.mapping.kind.'.$state))
                    ->badge()
                    ->color(static fn (string $state): string => $state === RoleMapping::KIND_USER ? 'warning' : 'primary'),

                TextColumn::make('value')
                    ->label(__('identity.mapping.field.value'))
                    ->searchable()
                    /*
                     * Der Zustand steht UNTER dem Wert, nicht als eigene Spalte:
                     * Er ist die Antwort auf „warum wirkt diese Zeile nicht", und
                     * die will man dort lesen, wo der Wert steht.
                     */
                    ->description(static function (RoleMapping $record): ?string {
                        if ($record->kind === RoleMapping::KIND_USER) {
                            return null;
                        }

                        $gruppe = ExternalGroup::query()
                            ->ofProvider($record->provider)
                            ->where('value', $record->value)
                            ->first();

                        if ($gruppe === null) {
                            return __('identity.group.status.unknown');
                        }

                        return __('identity.group.status.'.$gruppe->status());
                    }),

                TextColumn::make('role.name')
                    ->label(__('identity.mapping.field.role'))
                    ->formatStateUsing(static fn (string $state): string => __('roles.'.$state))
                    ->badge()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('identity.mapping.field.created'))
                    ->dateTime('d.m.Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('provider')
                    ->label(__('identity.mapping.field.provider'))
                    ->options(static fn (): array => array_map(
                        static fn (IdentityProvider $p): string => $p->label(),
                        app(IdentityProviderRegistry::class)->all(),
                    )),
            ])
            ->defaultSort('provider')
            ->emptyStateHeading(__('identity.mapping.empty'))
            ->emptyStateDescription(__('identity.mapping.empty_help'))
            ->recordActions([
                EditAction::make(),
                /*
                 * Loeschen ist hier richtig -- anders als bei Rollen. Eine
                 * Zuordnung ist kein Nachweis, sondern eine Regel fuer die
                 * Zukunft. Was sie einmal vergab, steht in external_role_grants
                 * und wird beim naechsten Abgleich sauber zurueckgenommen.
                 */
                DeleteAction::make(),
            ]);
    }
}
