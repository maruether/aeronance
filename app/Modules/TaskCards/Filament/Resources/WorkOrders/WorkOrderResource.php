<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Filament\Resources\WorkOrders;

use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Filament\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Modules\TaskCards\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Modules\TaskCards\Filament\Resources\WorkOrders\Schemas\WorkOrderInfolist;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Permissions;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Visits.
 *
 * The number, the aircraft and how many cards are still open -- which is what
 * somebody scanning this list wants, because an open card is work waiting and an
 * uncertified one is work waiting for somebody else.
 */
final class WorkOrderResource extends Resource
{
    protected static ?string $model = WorkOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('aircraft_id')
                ->label(__('fleet.aircraft.singular'))
                ->options(fn (): array => Aircraft::active()->orderBy('registration')
                    ->pluck('registration', 'id')->all())
                ->searchable()
                ->required()
                ->disabledOn('edit'),

            TextInput::make('title')
                ->label(__('taskcards.work_order.field.title'))
                ->required()
                ->maxLength(160),

            DatePicker::make('opened_at')
                ->label(__('taskcards.work_order.field.opened_at'))
                ->default(now())
                ->required()
                ->disabledOn('edit'),

            Textarea::make('description')
                ->label(__('taskcards.work_order.field.description'))
                ->rows(3)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function infolist(Schema $schema): Schema
    {
        return WorkOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label(__('taskcards.work_order.field.number'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('aircraft.registration')
                    ->label(__('fleet.aircraft.singular'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('title')
                    ->label(__('taskcards.work_order.field.title'))
                    ->searchable()
                    ->limit(50),

                TextColumn::make('opened_at')
                    ->label(__('taskcards.work_order.field.opened_at'))
                    ->date('d.m.Y')
                    ->sortable(),

                // Open cards are work waiting; completed ones are work waiting
                // for somebody else. Different problems, so they are counted
                // apart.
                TextColumn::make('cards')
                    ->label(__('taskcards.card.plural'))
                    ->state(fn (WorkOrder $r): string => sprintf(
                        '%d / %d',
                        $r->taskCards()->where('state', 'certified')->count(),
                        $r->taskCards()->count(),
                    )),

                TextColumn::make('awaiting')
                    ->label(__('taskcards.card.action.certify'))
                    ->badge()
                    ->state(fn (WorkOrder $r): string => (string) $r->taskCards()->awaitingCertification()->count())
                    ->color(fn (WorkOrder $r): string => $r->taskCards()->awaitingCertification()->exists()
                        ? 'warning'
                        : 'gray'),

                // Whether the aircraft may fly, which is the question the whole
                // module builds up to.
                TextColumn::make('release')
                    ->label(__('taskcards.release.singular'))
                    ->badge()
                    ->state(fn (WorkOrder $r): string => $r->isReleased()
                        ? ($r->currentRelease()?->number ?? '—')
                        : ($r->isReadyForRelease()
                            ? __('taskcards.release.awaiting')
                            : '—'))
                    ->color(fn (WorkOrder $r): string => match (true) {
                        $r->isReleased() => 'success',
                        $r->isReadyForRelease() => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('state')
                    ->label(__('taskcards.work_order.field.state'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('taskcards.work_order.state.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        WorkOrder::STATE_OPEN => 'info',
                        WorkOrder::STATE_CLOSED => 'success',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label(__('taskcards.work_order.field.state'))
                    ->options([
                        WorkOrder::STATE_OPEN => __('taskcards.work_order.state.open'),
                        WorkOrder::STATE_CLOSED => __('taskcards.work_order.state.closed'),
                    ])
                    ->default(WorkOrder::STATE_OPEN),
            ])
            ->recordUrl(fn (WorkOrder $record): string => WorkOrderResource::getUrl('view', ['record' => $record]));
    }

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.taskcards');
    }

    public static function getNavigationLabel(): string
    {
        return __('taskcards.work_order.plural');
    }

    public static function getModelLabel(): string
    {
        return __('taskcards.work_order.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('taskcards.work_order.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $open = WorkOrder::open()->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::WORK_ORDERS_VIEW) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(Permissions::WORK_ORDERS_MANAGE) ?? false;
    }

    /** @param  WorkOrder  $record */
    public static function canEdit($record): bool
    {
        return $record->isOpen()
            && (auth()->user()?->can(Permissions::WORK_ORDERS_MANAGE) ?? false);
    }

    /** @param  WorkOrder  $record */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkOrders::route('/'),
            'view' => ViewWorkOrder::route('/{record}'),
        ];
    }
}
