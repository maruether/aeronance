<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Filament\Resources\WorkOrders\Pages;

use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Enums\ActivityKind;
use App\Modules\TaskCards\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Modules\TaskCards\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
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

            /*
             * Feldtest: "Eine einzelne Reparatur ist nur eine Arbeitskarte.
             * Das ist unnoetige Arbeit." EIN Dialog, der Vorgang entsteht
             * implizit mit (Titel = Kartentitel) -- abgekuerzt, nicht
             * uebersprungen: Nummernkreis, Zaehlerstaende und Freigabeweg
             * laufen unveraendert. Sichtbar nur, wer beides duerfte.
             */
            Action::make('quickRepair')
                ->label(__('taskcards.work_order.action.quick_repair'))
                ->icon('heroicon-o-bolt')
                ->color('gray')
                ->visible(fn (): bool => (auth()->user()?->can(Permissions::WORK_ORDERS_MANAGE) ?? false)
                    && (auth()->user()?->can(Permissions::CARDS_WORK) ?? false))
                ->schema([
                    Select::make('aircraft_id')
                        ->label(__('fleet.aircraft.singular'))
                        ->options(fn (): array => Aircraft::active()->orderBy('registration')
                            ->pluck('registration', 'id')->all())
                        ->searchable()
                        ->required(),

                    TextInput::make('title')
                        ->label(__('taskcards.card.field.title'))
                        ->required()
                        ->maxLength(160)
                        ->placeholder(__('taskcards.work_order.quick_repair_placeholder')),

                    Select::make('activity_kind')
                        ->label(__('taskcards.card.field.activity_kind'))
                        ->options(collect(ActivityKind::cases())
                            ->mapWithKeys(fn (ActivityKind $k): array => [$k->value => $k->label()])
                            ->all())
                        ->default(ActivityKind::Repair->value)
                        ->selectablePlaceholder(false)
                        ->required(),

                    TextInput::make('ata_chapter')
                        ->label(__('taskcards.card.field.ata_chapter'))
                        ->maxLength(16)
                        ->helperText(__('taskcards.card.help.ata')),

                    Textarea::make('instruction')
                        ->label(__('taskcards.card.field.instruction'))
                        ->rows(2),

                    Checkbox::make('critical')
                        ->label(__('taskcards.card.field.critical'))
                        ->live()
                        ->helperText(__('taskcards.inspection.help.critical')),

                    TextInput::make('critical_reason')
                        ->label(__('taskcards.card.field.critical_reason'))
                        ->maxLength(160)
                        ->visible(fn (Get $get): bool => (bool) $get('critical'))
                        ->required(fn (Get $get): bool => (bool) $get('critical'))
                        ->helperText(__('taskcards.inspection.help.reason')),
                ])
                ->action(function (array $data): void {
                    $aircraft = Aircraft::find($data['aircraft_id'] ?? null);

                    if ($aircraft === null) {
                        return;
                    }

                    try {
                        $card = app(ManageWorkOrder::class)->openQuick(
                            aircraft: $aircraft,
                            user: auth()->user(),
                            title: (string) $data['title'],
                            instruction: $data['instruction'] ?? null,
                            kind: ActivityKind::from($data['activity_kind']),
                            ataChapter: $data['ata_chapter'] ?? null,
                            critical: (bool) ($data['critical'] ?? false),
                            criticalReason: $data['critical_reason'] ?? null,
                        );
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                        return;
                    }

                    $this->redirect(WorkOrderResource::getUrl('view', ['record' => $card->work_order_id]));
                }),
        ];
    }
}
