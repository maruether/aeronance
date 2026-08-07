<?php

declare(strict_types=1);

namespace App\Modules\Directives\Filament\Resources\Directives\Pages;

use App\Modules\Directives\Actions\AssessDirective;
use App\Modules\Directives\Actions\ImportDirectives;
use App\Modules\Directives\Actions\ScheduleDirectiveCard;
use App\Modules\Directives\Enums\ComplianceState;
use App\Modules\Directives\Filament\Resources\Directives\DirectiveResource;
use App\Modules\Directives\Models\Directive;
use App\Modules\Directives\Permissions;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Models\WorkOrder;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Throwable;

/**
 * One line, and what every affected aircraft says about it.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE SCREEN THE MODULE EXISTS FOR: "die Übersicht die ich dann bestätigen kann
 * (zeile für zeile)".
 *
 * Three buttons, not two -- and their order is deliberate. "Durchgeführt" first
 * because it is the common case, then the two negative answers, which look alike
 * on screen and mean very different things: one says the line does not concern
 * this aircraft, the other says it does and has not been done.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ViewDirective extends ViewRecord
{
    protected static string $resource = DirectiveResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (Directive $record): string => $record->label())
                ->schema([
                    TextEntry::make('title')->label(__('directives.field.title'))->columnSpanFull(),
                    TextEntry::make('kind')
                        ->label(__('directives.field.kind'))
                        ->badge()
                        ->formatStateUsing(fn ($state): string => $state->label())
                        ->color(fn ($state): string => $state->isMandatory() ? 'danger' : 'gray'),
                    TextEntry::make('bindingness')
                        ->label(__('directives.field.bindingness'))
                        ->badge()
                        ->formatStateUsing(fn ($state): string => $state->label())
                        ->color(fn ($state): string => $state->color()),
                    TextEntry::make('issuer')->label(__('directives.field.issuer'))->placeholder('—'),
                    TextEntry::make('issued_at')->label(__('directives.field.issued_at'))->date('d.m.Y')->placeholder('—'),
                    TextEntry::make('comply_before')
                        ->label(__('directives.field.comply_before'))
                        ->date('d.m.Y')
                        ->placeholder('—')
                        ->color(fn (Directive $r): ?string => $r->isOverdue() ? 'danger' : null),
                    TextEntry::make('subject')
                        ->label(__('directives.field.subject_kind'))
                        ->state(fn (Directive $r): string => self::subject($r))
                        ->columnSpanFull(),
                    TextEntry::make('recurrence')
                        ->label(__('directives.field.is_recurring'))
                        ->state(fn (Directive $r): string => self::recurrence($r))
                        ->visible(fn (Directive $r): bool => $r->is_recurring),
                    TextEntry::make('summary')->label(__('directives.field.summary'))->placeholder('—')->columnSpanFull(),
                    TextEntry::make('reference_url')
                        ->label(__('directives.field.reference_url'))
                        ->url(fn (Directive $r): ?string => $r->reference_url, shouldOpenInNewTab: true)
                        ->placeholder('—')
                        ->columnSpanFull(),

                    // Superseded lines say so plainly and point at their successor.
                    TextEntry::make('superseded')
                        ->label(__('directives.field.superseded_by'))
                        ->state(fn (Directive $r): string => $r->supersededBy?->label() ?? '')
                        ->badge()
                        ->color('warning')
                        ->visible(fn (Directive $r): bool => $r->isSuperseded())
                        ->columnSpanFull(),
                ])
                ->columns(3),

            Section::make(__('directives.field.aircraft'))
                ->description(__('directives.help.four_states'))
                ->schema([
                    TextEntry::make('applications')
                        ->hiddenLabel()
                        ->state(fn (Directive $record): string => self::fleetLines($record))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->assessAction('comply', ComplianceState::Complied),
            $this->assessAction('notApplicable', ComplianceState::NotApplicable),
            $this->assessAction('notCarriedOut', ComplianceState::NotCarriedOut),
            $this->scheduleCardAction(),
            $this->supersedeAction(),
            EditAction::make()->schema(ListDirectives::formSchema()),
        ];
    }

    /**
     * One action shape for all three answers.
     *
     * Written once rather than three times, because the difference between them
     * is genuinely only the state and which field is mandatory -- and three
     * near-identical copies is how the reason requirement gets forgotten in one
     * of them.
     */
    private function assessAction(string $name, ComplianceState $state): Action
    {
        return Action::make($name)
            ->label(__('directives.action.'.match ($state) {
                ComplianceState::Complied => 'comply',
                ComplianceState::NotApplicable => 'not_applicable',
                default => 'not_carried_out',
            }))
            ->icon(match ($state) {
                ComplianceState::Complied => 'heroicon-o-check',
                ComplianceState::NotApplicable => 'heroicon-o-minus-circle',
                default => 'heroicon-o-exclamation-triangle',
            })
            ->color($state->color())
            ->visible(fn (Directive $record): bool => ! $record->isSuperseded()
                && (auth()->user()?->can(Permissions::DIRECTIVES_ASSESS) ?? false))
            ->modalDescription(fn (): string => $state === ComplianceState::NotApplicable
                ? __('directives.help.not_applicable_stays')
                : __('directives.help.qualification'))
            ->schema(fn (Directive $record): array => array_values(array_filter([
                Select::make('aircraft_id')
                    ->label(__('directives.field.aircraft'))
                    ->options(fn (): array => $record->candidateAircraft()
                        ->mapWithKeys(fn (Aircraft $a): array => [$a->id => $a->registration.' — '.$a->model])
                        ->all())
                    ->searchable()
                    ->required()
                    ->helperText(__('directives.help.candidates')),

                $state === ComplianceState::Complied
                    ? Textarea::make('method')
                        ->label(__('directives.field.method'))
                        ->required()
                        ->rows(2)
                    : Textarea::make('reason')
                        ->label(__('directives.field.reason'))
                        ->required()
                        ->rows(2)
                        ->helperText(__('directives.help.reason_required')),

                $state === ComplianceState::Complied
                    ? TextInput::make('task_card_reference')
                        ->label(__('directives.field.task_card'))
                        ->maxLength(64)
                        ->helperText(__('directives.help.task_card'))
                    : null,

                DatePicker::make('on')
                    ->label(__('directives.field.assessed_at'))
                    ->default(now())
                    ->required(),
            ])))
            ->action(function (array $data) use ($state): void {
                $aircraft = Aircraft::find($data['aircraft_id'] ?? null);

                if ($aircraft === null) {
                    return;
                }

                try {
                    match ($state) {
                        ComplianceState::Complied => app(AssessDirective::class)->comply(
                            $this->record, $aircraft, auth()->user(),
                            (string) $data['method'], $data['on'] ?? null,
                            $data['task_card_reference'] ?? null,
                        ),
                        ComplianceState::NotApplicable => app(AssessDirective::class)->markNotApplicable(
                            $this->record, $aircraft, auth()->user(),
                            (string) $data['reason'], $data['on'] ?? null,
                        ),
                        default => app(AssessDirective::class)->markNotCarriedOut(
                            $this->record, $aircraft, auth()->user(),
                            (string) $data['reason'], $data['on'] ?? null,
                        ),
                    };
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('directives.notification.refused'))
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()->success()->title(__('directives.notification.assessed'))->send();
            });
    }

    /**
     * Raising a task card for this line.
     *
     * Offered only where there are task cards -- a club that keeps an LTA list
     * without work orders is a real arrangement, and the list works as a plain
     * tick there. Same shape as the parts issue.
     */
    private function scheduleCardAction(): Action
    {
        return Action::make('scheduleCard')
            ->label(__('directives.card.action'))
            ->icon('heroicon-o-wrench')
            ->color('gray')
            ->visible(fn (Directive $record): bool => app(ScheduleDirectiveCard::class)->isAvailable()
                && ! $record->isSuperseded()
                && (auth()->user()?->can(Permissions::DIRECTIVES_VIEW) ?? false))
            ->modalDescription(__('directives.card.help'))
            ->schema(fn (Directive $record): array => [
                Select::make('aircraft_id')
                    ->label(__('directives.field.aircraft'))
                    ->options(fn (): array => $record->candidateAircraft()
                        ->mapWithKeys(fn (Aircraft $a): array => [$a->id => $a->registration.' — '.$a->model])
                        ->all())
                    ->searchable()
                    ->required()
                    ->live(),

                // Only OPEN visits of the chosen aircraft: a card cannot be added
                // to a closed or released one, and offering those would be a
                // refusal after the click.
                Select::make('work_order_id')
                    ->label(__('taskcards.work_order.singular'))
                    ->options(fn (Get $get): array => filled($get('aircraft_id'))
                        ? WorkOrder::query()
                            ->where('aircraft_id', $get('aircraft_id'))
                            ->where('state', WorkOrder::STATE_OPEN)
                            ->orderByDesc('opened_at')
                            ->get()
                            ->mapWithKeys(fn ($o): array => [$o->id => $o->label()])
                            ->all()
                        : [])
                    ->required()
                    ->visible(fn (Get $get): bool => filled($get('aircraft_id'))),
            ])
            ->action(function (array $data): void {
                $aircraft = Aircraft::find($data['aircraft_id'] ?? null);
                $order = WorkOrder::find($data['work_order_id'] ?? null);

                if ($aircraft === null || $order === null) {
                    return;
                }

                try {
                    $card = app(ScheduleDirectiveCard::class)->handle(
                        $this->record, $aircraft, $order, auth()->user(),
                    );
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('directives.notification.refused'))
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('directives.card.created', ['number' => $card->number]))
                    ->body(__('directives.card.help'))
                    ->send();
            });
    }

    private function supersedeAction(): Action
    {
        return Action::make('supersede')
            ->label(__('directives.action.supersede'))
            ->icon('heroicon-o-arrow-right-circle')
            ->color('gray')
            ->visible(fn (Directive $record): bool => ! $record->isSuperseded()
                && (auth()->user()?->can(Permissions::DIRECTIVES_MANAGE) ?? false))
            ->modalDescription(__('directives.help.supersede'))
            ->schema(fn (Directive $record): array => [
                Select::make('new_id')
                    ->label(__('directives.field.superseded_by'))
                    ->options(fn (): array => Directive::query()
                        ->current()
                        ->whereKeyNot($record->id)
                        ->orderByDesc('issued_at')
                        ->get()
                        ->mapWithKeys(fn (Directive $d): array => [$d->id => $d->label().' — '.$d->title])
                        ->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $new = Directive::find($data['new_id'] ?? null);

                if ($new === null) {
                    return;
                }

                try {
                    app(ImportDirectives::class)->supersede($this->record, $new, auth()->user());
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title(__('directives.notification.assessed'))->send();
            });
    }

    /**
     * Every affected aircraft and what it says -- including the ones nobody has
     * answered for yet, which is the whole point of a line-by-line overview.
     */
    private static function fleetLines(Directive $directive): string
    {
        $assessed = $directive->applications->keyBy('aircraft_id');
        $lines = [];

        foreach ($directive->candidateAircraft() as $aircraft) {
            $application = $assessed->get($aircraft->id);

            $lines[] = sprintf(
                '%s: %s',
                $aircraft->registration,
                $application?->describe() ?? __('directives.open.unassessed'),
            );
        }

        // Aircraft that were assessed but no longer look affected -- a component
        // was removed, say. Kept visible: the assessment happened and stays part
        // of the record.
        foreach ($assessed as $application) {
            if (! $directive->candidateAircraft()->contains('id', $application->aircraft_id)) {
                $lines[] = sprintf('%s: %s', $application->aircraft_registration, $application->describe());
            }
        }

        return $lines === [] ? __('directives.open.no_candidates') : implode(' · ', $lines);
    }

    private static function subject(Directive $d): string
    {
        $parts = [$d->subject_kind->label()];

        foreach ([$d->subject_model, $d->subject_designation, $d->subject_part_number] as $v) {
            if (filled($v)) {
                $parts[] = $v;
            }
        }

        if (filled($d->serial_from) || filled($d->serial_to)) {
            $parts[] = sprintf('S/N %s–%s', $d->serial_from ?? '', $d->serial_to ?? '');
        }

        return implode(' · ', $parts);
    }

    private static function recurrence(Directive $d): string
    {
        $parts = [];

        if ($d->interval_months !== null) {
            $parts[] = $d->interval_months.' Monate';
        }

        if ($d->interval_counter !== null && $d->interval_value !== null) {
            $parts[] = sprintf(
                '%s %s',
                rtrim(rtrim(number_format((float) $d->interval_value, 2, ',', '.'), '0'), ','),
                __('fleet.counter.'.$d->interval_counter),
            );
        }

        return $parts === [] ? '—' : implode(' oder ', $parts);
    }
}
