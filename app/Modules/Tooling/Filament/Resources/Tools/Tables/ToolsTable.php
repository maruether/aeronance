<?php

declare(strict_types=1);

namespace App\Modules\Tooling\Filament\Resources\Tools\Tables;

use App\Models\User;
use App\Modules\Tooling\Actions\IssueTool;
use App\Modules\Tooling\Actions\RecordCalibration;
use App\Modules\Tooling\Enums\CalibrationResult;
use App\Modules\Tooling\Enums\ToolState;
use App\Modules\Tooling\Models\Tool;
use App\Modules\Tooling\Permissions;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Der Werkzeugbestand — sortiert nach dem, was drängt.
 *
 * Überfällige zuoberst: Die Liste wird nicht gelesen, um zu bewundern, was man
 * alles hat, sondern um zu sehen, was ins Labor muss.
 */
final class ToolsTable
{
    /**
     * Einen Kalibrierschein eintragen.
     *
     * Gefragt wird nach dem BEFUND, nicht nach dem Nachprüfzeitraum. Der Befund
     * steht auf dem Schein und kann nur ein Mensch ablesen; der Zeitraum ergibt
     * sich daraus und aus der Historie, und den rechnet RecordCalibration aus.
     * Ihn erfragen hieße, eine Angabe entgegenzunehmen, die das System besser
     * weiß.
     */
    public static function calibrate(): Action
    {
        return Action::make('calibrate')
            ->label(__('tooling.action.calibrate'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->visible(fn (Tool $record): bool => $record->calibration_required
                && (auth()->user()?->can(Permissions::TOOLS_MANAGE) ?? false))
            ->schema([
                DatePicker::make('performed_at')
                    ->label(__('tooling.field.performed_at'))
                    ->default(now()->toDateString())
                    ->maxDate(now())
                    ->required(),

                /*
                 * OHNE VORAUSWAHL. Der Befund entscheidet, ob eine Nachpruefung
                 * faellig wird -- ein vorbelegtes "in Ordnung" waere genau der
                 * Klick, den man macht, ohne auf den Schein zu sehen.
                 */
                Radio::make('result')
                    ->label(__('tooling.field.result'))
                    ->helperText(__('tooling.help.result'))
                    ->options(collect(CalibrationResult::cases())
                        ->mapWithKeys(fn (CalibrationResult $r): array => [$r->value => $r->label()])
                        ->all())
                    ->required(),

                DatePicker::make('valid_until')
                    ->label(__('tooling.field.valid_until'))
                    ->helperText(__('tooling.help.valid_until')),

                TextInput::make('provider')
                    ->label(__('tooling.field.provider'))
                    ->maxLength(255),

                TextInput::make('certificate_reference')
                    ->label(__('tooling.field.certificate_reference'))
                    ->maxLength(128),

                FileUpload::make('certificate')
                    ->label(__('tooling.field.certificate'))
                    ->disk('documents')
                    ->directory('tool-calibrations')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                    ->maxSize(10240),

                Textarea::make('note')
                    ->label(__('tooling.field.note'))
                    ->rows(2),
            ])
            ->action(function (Tool $record, array $data): void {
                try {
                    app(RecordCalibration::class)->handle(
                        tool: $record,
                        performedAt: $data['performed_at'],
                        result: CalibrationResult::from($data['result']),
                        validUntil: $data['valid_until'] ?? null,
                        provider: $data['provider'] ?? null,
                        certificateReference: $data['certificate_reference'] ?? null,
                        user: auth()->user(),
                        note: $data['note'] ?? null,
                    );

                    Notification::make()->success()->title(__('tooling.action.calibrated'))->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('tooling.action.failed'))
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();
                }
            });
    }

    /**
     * Ausgeben — mit der Sperre, die den Zweck trägt.
     *
     * Ein Werkzeug mit abgelaufener Kalibrierung wird gar nicht erst
     * herausgegeben; die Regel steckt in IssueTool und nicht hier.
     */
    public static function issue(): Action
    {
        return Action::make('issue')
            ->label(__('tooling.action.issue'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->visible(fn (Tool $record): bool => ! $record->isIssued()
                && (auth()->user()?->can(Permissions::TOOLS_ISSUE) ?? false))
            ->schema([
                Select::make('issued_to_id')
                    ->label(__('tooling.field_issue.issued_to'))
                    ->options(fn (): array => User::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->default(fn (): ?int => auth()->id())
                    ->searchable()
                    ->required(),

                /*
                 * Die Vorgangsnummer ist ein FREIES FELD und keine Auswahl aus
                 * den Arbeitskarten: Das Werkzeugmodul steht allein, und ein
                 * Verein ohne Arbeitskarten soll hier trotzdem etwas eintragen
                 * koennen. Eine Auswahlliste haette aus der optionalen eine
                 * harte Abhaengigkeit gemacht -- siehe die Migration.
                 */
                TextInput::make('work_order_reference')
                    ->label(__('tooling.field_issue.work_order_reference'))
                    ->helperText(__('tooling.issue.help.work_order'))
                    ->maxLength(64),

                DatePicker::make('due_back_at')
                    ->label(__('tooling.field_issue.due_back_at'))
                    ->helperText(__('tooling.issue.help.due_back'))
                    ->minDate(now()),

                Textarea::make('note')
                    ->label(__('tooling.field.note'))
                    ->rows(2),
            ])
            ->action(function (Tool $record, array $data): void {
                try {
                    app(IssueTool::class)->handle(
                        tool: $record,
                        to: User::findOrFail($data['issued_to_id']),
                        by: auth()->user(),
                        workOrderReference: $data['work_order_reference'] ?? null,
                        dueBackAt: $data['due_back_at'] ?? null,
                        note: $data['note'] ?? null,
                    );

                    Notification::make()->success()->title(__('tooling.action.issued'))->send();
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title(__('tooling.action.failed'))
                        ->body($e->getMessage())->persistent()->send();
                }
            });
    }

    /**
     * Zurücknehmen — ohne jede Bedingung.
     *
     * Ein Werkzeug, das sich nicht zurückbuchen lässt, weil inzwischen seine
     * Frist abgelaufen ist, bliebe für immer als „draußen" stehen.
     */
    public static function takeBack(): Action
    {
        return Action::make('takeBack')
            ->label(__('tooling.action.return'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (Tool $record): bool => $record->isIssued()
                && (auth()->user()?->can(Permissions::TOOLS_ISSUE) ?? false))
            ->schema([
                Textarea::make('note')
                    ->label(__('tooling.field.note'))
                    ->rows(2),
            ])
            ->action(function (Tool $record, array $data): void {
                $ausgabe = $record->currentIssue();

                if ($ausgabe === null) {
                    return;
                }

                try {
                    app(IssueTool::class)->returnIt($ausgabe, auth()->user(), $data['note'] ?? null);

                    Notification::make()->success()->title(__('tooling.action.returned'))->send();
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title(__('tooling.action.failed'))
                        ->body($e->getMessage())->persistent()->send();
                }
            });
    }

    public static function configure(Table $table): Table
    {
        return $table
            /*
             * Faelligkeit aufsteigend, NULL zuerst: "kalibrierpflichtig, aber
             * noch nie kalibriert" ist der schlimmste Fall und darf nicht ans
             * Ende der Liste rutschen, wo ihn niemand sieht.
             */
            ->defaultSort('calibration_due_at', 'asc')
            ->columns([
                TextColumn::make('inventory_number')
                    ->label(__('tooling.field.inventory_number'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('tooling.field.name'))
                    ->searchable()
                    ->description(fn (Tool $record): ?string => trim(($record->manufacturer ?? '').' '.($record->model ?? '')) ?: null),

                TextColumn::make('state')
                    ->label(__('tooling.field.state'))
                    ->badge()
                    ->formatStateUsing(fn (ToolState $state): string => $state->label())
                    ->color(fn (ToolState $state): string => $state->color()),

                /*
                 * WER ES HAT. Die Frage, wegen der es die Ausgabeliste gibt --
                 * und die einzige, die man vor dem Zumachen einer Flaeche
                 * beantwortet haben will.
                 */
                TextColumn::make('issued')
                    ->label(__('tooling.issue.heading'))
                    ->badge()
                    ->state(fn (Tool $record): string => $record->isIssued()
                        ? __('tooling.issue.out', ['name' => $record->currentIssue()?->issued_to_name ?? '—'])
                        : __('tooling.issue.available'))
                    ->color(fn (Tool $record): string => match (true) {
                        ! $record->isIssued() => 'gray',
                        $record->currentIssue()?->isOverdue() ?? false => 'danger',
                        default => 'warning',
                    })
                    ->description(fn (Tool $record): ?string => $record->isIssued()
                        ? __('tooling.issue.since', ['days' => $record->currentIssue()?->daysOut() ?? 0])
                        : null),

                TextColumn::make('location')
                    ->label(__('tooling.field.location'))
                    ->placeholder('—')
                    ->toggleable(),

                IconColumn::make('calibration_required')
                    ->label(__('tooling.field.calibration_required'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('calibration_due_at')
                    ->label(__('tooling.field.calibration_due_at'))
                    ->date('d.m.Y')
                    ->sortable()
                    ->placeholder(fn (Tool $record): string => $record->calibration_required
                        ? __('tooling.due.never')
                        : '—')
                    ->badge()
                    ->color(fn (Tool $record): string => match (true) {
                        ! $record->calibration_required => 'gray',
                        $record->isCalibrationOverdue() => 'danger',
                        $record->isCalibrationDueSoon() => 'warning',
                        default => 'success',
                    })
                    ->description(fn (Tool $record): ?string => match (true) {
                        ! $record->calibration_required || $record->calibration_due_at === null => null,
                        $record->isCalibrationOverdue() => __('tooling.due.days', [
                            'days' => (int) $record->calibration_due_at->diffInDays(now()),
                        ]),
                        $record->isCalibrationDueSoon() => __('tooling.due.in_days', [
                            'days' => (int) now()->diffInDays($record->calibration_due_at),
                        ]),
                        default => null,
                    }),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label(__('tooling.filter.state'))
                    ->options(collect(ToolState::cases())
                        ->mapWithKeys(fn (ToolState $s): array => [$s->value => $s->label()])
                        ->all()),

                Filter::make('issued')
                    ->label(__('tooling.filter.issued'))
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'issues',
                        fn (Builder $q): Builder => $q->outstanding(),
                    )),

                Filter::make('overdue')
                    ->label(__('tooling.filter.overdue'))
                    ->query(fn (Builder $query): Builder => $query->overdue()),

                Filter::make('open_gaps')
                    ->label(__('tooling.filter.open_gaps'))
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'calibrations',
                        fn (Builder $q): Builder => $q->openGaps(),
                    )),
            ])
            ->recordActions([
                self::issue(),
                self::takeBack(),
                self::calibrate(),
                EditAction::make(),
            ])
            ->emptyStateHeading(__('tooling.empty.heading'))
            ->emptyStateDescription(__('tooling.empty.description'));
    }
}
