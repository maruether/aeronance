<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\MaintenanceManuals\Tables;

use App\Modules\Fleet\Actions\RecordManualRevision;
use App\Modules\Fleet\Enums\ManualKind;
use App\Modules\Fleet\Models\MaintenanceManual;
use App\Modules\Fleet\Permissions;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * Die Unterlagen mit ihren Ständen.
 *
 * Geltende zuoberst und ab Werk gefiltert: Wer die Liste aufmacht, will wissen,
 * wonach heute gearbeitet wird — die abgelösten Stände sind die Antwort auf eine
 * andere Frage und stehen deshalb einen Klick weiter.
 */
final class MaintenanceManualsTable
{
    /**
     * Eine neue Revision — der Weg, der den alten Stand STEHEN lässt.
     *
     * Deshalb ist der Revisionsstand im Formular gesperrt: Ihn dort zu ändern
     * würde die Historie überschreiben, und genau dagegen ist diese Liste
     * gebaut.
     */
    public static function supersede(): Action
    {
        return Action::make('supersede')
            ->label(__('fleet.manual.action.supersede'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->modalDescription(__('fleet.manual.help.supersede'))
            ->visible(fn (MaintenanceManual $record): bool => $record->isCurrent()
                && (auth()->user()?->can(Permissions::FLEET_MANAGE) ?? false))
            ->schema([
                TextInput::make('revision')
                    ->label(__('fleet.manual.field.revision'))
                    ->helperText(__('fleet.manual.help.revision'))
                    ->required()
                    ->maxLength(64),

                DatePicker::make('revision_date')
                    ->label(__('fleet.manual.field.revision_date')),

                DatePicker::make('effective_from')
                    ->label(__('fleet.manual.field.effective_from')),

                Textarea::make('note')
                    ->label(__('fleet.manual.field.note'))
                    ->rows(2),

                /*
                 * Das neue PDF gehört in DENSELBEN Dialog: Eine neue Revision
                 * ohne ihr Dokument ist der Normalfall des Vergessens.
                 * storeFiles(false), weil der Zieldatensatz erst in action()
                 * entsteht -- dasselbe Muster wie am Luftfahrzeug-Dokument.
                 */
                FileUpload::make('file')
                    ->label(__('fleet.manual.field.file'))
                    ->storeFiles(false)
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                    ->maxSize((int) config('aeronance.documents.max_size_mb', 20) * 1024)
                    ->helperText(__('fleet.manual.help.file')),
            ])
            ->action(function (MaintenanceManual $record, array $data): void {
                try {
                    $neu = app(RecordManualRevision::class)->supersede(
                        previous: $record,
                        revision: (string) $data['revision'],
                        revisionDate: $data['revision_date'] ?? null,
                        effectiveFrom: $data['effective_from'] ?? null,
                        user: auth()->user(),
                        note: $data['note'] ?? null,
                    );

                    $datei = $data['file'] ?? null;

                    if ($datei instanceof TemporaryUploadedFile) {
                        // Erzeugter Name: ein hochgeladener Dateiname ist
                        // Fremdeingabe (Härtungs-Leitplanke).
                        $neu->addMedia($datei->getRealPath())
                            ->usingFileName(Str::uuid().'.'.($datei->guessExtension() ?: 'pdf'))
                            ->toMediaCollection(MaintenanceManual::DOCUMENTS);
                    }

                    Notification::make()->success()->title(__('fleet.manual.action.superseded'))->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('fleet.manual.action.failed'))
                        ->body($e->getMessage())->persistent()->send();
                }
            });
    }

    /** Zurückziehen — gilt nicht mehr, und es kommt nichts nach. */
    public static function withdraw(): Action
    {
        return Action::make('withdraw')
            ->label(__('fleet.manual.action.withdraw'))
            ->icon(Heroicon::OutlinedArchiveBoxXMark)
            ->color('danger')
            ->visible(fn (MaintenanceManual $record): bool => $record->isCurrent()
                && (auth()->user()?->can(Permissions::FLEET_MANAGE) ?? false))
            ->schema([
                Textarea::make('reason')
                    ->label(__('fleet.manual.field.withdrawn_reason'))
                    ->required()
                    ->rows(3),
            ])
            ->action(function (MaintenanceManual $record, array $data): void {
                try {
                    app(RecordManualRevision::class)->withdraw($record, (string) ($data['reason'] ?? ''));

                    Notification::make()->success()->title(__('fleet.manual.action.withdrawn'))->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('fleet.manual.action.failed'))
                        ->body($e->getMessage())->persistent()->send();
                }
            });
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('title')
            ->columns([
                TextColumn::make('title')
                    ->label(__('fleet.manual.field.title'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (MaintenanceManual $record): ?string => $record->reference),

                TextColumn::make('kind')
                    ->label(__('fleet.manual.field.kind'))
                    ->badge()
                    ->formatStateUsing(fn (ManualKind $state): string => $state->label()),

                /*
                 * WOFUER SIE GILT. Muster oder einzelnes Luftfahrzeug -- eine
                 * Spalte, weil es immer genau eines von beiden ist.
                 */
                TextColumn::make('scope')
                    ->label(__('fleet.manual.field.scope'))
                    ->state(fn (MaintenanceManual $record): string => $record->aircraft?->registration
                        ?? $record->aircraftType?->designation
                        ?? '—'),

                TextColumn::make('revision')
                    ->label(__('fleet.manual.field.revision'))
                    ->badge()
                    ->color(fn (MaintenanceManual $record): string => match (true) {
                        ! $record->isCurrent() => 'gray',
                        $record->isNotYetEffective() => 'warning',
                        default => 'success',
                    })
                    ->description(fn (MaintenanceManual $record): ?string => match (true) {
                        $record->withdrawn_at !== null => __('fleet.manual.withdrawn'),
                        $record->superseded_at !== null => __('fleet.manual.superseded'),
                        $record->isNotYetEffective() => __('fleet.manual.not_yet_effective', [
                            'date' => $record->effective_from?->format('d.m.Y') ?? '',
                        ]),
                        default => __('fleet.manual.current'),
                    }),

                TextColumn::make('revision_date')
                    ->label(__('fleet.manual.field.revision_date'))
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                Filter::make('current')
                    ->label(__('fleet.manual.filter.current'))
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->current()),

                SelectFilter::make('kind')
                    ->label(__('fleet.manual.filter.kind'))
                    ->options(collect(ManualKind::cases())
                        ->mapWithKeys(fn (ManualKind $k): array => [$k->value => $k->label()])
                        ->all()),
            ])
            ->recordActions([
                // Die Datei zuerst: Öffnen ist der häufigste Griff an dieser
                // Liste -- wer hier ist, will meist LESEN, wonach gearbeitet wird.
                Action::make('open')
                    ->label(__('fleet.manual.action.open'))
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->url(fn (MaintenanceManual $record): string => route('fleet.manual.file', ['manual' => $record]))
                    ->openUrlInNewTab()
                    ->visible(fn (MaintenanceManual $record): bool => $record->hasMedia(MaintenanceManual::DOCUMENTS)),

                self::supersede(),
                EditAction::make(),
                self::withdraw(),
            ])
            ->emptyStateHeading(__('fleet.manual.empty.heading'))
            ->emptyStateDescription(__('fleet.manual.empty.description'))
            // Auch aus der leeren Liste heraus anlegbar: Wer hier steht, will
            // meist genau das, und der Kopfknopf ist zwei Blickrichtungen weg.
            ->emptyStateActions([CreateAction::make()]);
    }
}
