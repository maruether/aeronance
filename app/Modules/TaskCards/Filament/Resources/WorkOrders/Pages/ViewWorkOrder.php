<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Filament\Resources\WorkOrders\Pages;

use App\Models\User;
use App\Modules\Fleet\Models\ComponentLimit;
use App\Modules\Fleet\Models\ExternalWorkOrder;
use App\Modules\Fleet\Models\Installation;
use App\Modules\Fleet\Models\MaintenanceManual;
use App\Modules\TaskCards\Actions\CertifyTaskCard;
use App\Modules\TaskCards\Actions\InspectCriticalTask;
use App\Modules\TaskCards\Actions\IssuePartToCard;
use App\Modules\TaskCards\Actions\IssueRelease;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Actions\RecordFinding;
use App\Modules\TaskCards\Enums\ActivityKind;
use App\Modules\TaskCards\Enums\ParticipationKind;
use App\Modules\TaskCards\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Modules\TaskCards\Models\Finding;
use App\Modules\TaskCards\Models\TaskCard;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Permissions;
use App\Modules\TaskCards\Support\WorkDuration;
use App\Modules\Warehouse\Actions\ResolveScanCode;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions as WarehousePermissions;
use App\Modules\Warehouse\Support\ScanCode;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Throwable;

/**
 * One visit, with its cards.
 *
 * Everything a card needs is here rather than on a card page of its own: writing
 * cards, putting hours on them and signing them off all happen while looking at
 * the same visit, and a separate screen per card would mean navigating away and
 * back for every one of them.
 */
final class ViewWorkOrder extends ViewRecord
{
    protected static string $resource = WorkOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->addCardAction(),
            ActionGroup::make([
                $this->recordTimeAction(),
                $this->completeCardAction(),
                $this->inspectCardAction(),
                $this->certifyCardAction(),
                $this->recordFindingAction(),
                $this->issuePartAction(),
                $this->cancelCardAction(),
                $this->scheduleFindingAction(),
            ])->label(__('taskcards.card.plural'))->button(),

