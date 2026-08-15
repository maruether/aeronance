<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Pages;

use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Filament\Resources\StockLots\StockLotResource;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StorageCompartment;
use App\Modules\Warehouse\Permissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Was im Lager ist -- die Frage, die am häufigsten gestellt wird.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Feldtest: "ich hätte gerne noch eine übersichtsseite was im lager ist.
 * bisher gibt es nur die seite ‚Lose', welche nur bei seriennummern geführten
 * Teilen greift."
 *
 * Genau so ist es: Die Lose-Liste zeigt EINZELSTÜCKE, und die gibt es nur, wo
 * etwas los- oder seriennummerngeführt ist. Schrauben, Öl und Sicherungsdraht
 * kommen dort gar nicht vor. Die Bauteiltypen-Liste wiederum ist
 * Stammdatenpflege -- sie beantwortet "welche Teile kennen wir", nicht "was
 * liegt da".
 *
 * Diese Seite beantwortet die dritte Frage, und zwar für JEDE Art von
 * Bestand. Sie zeigt nur Teile, von denen etwas da ist (oder etwas fehlt):
 * Ein Katalog aller je angelegten Bauteiltypen wäre wieder die Stammdatenliste.
 *
 * DREI ZAHLEN, weil drei verschiedene Fragen dahinterstehen: verfügbar (was
 * eingebaut werden darf), gesperrt (was da ist und nicht darf) und gesamt.
 * Eine einzige Zahl müsste sich für eine davon entscheiden und wäre für die
 * anderen beiden falsch.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class StockOverview extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'warehouse.filament.pages.stock-overview';

    protected static ?string $slug = 'bestand';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.warehouse');
    }

    public static function getNavigationLabel(): string
    {
        return __('warehouse.overview.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('warehouse.overview.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('warehouse.overview.subheading');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedSquares2x2;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permissions::STOCK_VIEW) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                PartType::query()
                    ->with(['storageCompartment.location', 'supplier'])
                    ->withAvailableStock(),
            )
            /*
             * Ab Werk nur, was da ist oder fehlt. Wer den ganzen Katalog
             * sucht, sucht die Stammdaten -- und findet sie unter
             * „Bauteiltypen".
             */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where(
                fn (Builder $q): Builder => $q->whereHas('movements')->orWhereNotNull('minimum_stock'),
            ))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('warehouse.part_type.field.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (PartType $r): ?string => $r->ipc_part_number),

                TextColumn::make('classification')
                    ->label(__('warehouse.part_type.field.classification'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (PartClassification $state): string => $state->label())
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('storageCompartment.name')
                    ->label(__('warehouse.part_type.field.compartment'))
                    ->state(fn (PartType $r): string => $r->storageCompartment?->fullName()
                        ?? __('warehouse.overview.no_compartment'))
                    ->searchable()
                    ->toggleable(),

                // Was eingebaut werden darf -- der Maßstab ist derselbe wie
                // beim Buchen (StockLot::scopeIssuable).
                TextColumn::make('available')
                    ->label(__('warehouse.overview.available'))
                    ->alignEnd()
                    ->state(fn (PartType $r): string => self::amount($r->availableStock(), $r))
                    ->color(fn (PartType $r): ?string => $r->isBelowMinimum() ? 'danger' : null)
                    ->weight(fn (PartType $r): ?string => $r->isBelowMinimum() ? 'bold' : null)
                    ->description(fn (PartType $r): ?string => $r->minimum_stock !== null
                        ? __('warehouse.part_type.minimum_is', ['n' => $r->minimum_stock])
                        : null),

                // Da, aber nicht verwendbar: gesperrt, unbrauchbar, oder ohne
                // Nachweis. Eine Zahl, die man kennen will, bevor man bestellt.
                TextColumn::make('blocked')
                    ->label(__('warehouse.overview.blocked'))
                    ->alignEnd()
                    ->state(fn (PartType $r): string => self::amount(
                        max(0.0, $r->currentStock() - $r->availableStock()),
                        $r,
                    ))
                    ->color(fn (PartType $r): ?string => $r->currentStock() > $r->availableStock()
                        ? 'warning'
                        : null),

                TextColumn::make('total')
                    ->label(__('warehouse.overview.total'))
                    ->alignEnd()
                    ->state(fn (PartType $r): string => self::amount($r->currentStock(), $r))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('storage')
                    ->label(__('warehouse.overview.filter.location'))
                    ->options(fn (): array => StorageCompartment::query()
                        ->with('location')
                        ->get()
                        ->mapWithKeys(fn (StorageCompartment $c): array => [$c->id => $c->fullName()])
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $q): Builder => $q->where('storage_compartment_id', $data['value']),
                    )),

                Filter::make('below_minimum')
                    ->label(__('warehouse.part_type.filter.below_minimum'))
                    ->query(fn (Builder $query): Builder => $query->belowMinimum()),

                Filter::make('with_blocked')
                    ->label(__('warehouse.overview.filter.with_blocked'))
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'lots',
                        fn (Builder $l): Builder => $l->whereIn('state', [
                            LotState::Quarantined->value,
                            LotState::Unserviceable->value,
                        ]),
                    )),
            ])
            ->recordActions([
                /*
                 * Von der Zahl zu den Zeilen: Wer wissen will, WORAUS sich ein
                 * Bestand zusammensetzt, will die Lose sehen -- gefiltert auf
                 * dieses Teil, nicht die ganze Liste zum Durchsuchen.
                 */
                Action::make('lots')
                    ->label(__('warehouse.overview.show_lots'))
                    ->icon('heroicon-o-rectangle-stack')
                    ->url(fn (PartType $r): string => StockLotResource::getUrl('index', [
                        'tableSearch' => $r->name,
                    ])),
            ])
            ->emptyStateHeading(__('warehouse.overview.empty.heading'))
            ->emptyStateDescription(__('warehouse.overview.empty.description'));
    }

    /**
     * Menge mit Einheit, ohne die Nullen, die niemand liest.
     */
    private static function amount(float $value, PartType $part): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', '.'), '0'), ',')
            .' '.$part->unit_of_measure;
    }
}
