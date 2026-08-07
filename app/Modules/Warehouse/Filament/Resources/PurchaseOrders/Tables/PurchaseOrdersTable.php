<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\PurchaseOrders\Tables;

use App\Modules\Warehouse\Actions\CancelPurchaseOrder;
use App\Modules\Warehouse\Actions\ReceivePurchaseOrderLine;
use App\Modules\Warehouse\Enums\PurchaseOrderState;
use App\Modules\Warehouse\Models\PurchaseOrder;
use App\Modules\Warehouse\Models\PurchaseOrderLine;
use App\Modules\Warehouse\Permissions;
use App\Modules\Warehouse\Support\DocumentTypes;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

final class PurchaseOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order_number')
                    ->label(__('warehouse.order.field.number'))
                    ->state(fn (PurchaseOrder $record): string => $record->label())
                    ->searchable()
                    ->sortable(),

                TextColumn::make('supplier.name')
                    ->label(__('warehouse.order.field.supplier'))
                    ->searchable(),

                TextColumn::make('expected_at')
                    ->label(__('warehouse.order.field.expected'))
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->sortable()
                    /*
                     * Ueberfaellig wird eingefaerbt, nicht nur sortiert: Die
                     * Liste beantwortet damit auf einen Blick die Frage, wegen
                     * der es sie gibt.
                     */
                    ->color(fn (PurchaseOrder $record): string => $record->expected_at !== null
                        && $record->state->isOutstanding()
                        && $record->expected_at->isPast()
                            ? 'warning'
                            : 'gray'),

                TextColumn::make('state')
                    ->label(__('warehouse.order.field.state'))
                    ->badge()
                    ->formatStateUsing(fn (PurchaseOrderState $state): string => $state->label())
                    ->color(fn (PurchaseOrderState $state): string => $state->colour()),

                // Wie weit die Lieferung ist -- „3 von 5" sagt mehr als ein Haken.
                TextColumn::make('fortschritt')
                    ->label(__('warehouse.order.field.quantity_received'))
                    ->state(fn (PurchaseOrder $record): string => sprintf(
                        '%s / %s',
                        self::zahl($record->lines->sum('quantity_received')),
                        self::zahl($record->lines->sum('quantity_ordered')),
                    )),

                TextColumn::make('createdBy.name')
                    ->label(__('warehouse.order.field.created_by'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('expected_at')
            ->filters([
                Filter::make('outstanding')
                    ->label(__('warehouse.order.filter.outstanding'))
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->outstanding()),

                Filter::make('overdue')
                    ->label(__('warehouse.order.filter.overdue'))
                    ->query(fn (Builder $query): Builder => $query->overdue()),

                SelectFilter::make('state')
                    ->label(__('warehouse.order.field.state'))
                    ->options(fn (): array => collect(PurchaseOrderState::cases())
                        ->mapWithKeys(fn (PurchaseOrderState $s): array => [$s->value => $s->label()])
                        ->all()),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    self::receiveAction(),
                    self::cancelAction(),
                ]),
            ]);
    }

    /**
     * Einbuchen — je Position, mit Los und Papier.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * JE POSITION UND NICHT AUF EINEN SCHLAG, weil jede gelieferte Charge ihr
     * EIGENES Form 1 hat. Ein Sammelknopf „alles angekommen" müsste sich ein
     * gemeinsames Papier ausdenken, und damit wäre die Nachweiskette an genau
     * der Stelle erfunden, an der sie zählt.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private static function receiveAction(): Action
    {
        return Action::make('receive')
            ->label(__('warehouse.order.action.receive'))
            ->icon('heroicon-o-inbox-arrow-down')
            ->color('success')
            ->visible(fn (PurchaseOrder $record): bool => $record->state->isOutstanding()
                && (auth()->user()?->can(Permissions::ORDERS_MANAGE) ?? false))
            ->schema(fn (PurchaseOrder $record): array => [
                Select::make('line_id')
                    ->label(__('warehouse.order.field.part'))
                    ->options(fn (): array => $record->lines()
                        ->with('partType')
                        ->get()
                        ->filter(fn (PurchaseOrderLine $p): bool => ! $p->isComplete())
                        ->mapWithKeys(fn (PurchaseOrderLine $p): array => [
                            $p->id => sprintf(
                                '%s (%s %s)',
                                $p->partType?->name ?? '—',
                                __('warehouse.order.field.outstanding'),
                                self::zahl($p->outstanding()),
                            ),
                        ])
                        ->all())
                    ->required()
                    ->live(),

                TextInput::make('quantity')
                    ->label(__('warehouse.order.field.quantity_received'))
                    ->numeric()
                    ->minValue(0.001)
                    ->required()
                    // Vorbelegt mit dem, was fehlt: der haeufigste Fall ist,
                    // dass genau die Restmenge ankommt.
                    ->default(fn (callable $get): ?float => PurchaseOrderLine::find($get('line_id'))?->outstanding()),

                DatePicker::make('received_at')
                    ->label(__('warehouse.receive.field.received_at'))
                    ->required()
                    ->default(now()),

                Select::make('document_type')
                    ->label(__('warehouse.lot.field.document_type'))
                    ->options(DocumentTypes::options())
                    ->required()
                    ->live(),

                TextInput::make('document_reference')
                    ->label(__('warehouse.lot.field.document_reference'))
                    ->maxLength(128)
                    ->helperText(__('warehouse.order.notification.received_hint')),

                Textarea::make('note')
                    ->label(__('warehouse.order.field.note'))
                    ->rows(2),
            ])
            ->action(function (PurchaseOrder $record, array $data): void {
                $position = PurchaseOrderLine::find($data['line_id'] ?? null);

                if ($position === null) {
                    return;
                }

                try {
                    app(ReceivePurchaseOrderLine::class)->handle(
                        $position,
                        (float) $data['quantity'],
                        (string) $data['received_at'],
                        auth()->user(),
                        [
                            'document_type' => $data['document_type'] ?? null,
                            'document_reference' => $data['document_reference'] ?? null,
                        ],
                    );
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('warehouse.order.notification.refused'))
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('warehouse.order.notification.received', [
                        'quantity' => self::zahl((float) $data['quantity']),
                        'part' => $position->partType?->name ?? '',
                    ]))
                    ->body(__('warehouse.order.notification.received_hint'))
                    ->send();
            });
    }

    private static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('warehouse.order.action.cancel'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (PurchaseOrder $record): bool => $record->state->isOutstanding()
                && (auth()->user()?->can(Permissions::ORDERS_MANAGE) ?? false))
            ->modalHeading(fn (PurchaseOrder $record): string => __('warehouse.order.action.cancel_heading', [
                'order' => $record->label(),
            ]))
            ->modalDescription(__('warehouse.order.action.cancel_description'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('warehouse.order.field.cancel_reason'))
                    ->required()
                    ->rows(2)
                    ->maxLength(255),
            ])
            ->action(function (PurchaseOrder $record, array $data): void {
                try {
                    app(CancelPurchaseOrder::class)->handle($record, (string) $data['reason']);
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('warehouse.order.notification.refused'))
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('warehouse.order.notification.cancelled', ['order' => $record->label()]))
                    ->send();
            });
    }

    /** Mengen ohne schleppende Nullen -- „3" statt „3,000". */
    private static function zahl(float $wert): string
    {
        return rtrim(rtrim(number_format($wert, 3, ',', '.'), '0'), ',');
    }
}