            $this->linkExternalOrderAction(),
            $this->releaseAction(),
            $this->printReleaseAction(),
            $this->correctReleaseAction(),
            $this->closeAction(),
        ];
    }

    /**
     * Die Bescheinigung drucken -- abheften und einkleben.
     *
     * Feldtest: "Abgeschlossene Freigaben sollten ausdruckbar sein." Die
     * Druckansicht gab es laengst (taskcards.release-Route, Browser druckt);
     * erreichbar war sie nur ueber ein unscheinbares Badge in der
     * Freigabe-Sektion. Ein Knopf am Kopf der Seite ist der Unterschied
     * zwischen "gibt es" und "findet man".
     */
    private function printReleaseAction(): Action
    {
        return Action::make('printRelease')
            ->label(__('taskcards.release.print'))
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->visible(fn (): bool => $this->record->currentRelease() !== null)
            ->url(fn (): string => route('taskcards.release', $this->record->currentRelease()), shouldOpenInNewTab: true);
    }

    /**
     * Tying the visit to the external order it commissioned.
     *
     * Offered only while there is something to link: orders of THIS aircraft.
     * The wrong-aircraft refusal lives in the action; the list here simply
     * never contains a wrong-aircraft order, so the refusal is for imports and
     * console use, not for this screen.
     */
    private function linkExternalOrderAction(): Action
    {
        return Action::make('linkExternalOrder')
            ->label(__('taskcards.external.link'))
            ->icon('heroicon-o-building-office-2')
            ->color('gray')
            ->visible(fn (WorkOrder $record): bool => ! $record->isReleased()
                && $record->state !== WorkOrder::STATE_CANCELLED
                && (auth()->user()?->can(Permissions::WORK_ORDERS_MANAGE) ?? false)
                && ExternalWorkOrder::where('aircraft_id', $record->aircraft_id)->exists())
            ->modalDescription(__('taskcards.external.help.why'))
            ->schema(fn (WorkOrder $record): array => [
                Select::make('external_work_order_id')
                    ->label(__('taskcards.external.singular'))
                    ->options(ExternalWorkOrder::where('aircraft_id', $record->aircraft_id)
                        ->orderByDesc('sent_at')
                        ->get()
                        ->mapWithKeys(fn (ExternalWorkOrder $o): array => [$o->id => $o->label()])
                        ->all())
                    ->default($record->external_work_order_id)
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $external = ExternalWorkOrder::find($data['external_work_order_id'] ?? null);

                if ($external === null) {
                    return;
                }

                try {
                    app(ManageWorkOrder::class)->linkExternalOrder(
                        $this->record, $external, auth()->user(),
                    );
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title(__('taskcards.external.linked'))->send();
            });
    }

    /**
     * The third signature.
     *
     * Offered only where it can succeed, and where it cannot the reason is shown
     * as a disabled tooltip rather than as a refusal after the click -- the same
     * approach as the correction action in the warehouse, for the same reason: an
     * action that is always there and usually refuses teaches people to ignore
     * refusals.
     */
    private function releaseAction(): Action
    {
        return Action::make('release')
            ->label(__('taskcards.release.action'))
            ->icon('heroicon-o-shield-check')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('taskcards.release.help.freezes'))
            ->visible(fn (WorkOrder $record): bool => ! $record->isReleased()
                && (auth()->user()?->can(Permissions::CARDS_CERTIFY) ?? false))
            ->disabled(fn (WorkOrder $record): bool => app(IssueRelease::class)
                ->refusalFor($record, auth()->user()) !== null)
            ->tooltip(fn (WorkOrder $record): ?string => app(IssueRelease::class)
                ->refusalFor($record, auth()->user()))
            ->schema([
                TextInput::make('maintenance_data')
                    ->label(__('taskcards.release.field.maintenance_data'))
                    ->maxLength(255)
                    ->placeholder('AMP ASK 21, Ausgabe 4 / Wartungshandbuch Kap. 5'),

                // Prefilled from the assembled default, and editable -- because
                // the words above a signature belong to whoever signs.
                Textarea::make('statement')
                    ->label(__('taskcards.release.field.statement'))
                    ->rows(4)
                    ->helperText(__('taskcards.release.help.statement')),

                DatePicker::make('released_at')
                    ->label(__('taskcards.release.field.released_at'))
                    ->default(now())
                    ->required(),
            ])
            ->action(function (array $data): void {
                try {
                    // Auf Namen: drei ?string nebeneinander -- positionsgebunden
                    // wuerde ein Einschub in der Mitte still alles verschieben.
                    $release = app(IssueRelease::class)->handle(
                        order: $this->record,
                        user: auth()->user(),
                        maintenanceData: $data['maintenance_data'] ?? null,
                        statement: $data['statement'] ?? null,
                        releasedAt: $data['released_at'] ?? null,
                    );
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('taskcards.release.issued', ['number' => $release->number]))
                    ->body(__('taskcards.release.help.freezes'))
                    ->persistent()
                    ->send();
            });
    }

    /**
     * A correction: a new certificate that says what was wrong with the old one.
     */
    private function correctReleaseAction(): Action
    {
        return Action::make('correctRelease')
            ->label(__('taskcards.release.correct'))
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->visible(fn (WorkOrder $record): bool => $record->currentRelease() !== null
                && (auth()->user()?->can(Permissions::CARDS_CERTIFY) ?? false))
            ->modalDescription(__('taskcards.release.help.correction'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('taskcards.release.field.correction_reason'))
                    ->required()
                    ->rows(2),

                Textarea::make('statement')
                    ->label(__('taskcards.release.field.statement'))
                    ->rows(4)
                    ->helperText(__('taskcards.release.help.statement')),
            ])
            ->action(function (array $data): void {
                $current = $this->record->currentRelease();

                if ($current === null) {
                    return;
                }

                try {
                    $correction = app(IssueRelease::class)->correct(
                        $current,
                        auth()->user(),
                        (string) $data['reason'],
                        $data['statement'] ?? null,
                    );
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('taskcards.release.issued', ['number' => $correction->number]))
                    ->send();
            });
    }

    private function addCardAction(): Action
    {
        return Action::make('addCard')
            ->label(__('taskcards.work_order.action.add_card'))
            ->icon('heroicon-o-plus')
            ->visible(fn (WorkOrder $record): bool => $record->isOpen()
                && (auth()->user()?->can(Permissions::CARDS_WORK) ?? false))
            ->schema([
                TextInput::make('title')
                    ->label(__('taskcards.card.field.title'))
                    ->required()
                    ->maxLength(160),

                Select::make('activity_kind')
                    ->label(__('taskcards.card.field.activity_kind'))
                    ->options(collect(ActivityKind::cases())
                        ->mapWithKeys(fn (ActivityKind $k): array => [$k->value => $k->label()])
                        ->all())
                    ->default(ActivityKind::Maintenance->value)
                    ->selectablePlaceholder(false)
                    ->required(),

                // Free text with suggestions: gliding often does not keep ATA at
                // all, and a fixed list would force somebody to find a chapter
                // where none fits.
                Select::make('ata_chapter')
                    ->label(__('taskcards.card.field.ata_chapter'))
                    ->options(self::ataSuggestions())
                    ->searchable()
                    ->allowHtml(false)
                    ->createOptionForm([
                        TextInput::make('ata_chapter')->label(__('taskcards.card.field.ata_chapter')),
                    ])
                    ->helperText(__('taskcards.card.help.ata')),

                // The bridge to the fleet: a card raised against a due limit
                // discharges it when signed.
                Select::make('component_limit_id')
                    ->label(__('taskcards.card.field.for_limit'))
                    ->options(fn (WorkOrder $record): array => self::dueLimits($record))
                    ->searchable()
                    ->helperText(__('taskcards.card.help.certify_discharges')),

                Textarea::make('instruction')
                    ->label(__('taskcards.card.field.instruction'))
                    ->rows(3)
                    ->columnSpanFull(),

                /*
                 * Nach welchem Stand gearbeitet wird -- gespeichert als KOPIE
                 * (MaintenanceManual::snapshot()), nie als Verweis: Ein Verweis
                 * wuerde mitwandern und die Karte rueckwirkend behaupten
                 * lassen, nach dem neuen Stand gearbeitet worden zu sein.
                 * Sichtbar nur, wenn es fuer dieses Luftfahrzeug ueberhaupt
                 * geltende Unterlagen gibt -- ein leeres Pflichtdropdown
                 * waere eine Frage ohne moegliche Antwort.
                 */
                Select::make('maintenance_manual_id')
                    ->label(__('taskcards.card.field.manual_reference'))
                    ->options(fn (WorkOrder $record): array => self::currentManuals($record))
                    ->searchable()
                    ->helperText(__('taskcards.card.help.manual_reference'))
                    ->visible(fn (WorkOrder $record): bool => self::currentManuals($record) !== []),

                /*
                 * BEIM ANLEGEN, nicht spaeter: Wer die Markierung nachtraeglich
                 * setzen oder wegnehmen koennte, koennte die Kontrolle nach
                 * Bedarf an- und abschalten.
                 */
                Toggle::make('critical')
                    ->label(__('taskcards.card.field.critical'))
                    ->helperText(__('taskcards.inspection.help.critical'))
                    ->live()
                    ->columnSpanFull(),

                TextInput::make('critical_reason')
                    ->label(__('taskcards.card.field.critical_reason'))
                    ->helperText(__('taskcards.inspection.help.reason'))
                    ->maxLength(160)
                    ->required()
                    ->visible(fn (Get $get): bool => (bool) $get('critical'))
                    ->columnSpanFull(),
            ])
            ->action(function (array $data): void {
                // Der Abdruck entsteht JETZT, beim Anlegen -- aus der gerade
                // geltenden Unterlage, nicht aus einer gespeicherten ID.
                $manual = isset($data['maintenance_manual_id'])
                    ? MaintenanceManual::query()->find($data['maintenance_manual_id'])
                    : null;

                try {
                    // Auf Namen: neun Parameter, mehrere gleichtypig -- die
                    // Fehlerklasse "Einschub verschiebt still alles danach"
                    // hat dieses Projekt schon zweimal erwischt.
                    app(ManageWorkOrder::class)->addCard(
                        order: $this->record,
                        title: (string) $data['title'],
                        instruction: $data['instruction'] ?? null,
                        kind: ActivityKind::from($data['activity_kind']),
                        ataChapter: $data['ata_chapter'] ?? null,
                        forLimit: isset($data['component_limit_id'])
                            ? ComponentLimit::find($data['component_limit_id'])
                            : null,
                        critical: (bool) ($data['critical'] ?? false),
                        criticalReason: $data['critical_reason'] ?? null,
                        manualReference: $manual?->snapshot(),
                    );
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title(__('taskcards.card.singular'))->send();
            });
    }

    /**
     * Die geltenden Wartungsunterlagen dieses Luftfahrzeugs, fuer die Auswahl.
     *
     * @return array<int, string>
     */
    private static function currentManuals(WorkOrder $record): array
    {
        $aircraft = $record->aircraft;

        if ($aircraft === null) {
            return [];
        }

        return MaintenanceManual::query()
            ->current()
            ->for($aircraft)
            ->orderBy('title')
            ->get()
            ->mapWithKeys(fn (MaintenanceManual $manual): array => [$manual->id => $manual->label()])
            ->all();
    }

    private function recordTimeAction(): Action
    {
        return Action::make('recordTime')
            ->label(__('taskcards.card.action.record_time'))
            ->icon('heroicon-o-clock')
            ->visible(fn (WorkOrder $record): bool => $record->taskCards()->exists()
                && (auth()->user()?->can(Permissions::CARDS_WORK) ?? false))
            ->modalDescription(__('taskcards.time.help.per_person'))
            ->schema(fn (WorkOrder $record): array => [
                Select::make('task_card_id')
                    ->label(__('taskcards.card.singular'))
                    ->options($this->cardOptions($record, onlyOpen: true))
                    ->searchable()
                    ->required(),

                Select::make('user_id')
                    ->label(__('taskcards.time.field.person'))
                    ->options(fn (): array => User::where('is_active', true)
                        ->orderBy('name')->pluck('name', 'id')->all())
                    ->default(auth()->id())
                    ->searchable()
                    ->required(),

                Select::make('participation')
                    ->label(__('taskcards.time.field.participation'))
                    ->options(collect(ParticipationKind::cases())
                        ->mapWithKeys(fn (ParticipationKind $p): array => [$p->value => $p->label()])
                        ->all())
                    ->default(ParticipationKind::Executed->value)
                    ->selectablePlaceholder(false)
                    ->required(),

                /*
                 * Nimmt beides: "90" und "1:30" -- und schreibt beim Verlassen
                 * des Feldes die eine Anzeige hin ("1:30"). Feldtest: Auf dem
                 * Werkstattzettel steht hh:mm, nicht Minuten. Gespeichert wird
                 * weiter in Minuten (WorkDuration uebersetzt an der Kante).
                 */
                TextInput::make('minutes')
                    ->label(__('taskcards.time.field.minutes'))
                    ->placeholder('1:30')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('minutes', WorkDuration::normalise($state)))
                    ->rule(static fn (): \Closure => static function (string $attribute, mixed $value, \Closure $fail): void {
                        if (WorkDuration::parse(is_string($value) ? $value : null) === null) {
                            $fail(__('taskcards.time.invalid'));
                        }
                    })
                    ->helperText(__('taskcards.time.help.minutes')),

                DatePicker::make('worked_on')
                    ->label(__('taskcards.time.field.worked_on'))
                    ->default(now())
                    ->required(),
            ])
            ->action(function (array $data): void {
                $card = TaskCard::find($data['task_card_id'] ?? null);
                $person = User::find($data['user_id'] ?? null);

                if ($card === null || $person === null) {
                    return;
                }

                try {
                    app(ManageWorkOrder::class)->recordTime(
                        $card,
                        $person,
                        (int) WorkDuration::parse((string) $data['minutes']),
                        ParticipationKind::from($data['participation']),
                        $data['worked_on'] ?? null,
                    );
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title(__('taskcards.time.singular'))->send();
            });
    }

    /**
     * First signature. Permission only -- the person who did it is the person
     * who knows.
     */
    private function completeCardAction(): Action
    {
        return Action::make('completeCard')
            ->label(__('taskcards.card.action.complete'))
            ->icon('heroicon-o-check')
            ->visible(fn (WorkOrder $record): bool => $record->taskCards()->open()->exists()
                && (auth()->user()?->can(Permissions::CARDS_WORK) ?? false))
            ->modalDescription(__('taskcards.card.help.two_signatures'))
            ->schema(fn (WorkOrder $record): array => [
                Select::make('task_card_id')
                    ->label(__('taskcards.card.singular'))
                    ->options($this->cardOptions($record, onlyOpen: true))
                    ->searchable()
                    ->required(),

                Textarea::make('work_performed')
                    ->label(__('taskcards.card.field.work_performed'))
                    ->required()
                    ->rows(3)
                    ->helperText(__('taskcards.card.help.work_performed')),
            ])
            ->action(function (array $data): void {
                $card = TaskCard::find($data['task_card_id'] ?? null);

                if ($card === null) {
                    return;
                }

                try {
                    app(CertifyTaskCard::class)->complete(
                        $card, auth()->user(), (string) $data['work_performed'],
                    );
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('taskcards.state.completed'))
                    ->body(__('taskcards.card.help.two_signatures'))
                    ->send();
            });
    }

    /**
     * Das zweite Augenpaar bei einer kritischen Arbeit.
     *
     * Steht VOR der Freigabe in der Reihenfolge und in dieser Datei, weil
     * die Freigabe ohne sie nicht durchgeht.
     */
    private function inspectCardAction(): Action
    {
        return Action::make('inspectCard')
            ->label(__('taskcards.card.action.inspect'))
            ->icon('heroicon-o-eye')
            ->color('warning')
            ->modalDescription(__('taskcards.inspection.help.four_eyes'))
            /*
             * Sichtbar nur, wenn es ueberhaupt eine Karte gibt, die DIESE
             * Person kontrollieren darf. Ein Knopf, der beim Druecken erklaert,
             * warum es nicht geht, ist schlechter als keiner.
             */
            ->visible(fn (WorkOrder $record): bool => self::inspectableCards($record) !== [])
            ->schema(fn (WorkOrder $record): array => [
                Select::make('task_card_id')
                    ->label(__('taskcards.card.singular'))
                    ->options(self::inspectableCards($record))
                    ->searchable()
                    ->required(),

                Textarea::make('note')
                    ->label(__('taskcards.card.field.inspection_note'))
                    ->helperText(__('taskcards.inspection.help.note'))
                    ->rows(3)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $card = TaskCard::find($data['task_card_id'] ?? null);

                if ($card === null) {
                    return;
                }

                try {
                    app(InspectCriticalTask::class)->handle($card, auth()->user(), (string) ($data['note'] ?? ''));
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title(__('taskcards.inspection.done'))->send();
            });
    }

    /**
     * Karten, die diese Person kontrollieren darf.
     *
     * Die Ausschlussregel steckt in InspectCriticalTask::mayInspect und nicht
     * hier -- die Oberfläche fragt, sie entscheidet nicht.
     *
     * @return array<int, string>
     */
    private static function inspectableCards(WorkOrder $record): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $aktion = app(InspectCriticalTask::class);

        return $record->taskCards()
            ->where('critical', true)
            ->get()
            ->filter(fn (TaskCard $c): bool => $aktion->mayInspect($c, $user))
            ->mapWithKeys(fn (TaskCard $c): array => [$c->id => $c->label().' — '.($c->critical_reason ?? '')])
            ->all();
    }

    private function certifyCardAction(): Action
    {
        return Action::make('certifyCard')
            ->label(__('taskcards.card.action.certify'))
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (WorkOrder $record): bool => $record->taskCards()->awaitingCertification()->exists()
                && (auth()->user()?->can(Permissions::CARDS_CERTIFY) ?? false))
            ->modalDescription(__('taskcards.card.help.certify_discharges'))
            ->schema(fn (WorkOrder $record): array => [
                Select::make('task_card_id')
                    ->label(__('taskcards.card.singular'))
                    ->options($record->taskCards()->awaitingCertification()->get()
                        ->mapWithKeys(fn (TaskCard $c): array => [$c->id => $c->label()])
                        ->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $card = TaskCard::find($data['task_card_id'] ?? null);

                if ($card === null) {
                    return;
                }

                try {
                    app(CertifyTaskCard::class)->certify($card, auth()->user());
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title(__('taskcards.state.certified'))->send();
            });
    }

    private function recordFindingAction(): Action
    {
        return Action::make('recordFinding')
            ->label(__('taskcards.finding.action.record'))
            ->icon('heroicon-o-exclamation-triangle')
            ->color('warning')
            ->visible(fn (): bool => auth()->user()?->can(Permissions::FINDINGS_RECORD) ?? false)
            ->modalDescription(__('taskcards.finding.help.why_own'))
            ->schema(fn (WorkOrder $record): array => [
                Select::make('task_card_id')
                    ->label(__('taskcards.card.singular'))
                    ->options($this->cardOptions($record))
                    ->searchable()
                    ->helperText(__('taskcards.finding.help.why_own')),

                TextInput::make('title')
                    ->label(__('taskcards.finding.field.title'))
                    ->required()
                    ->maxLength(160),

                Textarea::make('description')
                    ->label(__('taskcards.finding.field.description'))
                    ->required()
                    ->rows(3)
                    ->columnSpanFull(),

                Checkbox::make('is_blocking')
                    ->label(__('taskcards.finding.field.is_blocking'))
                    ->default(true)
                    ->helperText(__('taskcards.finding.help.blocking')),
            ])
            ->action(function (array $data): void {
                try {
                    app(RecordFinding::class)->record(
                        $this->record->aircraft,
                        (string) $data['title'],
                        (string) $data['description'],
                        auth()->user(),
                        (bool) ($data['is_blocking'] ?? true),
                        isset($data['task_card_id']) ? TaskCard::find($data['task_card_id']) : null,
                    );
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->warning()->title(__('taskcards.finding.singular'))->send();
            });
    }

    /**
     * Taking a part out of the store for a card.
     *
     * Only offered where there is a store: a club with cards and no warehouse is
     * a real arrangement, and the cards still work.
     */
    private function issuePartAction(): Action
    {
        return Action::make('issuePart')
            ->label(__('taskcards.parts.action'))
            ->icon('heroicon-o-archive-box-arrow-down')
            ->visible(fn (WorkOrder $record): bool => app(IssuePartToCard::class)->isAvailable()
                && $record->taskCards()->exists()
                && (auth()->user()?->can(WarehousePermissions::STOCK_ISSUE) ?? false))
            ->modalDescription(__('taskcards.parts.help.through_warehouse'))
            ->schema(fn (WorkOrder $record): array => [
                Select::make('task_card_id')
                    ->label(__('taskcards.card.singular'))
                    ->options($this->cardOptions($record, onlyOpen: true))
                    ->searchable()
                    ->required(),

                /*
                 * ─────────────────────────────────────────────────────────
                 * DER SCAN, UND ER SPART MEHR ALS TIPPARBEIT.
                 *
                 * Vorgabe: „Techniker holt teil aus schrank, scannt es und
                 * muss nicht weiter suchen und nummern tippen. Außerdem haben
                 * wir damit automatisch zur Freigabe die richtigen form 1."
                 *
                 * Der zweite Satz ist der wichtige. Ohne Scan waehlt der
                 * Mensch ein Los aus einer Liste -- oder laesst das Feld leer,
                 * dann greift FEFO und nimmt das aelteste. FEFO ist eine
                 * ANNAHME darueber, welche Packung in der Hand lag. Griff er
                 * die danebenliegende, haengt an der Freigabe das FALSCHE
                 * FORM 1, und niemand merkt es: Die Buchung sieht plausibel
                 * aus.
                 *
                 * Der Scan ersetzt die Annahme durch eine Beobachtung. Das
                 * ist kein Komfort, das ist die Nachweiskette aus CLAUDE.md.
                 *
                 * Er setzt beide Felder, weil ein Los weiss, zu welchem
                 * Bauteil es gehoert -- ein Griff statt zwei.
                 * ─────────────────────────────────────────────────────────
                 */
                ViewField::make('scan')
                    ->label(__('warehouse.scan.field'))
                    ->view('warehouse.filament.fields.scan')
                    ->helperText(__('warehouse.scan.help'))
                    ->dehydrated(false)
                    ->live()
                    ->afterStateUpdated(function (?string $state, callable $set): void {
                        if (blank($state)) {
                            return;
                        }

                        self::applyScannedCode(trim($state), $set);
                    }),

                Select::make('part_type_id')
                    ->label(__('warehouse.part_type.singular'))
                    ->options(fn (): array => PartType::orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required()
                    ->live(),

                // Offered through the warehouse's own rules, so a removal lot
                // tied to another aircraft simply is not in the list.
                Select::make('stock_lot_id')
                    ->label(__('warehouse.issue.field.lot'))
                    ->options(fn (callable $get): array => $this->lotOptions(
                        $get('part_type_id'),
                        $this->record->aircraft?->registration,
                    ))
                    ->searchable()
                    ->visible(fn (callable $get): bool => filled($get('part_type_id')))
                    ->helperText(__('taskcards.parts.help.aircraft')),

                TextInput::make('quantity')
                    ->label(__('warehouse.issue.field.quantity'))
                    ->numeric()
                    ->minValue(0.001)
                    ->required()
                    ->default(1),

                Textarea::make('note')
                    ->label(__('warehouse.issue.field.note'))
                    ->rows(2),
            ])
            ->action(function (array $data): void {
                $card = TaskCard::find($data['task_card_id'] ?? null);
                $part = PartType::find($data['part_type_id'] ?? null);

                if ($card === null || $part === null) {
                    return;
                }

                try {
                    app(IssuePartToCard::class)->handle(
                        $card,
                        $part,
                        (float) $data['quantity'],
                        auth()->user(),
                        isset($data['stock_lot_id']) ? StockLot::find($data['stock_lot_id']) : null,
                        $data['note'] ?? null,
                    );
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('warehouse.issue.notification.refused'))
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()->success()->title(__('taskcards.parts.issued'))->send();
            });
    }

    /**
     * Lots the warehouse would allow for this aircraft.
     *
     * Filtered by the warehouse's own rule rather than a copy of it -- a lot
     * removed from another aircraft without a Form 1 is simply not offered.
     *
     * @return array<int, string>
     */
    private function lotOptions(mixed $partTypeId, ?string $registration): array
    {
        if (! filled($partTypeId)) {
            return [];
        }

        $part = PartType::find($partTypeId);

        if ($part === null) {
            return [];
        }

        return $part->lots()->issuable()->fefo()->get()
            ->filter(fn (StockLot $lot): bool => $lot->mayBeFittedTo($registration))
            ->mapWithKeys(fn (StockLot $lot): array => [
                $lot->id => sprintf(
                    '%s — %s %s',
                    $lot->label(),
                    rtrim(rtrim(number_format($lot->remainingQuantity(), 3, ',', '.'), '0'), ','),
                    $part->unit_of_measure,
                ),
            ])
            ->all();
    }

    /**
     * A card that turns out not to be needed.
     *
     * Without this a superfluous card blocks the visit for ever: closing
     * requires every card to be certified or cancelled, and an unnecessary one
     * can be neither. The way out was in the action all along and had no button.
     *
     * A signed card is never cancelled -- that would erase a signature. It gets
     * a new card instead, which is the same rule as everywhere else here.
     */
    private function cancelCardAction(): Action
    {
        return Action::make('cancelCard')
            ->label(__('taskcards.card.action.cancel'))
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->visible(fn (WorkOrder $record): bool => $record->taskCards()
                ->whereNotIn('state', ['certified', 'cancelled'])->exists()
                && (auth()->user()?->can(Permissions::CARDS_WORK) ?? false))
            ->schema(fn (WorkOrder $record): array => [
                Select::make('task_card_id')
                    ->label(__('taskcards.card.singular'))
                    ->options($record->taskCards()
                        ->whereNotIn('state', ['certified', 'cancelled'])
                        ->get()
                        ->mapWithKeys(fn (TaskCard $c): array => [$c->id => $c->label()])
                        ->all())
                    ->searchable()
                    ->required(),

                Textarea::make('reason')
                    ->label(__('taskcards.card.field.cancellation_reason'))
                    ->required()
                    ->rows(2)
                    ->helperText(__('taskcards.card.help.cancel')),
            ])
            ->action(function (array $data): void {
                $card = TaskCard::find($data['task_card_id'] ?? null);

                if ($card === null) {
                    return;
                }

                try {
                    app(CertifyTaskCard::class)->cancel(
                        $card, auth()->user(), (string) $data['reason'],
                    );
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title(__('taskcards.state.cancelled'))->send();
            });
    }

    /**
     * Raising a card for a finding.
     *
     * The path the requirement described as the core loop -- finding becomes work -- and
     * it existed only in the action. The finding does not close here; it becomes
     * scheduled, and closes when the card that fixes it is signed off.
     *
     * Findings from anywhere on this aircraft are offered, not only ones noticed
     * during this visit: a crack found in March is dealt with at the next
     * opportunity, which is what a visit is.
     */
    private function scheduleFindingAction(): Action
    {
        return Action::make('scheduleFinding')
            ->label(__('taskcards.finding.action.schedule'))
            ->icon('heroicon-o-wrench')
            ->visible(fn (WorkOrder $record): bool => $record->isOpen()
                && $this->openFindings($record) !== []
                && (auth()->user()?->can(Permissions::CARDS_WORK) ?? false))
            ->modalDescription(__('taskcards.finding.help.schedule'))
            ->schema(fn (WorkOrder $record): array => [
                Select::make('finding_id')
                    ->label(__('taskcards.finding.singular'))
                    ->options($this->openFindings($record))
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $finding = Finding::find($data['finding_id'] ?? null);

                if ($finding === null) {
                    return;
                }

                try {
                    $card = app(RecordFinding::class)->schedule(
                        $finding, $this->record, auth()->user(),
                    );
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

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
     * Findings on this aircraft that nobody has dealt with.
     *
     * @return array<int, string>
     */
    private function openFindings(WorkOrder $order): array
    {
        return Finding::where('aircraft_id', $order->aircraft_id)
            ->whereIn('state', ['open', 'deferred'])
            ->get()
            ->mapWithKeys(fn (Finding $f): array => [$f->id => $f->label()])
            ->all();
    }

    private function closeAction(): Action
    {
        return Action::make('close')
            ->label(__('taskcards.work_order.action.close'))
            ->icon('heroicon-o-lock-closed')
            ->requiresConfirmation()
            ->modalDescription(__('taskcards.work_order.help.close'))
            ->visible(fn (WorkOrder $record): bool => $record->isOpen()
                && (auth()->user()?->can(Permissions::WORK_ORDERS_MANAGE) ?? false))
            ->action(function (): void {
                try {
                    app(ManageWorkOrder::class)->close($this->record, auth()->user());
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()->success()->title(__('taskcards.work_order.state.closed'))->send();
            });
    }

    /** @return array<int, string> */
    private function cardOptions(WorkOrder $order, bool $onlyOpen = false): array
    {
        return $order->taskCards()
            ->when($onlyOpen, fn ($q) => $q->open())
            ->get()
            ->mapWithKeys(fn (TaskCard $c): array => [$c->id => $c->label()])
            ->all();
    }

    /**
     * Fleet limits on this aircraft that are due or past due.
     *
     * Only those: offering every limit ever entered would bury the two that
     * matter under thirty that do not.
     *
     * @return array<int, string>
     */
    private static function dueLimits(WorkOrder $order): array
    {
        $options = [];

        $installations = Installation::where('aircraft_id', $order->aircraft_id)
            ->whereNull('removed_at')
            ->with('limits')
            ->get();

        foreach ($installations as $installation) {
            foreach ($installation->limits as $limit) {
                if (! $limit->status()->needsAttention()) {
                    continue;
                }

                $options[$limit->id] = sprintf(
                    '%s — %s (%s)',
                    $installation->label(),
                    $limit->describe(),
                    $limit->status()->label(),
                );
            }
        }

        return $options;
    }

    /** @return array<string, string> */
    private static function ataSuggestions(): array
    {
        // The chapters a gliding club actually touches, as suggestions. Not a
        // constraint: see the helper text.
        return [
            '05' => '05 — Zeitgrenzen, Kontrollen',
            '11' => '11 — Beschriftungen',
            '20' => '20 — Standardverfahren',
            '21' => '21 — Klimaanlage / Belüftung',
            '22' => '22 — Autopilot',
            '23' => '23 — Kommunikation',
            '24' => '24 — Elektrik',
            '25' => '25 — Ausrüstung / Kabine',
            '27' => '27 — Steuerung',
            '28' => '28 — Kraftstoff',
            '31' => '31 — Instrumente',
            '32' => '32 — Fahrwerk',
            '34' => '34 — Navigation',
            '51' => '51 — Struktur allgemein',
            '52' => '52 — Hauben / Türen',
            '53' => '53 — Rumpf',
            '55' => '55 — Leitwerk',
            '57' => '57 — Tragflächen',
            '61' => '61 — Propeller',
            '71' => '71 — Triebwerk',
            '76' => '76 — Triebwerkssteuerung',
            '79' => '79 — Ölsystem',
        ];
    }

    /**
     * Einen gescannten oder abgetippten Code auf das Formular anwenden.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * WAS EIN CODE BEDEUTET, ENTSCHEIDET DER SERVER. Der Browser liefert eine
     * Zeichenkette, sonst nichts -- alles Weitere macht `ResolveScanCode`.
     * Anders herum haette ein Telefon darueber zu befinden, welches Los gebucht
     * wird, und das ist genau die Stelle, an der die Nachweiskette haengt.
     *
     * Jede der drei moeglichen Antworten wird auch AUSGESPROCHEN: „fremder
     * Code", „kennen wir nicht mehr" und der Treffer. Ein stilles Nichts liesse
     * jemanden vor dem Regal raten, ob er falsch gescannt hat.
     *
     * OEFFENTLICH, DAMIT ES PRUEFBAR IST -- wie UserResource::canLock. Der
     * Alternativweg waere ein Test, der die halbe Anwendung neu baut, um eine
     * Filament-Aktion einzuhaengen; fuer eine Funktion, die aus einer
     * Zeichenkette zwei Feldwerte macht, ist das der falsche Preis.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public static function applyScannedCode(string $code, callable $set): void
    {
        $ergebnis = app(ResolveScanCode::class)->handle($code);

        if ($ergebnis['status'] === ResolveScanCode::FOREIGN) {
            Notification::make()->warning()->title(__('warehouse.scan.foreign'))->send();

            return;
        }

        if ($ergebnis['status'] === ResolveScanCode::UNKNOWN) {
            Notification::make()->warning()->title(__('warehouse.scan.unknown'))->send();

            return;
        }

        /*
         * Ein Regalschild ist ein gueltiger Code -- nur nicht hier. Das
         * ausdruecklich zu sagen ist besser als es zu ignorieren: Wer im Lager
         * steht, hat dann das falsche Etikett gescannt und weiss es sofort.
         */
        if ($ergebnis['kind'] !== ScanCode::KIND_LOT) {
            Notification::make()->warning()->title(__('warehouse.scan.not_a_lot'))->send();

            return;
        }

        /** @var StockLot $lot */
        $lot = $ergebnis['record'];

        $set('part_type_id', $lot->part_type_id);
        $set('stock_lot_id', $lot->getKey());

        Notification::make()
            ->success()
            ->title(__('warehouse.scan.applied', ['lot' => $lot->lot_number]))
            ->send();
    }
}
