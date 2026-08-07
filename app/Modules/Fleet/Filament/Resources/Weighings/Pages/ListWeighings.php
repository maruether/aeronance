<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\Weighings\Pages;

use App\Modules\Fleet\Actions\PrepareWeighing;
use App\Modules\Fleet\Filament\Resources\Weighings\WeighingResource;
use App\Modules\Fleet\Models\Aircraft;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

final class ListWeighings extends ListRecords
{
    protected static string $resource = WeighingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            /*
             * Starting from the last sheet rather than from nothing.
             *
             * The manual values come across because they describe the type and
             * retyping them every four years is four chances to transpose a
             * digit. The measurements do not, and the datum least of all -- a
             * prefilled field one is supposed to check is a field nobody checks.
             */
            Action::make('prepare')
                ->label(__('fleet.weighing.new_from_last'))
                ->icon('heroicon-o-document-duplicate')
                ->schema([
                    Select::make('aircraft_id')
                        ->label(__('fleet.aircraft.singular'))
                        ->options(fn (): array => Aircraft::orderBy('registration')
                            ->pluck('registration', 'id')->all())
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $aircraft = Aircraft::find($data['aircraft_id'] ?? null);

                    if ($aircraft === null) {
                        return;
                    }

                    $action = app(PrepareWeighing::class);
                    $previous = $action->lastSignedOff($aircraft);
                    $weighing = $action->from($aircraft, auth()->user());

                    Notification::make()
                        ->success()
                        ->title(__('fleet.weighing.prepared'))
                        ->body($previous === null
                            ? __('fleet.weighing.no_previous')
                            : __('fleet.weighing.carried_over', [
                                'date' => $previous->weighed_at->format('d.m.Y'),
                            ]))
                        ->send();

                    $this->redirect(WeighingResource::getUrl('edit', ['record' => $weighing]));
                }),
        ];
    }
}
