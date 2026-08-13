<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Filament\Resources\Findings;

use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Actions\RecordFinding;
use App\Modules\TaskCards\Enums\FindingState;
use App\Modules\TaskCards\Filament\Resources\Findings\Pages\ListFindings;
use App\Modules\TaskCards\Models\Finding;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Permissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Findings across the whole fleet.
 *
 * Their own list rather than only appearing under the visit they were noticed
 * on -- because the question "what is still open on our aircraft" is asked far
 * more often than "what did we find during that job in March".
 */
final class FindingResource extends Resource
{
    protected static ?string $model = Finding::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 20;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('found_on', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label(__('taskcards.work_order.field.number'))
                    ->searchable()
                    ->weight('bold'),

                TextColumn::make('aircraft.registration')
                    ->label(__('fleet.aircraft.singular'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('title')
                    ->label(__('taskcards.finding.field.title'))
                    ->searchable()
                    ->limit(50)
                    ->description(fn (Finding $r): ?string => $r->found_by_name),

                IconColumn::make('is_blocking')
                    ->label(__('taskcards.finding.field.is_blocking'))
                    ->boolean(),

                TextColumn::make('state')
                    ->label(__('taskcards.work_order.field.state'))
                    ->badge()
                    ->formatStateUsing(fn (FindingState $state): string => $state->label())
                    ->color(fn (Finding $r): string => match (true) {
                        $r->deferralHasLapsed() => 'danger',
                        $r->state === FindingState::Deferred => 'warning',
                        $r->state === FindingState::Open => 'danger',
                        $r->state === FindingState::Scheduled => 'info',
                        default => 'success',
                    })
                    // A deferral that has run out is the state nobody is
                    // thinking about, so it says so rather than reading as a
                    // tidy "deferred".
                    ->description(fn (Finding $r): ?string => $r->deferralHasLapsed()
                        ? __('taskcards.finding.deferral_lapsed', ['date' => $r->deferred_until->format('d.m.Y')])
                        : null),

                TextColumn::make('found_on')
                    ->label(__('taskcards.finding.field.found_on'))
                    ->date('d.m.Y')
                    ->sortable()
                    ->toggleable(),
            ])
            /*
             * Die leere Liste muss sich selbst erklaeren. Feldtest, woertlich:
             * "nix kann angelegt werden. was soll der reiter?" -- beides
             * berechtigt, denn Befunde entstehen absichtlich NUR aus einem
             * Vorgang heraus (canCreate ist hart false, siehe unten), und der
             * Vorfilter blendet Erledigtes aus. Wer das nicht weiss, steht
             * vor einer leeren Seite ohne Knopf und haelt sie fuer kaputt.
             */
            ->emptyStateHeading(__('taskcards.finding.empty.heading'))
            ->emptyStateDescription(__('taskcards.finding.empty.description'))
            ->filters([
                SelectFilter::make('state')
                    ->label(__('taskcards.work_order.field.state'))
                    ->multiple()
                    ->options(collect(FindingState::cases())
                        ->mapWithKeys(fn (FindingState $s): array => [$s->value => $s->label()])
                        ->all())
                    ->default([
                        FindingState::Open->value,
                        FindingState::Scheduled->value,
                        FindingState::Deferred->value,
                    ]),

                TernaryFilter::make('is_blocking')
                    ->label(__('taskcards.finding.field.is_blocking')),
            ])
            ->recordActions([
                self::deferAction(),
                self::resolveAction(),
                self::dismissAction(),
            ])
            ->toolbarActions([
                self::raiseCardBulkAction(),
            ]);
    }

