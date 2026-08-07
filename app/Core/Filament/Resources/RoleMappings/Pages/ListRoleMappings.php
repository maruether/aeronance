<?php

declare(strict_types=1);

namespace App\Core\Filament\Resources\RoleMappings\Pages;

use App\Core\Filament\Resources\RoleMappings\RoleMappingResource;
use App\Core\Identity\DiscoversGroups;
use App\Core\Identity\IdentityProvider;
use App\Core\Identity\IdentityProviderRegistry;
use App\Core\Identity\RememberExternalGroups;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Throwable;

final class ListRoleMappings extends ListRecords
{
    protected static string $resource = RoleMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->discoverAction(),
            CreateAction::make(),
        ];
    }

    /**
     * „Funktionen jetzt abrufen".
     *
     * ─────────────────────────────────────────────────────────────────────────
     * AUF KNOPFDRUCK, NICHT BEIM SEITENAUFBAU. Vereinsflieger ist
     * mengenbegrenzt; eine Auswahlliste, die sich bei jedem Oeffnen neu holt,
     * wuerde die Sperre selbst herbeifuehren, gegen die der Client sonst so
     * sorgfaeltig ist.
     *
     * Die Bestaetigung sagt ausdruecklich, dass ein Netzzugriff stattfindet.
     * Wer bei einem begrenzten Dienst einen Knopf drueckt, soll wissen, dass er
     * damit nach draussen greift.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private function discoverAction(): Action
    {
        $faehig = array_filter(
            app(IdentityProviderRegistry::class)->all(),
            static fn (IdentityProvider $p): bool => $p instanceof DiscoversGroups,
        );

        return Action::make('discover')
            ->label(__('identity.discover.action'))
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            // Kein Knopf, wenn ihn kein Connector beantworten kann.
            ->visible($faehig !== [])
            ->requiresConfirmation()
            ->modalHeading(__('identity.discover.action'))
            ->modalDescription(__('identity.discover.confirm'))
            ->schema(count($faehig) > 1 ? [
                Select::make('provider')
                    ->label(__('identity.mapping.field.provider'))
                    ->options(array_map(static fn (IdentityProvider $p): string => $p->label(), $faehig))
                    ->required(),
            ] : [])
            ->action(function (array $data) use ($faehig): void {
                $name = $data['provider'] ?? array_key_first($faehig);
                $provider = $faehig[$name] ?? null;

                if (! $provider instanceof DiscoversGroups) {
                    return;
                }

                try {
                    $ergebnis = app(RememberExternalGroups::class)->handle($name, $provider->groups());
                } catch (Throwable $e) {
                    /*
                     * Die Begruendung des Dienstes wird DURCHGEREICHT, nicht
                     * durch ein freundliches „hat nicht geklappt" ersetzt. Genau
                     * dieses Wegwerfen hat in der Entwicklung zwei Anmeldungen
                     * gegen ein mengenbegrenztes System gekostet.
                     */
                    Notification::make()
                        ->title(__('identity.discover.failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('identity.discover.done', [
                        'seen' => $ergebnis['seen'],
                        'new' => $ergebnis['new'],
                    ]))
                    ->success()
                    ->send();
            });
    }
}
