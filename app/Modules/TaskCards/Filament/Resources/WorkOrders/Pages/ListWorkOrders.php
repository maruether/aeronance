<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Filament\Resources\WorkOrders\Pages;

use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Filament\Resources\WorkOrders\WorkOrderResource;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Throwable;

final class ListWorkOrders extends ListRecords
{
    protected static string $resource = WorkOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Through the action rather than a plain create, so the number is
            // allocated and the aircraft's counters are copied at the moment the
            // visit opens.
            Action::make('open')
                ->label(__('taskcards.work_order.action.open'))
                ->icon('heroicon-o-plus')
                ->schema([
                    Select::make('aircraft_id')
                        ->label(__('fleet.aircraft.singular'))
                        ->options(fn (): array => Aircraft::active()->orderBy('registration')
                            ->pluck('registration', 'id')->all())
                        ->searchable()
                        ->required(),

                    TextInput::make('title')
                        ->label(__('taskcards.work_order.field.title'))
                        ->required()
                        ->maxLength(160)
                        ->placeholder('Jahresnachprüfung 2026'),

                    DatePicker::make('opened_at')
                        ->label(__('taskcards.work_order.field.opened_at'))
                        ->default(now())
                        ->required(),

                    Textarea::make('description')
                        ->label(__('taskcards.work_order.field.description'))
                        ->rows(2),
                ])
                ->action(function (array $data): void {
                    $aircraft = Aircraft::find($data['aircraft_id'] ?? null);

                    if ($aircraft === null) {
                        return;
                    }

                    try {
                        $order = app(ManageWorkOrder::class)->open(
                            $aircraft,
                            (string) $data['title'],
                            auth()->user(),
                            $data['description'] ?? null,
                            $data['opened_at'] ?? null,
                        );
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                        return;
                    }

                    $this->redirect(WorkOrderResource::getUrl('view', ['record' => $order]));
                }),
        ];
    }
}