    /**
     * Mehrere Punkte, EINE Karte -- der zweite Halbsatz des Feldtests:
     * "Aus einzelnen oder mehreren Punkten soll dann eine Arbeitskarte
     * erstellt werden können."
     *
     * Die Auswahl entscheidet, WAS zusammen behoben wird; der Dialog nur
     * noch, in welchem Vorgang. Alles Fachliche (offen? dasselbe Flugzeug?)
     * prüft scheduleMany serverseitig -- eine Regel, die nur die Oberfläche
     * kennt, gilt als nicht vorhanden.
     */
    private static function raiseCardBulkAction(): BulkAction
    {
        return BulkAction::make('raiseCard')
            ->label(__('taskcards.finding.action.raise_card'))
            ->icon('heroicon-o-wrench')
            ->visible(fn (): bool => auth()->user()?->can(Permissions::WORK_ORDERS_MANAGE) ?? false)
            ->modalDescription(__('taskcards.finding.help.raise_card'))
            ->deselectRecordsAfterCompletion()
            ->schema(fn (Collection $records): array => [
                /*
                 * Vorbelegt, wenn das Flugzeug keinen offenen Vorgang hat --
                 * dasselbe Muster wie beim Einplanen einer LTA-Karte. Das
                 * Flugzeug steht mit der Auswahl fest (das der ersten Zeile;
                 * eine gemischte Auswahl weist der Server ab).
                 */
                Checkbox::make('open_new_order')
                    ->label(__('taskcards.finding.field.open_new_order'))
                    ->live()
                    ->default(fn (): bool => WorkOrder::query()
                        ->where('aircraft_id', $records->first()?->aircraft_id)
                        ->where('state', WorkOrder::STATE_OPEN)
                        ->doesntExist()),

                Select::make('work_order_id')
                    ->label(__('taskcards.work_order.singular'))
                    ->options(fn (): array => WorkOrder::query()
                        ->where('aircraft_id', $records->first()?->aircraft_id)
                        ->where('state', WorkOrder::STATE_OPEN)
                        ->orderByDesc('opened_at')
                        ->get()
                        ->mapWithKeys(fn (WorkOrder $o): array => [$o->id => $o->label()])
                        ->all())
                    ->required(fn (Get $get): bool => ! (bool) $get('open_new_order'))
                    ->visible(fn (Get $get): bool => ! (bool) $get('open_new_order')),
            ])
            ->action(function (Collection $records, array $data): void {
                /** @var list<Finding> $findings */
                $findings = $records->values()->all();

                try {
                    /*
                     * EINE Transaktion um beides. Sonst stünde nach einer
                     * abgewiesenen Auswahl (gemischte Flugzeuge, inzwischen
                     * erledigter Befund) ein frisch eröffneter, leerer Vorgang
                     * im Buch -- Nummer verbraucht, null Karten. Dieselbe
                     * Klammer, die openQuick aus genau diesem Grund hat.
                     */
                    $card = DB::transaction(function () use ($findings, $data) {
                        if ((bool) ($data['open_new_order'] ?? false)) {
                            // Implizit wie bei der Schnellreparatur: Der
                            // Vorgang trägt denselben Titel wie die Karte --
                            // aus derselben Feder (cardTitle), damit die
                            // beiden nie auseinanderlaufen.
                            $order = app(ManageWorkOrder::class)->open(
                                aircraft: $findings[0]->aircraft,
                                title: app(RecordFinding::class)->cardTitle($findings),
                                user: auth()->user(),
                            );
                        } else {
                            $order = WorkOrder::find($data['work_order_id'] ?? null);
                        }

                        if ($order === null) {
                            return null;
                        }

                        return app(RecordFinding::class)->scheduleMany(
                            findings: $findings,
                            order: $order,
                            user: auth()->user(),
                        );
                    });
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }

                if ($card === null) {
                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('taskcards.finding.scheduled', ['card' => $card->number]))
                    ->body(__('taskcards.finding.help.stays_open'))
                    ->send();
            });
    }

    /**
     * Deciding it can wait -- the act with teeth.
     */
    private static function deferAction(): Action
    {
        return Action::make('defer')
            ->label(__('taskcards.finding.action.defer'))
            ->icon('heroicon-o-clock')
            ->color('warning')
            ->visible(fn (Finding $r): bool => $r->isOutstanding()
                && (auth()->user()?->can(Permissions::FINDINGS_DEFER) ?? false))
            ->modalDescription(__('taskcards.finding.help.defer'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('taskcards.finding.field.deferral_reason'))
                    ->required()
                    ->rows(2),

                DatePicker::make('until')
                    ->label(__('taskcards.finding.field.deferred_until'))
                    ->minDate(now()),
            ])
            ->action(function (Finding $record, array $data): void {
                try {
                    app(RecordFinding::class)->defer(
                        $record, auth()->user(), (string) $data['reason'], $data['until'] ?? null,
                    );
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title(__('taskcards.finding_state.deferred'))->send();
            });
    }

    private static function resolveAction(): Action
    {
        return Action::make('resolve')
            ->label(__('taskcards.finding.action.resolve'))
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible(fn (Finding $r): bool => $r->isOutstanding()
                && (auth()->user()?->can(Permissions::FINDINGS_RECORD) ?? false))
            ->schema([
                Textarea::make('resolution')
                    ->label(__('taskcards.finding.field.resolution'))
                    ->required()
                    ->rows(2),
            ])
            ->action(function (Finding $record, array $data): void {
                try {
                    app(RecordFinding::class)->resolve(
                        $record, auth()->user(), (string) $data['resolution'],
                    );
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title(__('taskcards.finding_state.resolved'))->send();
            });
    }

    /**
     * Not a defect after all -- which is not the same as fixed.
     */
    private static function dismissAction(): Action
    {
        return Action::make('dismiss')
            ->label(__('taskcards.finding.action.dismiss'))
            ->icon('heroicon-o-x-mark')
            ->visible(fn (Finding $r): bool => $r->isOutstanding()
                && (auth()->user()?->can(Permissions::FINDINGS_DEFER) ?? false))
            ->modalDescription(__('taskcards.finding.help.dismiss'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('taskcards.finding.field.resolution'))
                    ->required()
                    ->rows(2),
            ])
            ->action(function (Finding $record, array $data): void {
                try {
                    app(RecordFinding::class)->dismiss(
                        $record, auth()->user(), (string) $data['reason'],
                    );
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title(__('taskcards.finding_state.dismissed'))->send();
            });
    }

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.taskcards');
    }

    public static function getNavigationLabel(): string
    {
        return __('taskcards.finding.plural');
    }

    public static function getModelLabel(): string
    {
        return __('taskcards.finding.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('taskcards.finding.plural');
    }

    /**
     * Only what is blocking, so the badge means something.
     */
    public static function getNavigationBadge(): ?string
    {
        $blocking = Finding::blocking()->count();

        return $blocking > 0 ? (string) $blocking : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::WORK_ORDERS_VIEW) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** @param  Finding  $record */
    public static function canEdit($record): bool
    {
        return false;
    }

    /** @param  Finding  $record */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListFindings::route('/')];
    }
}
