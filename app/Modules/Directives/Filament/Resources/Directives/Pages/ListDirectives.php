<?php

declare(strict_types=1);

namespace App\Modules\Directives\Filament\Resources\Directives\Pages;

use App\Modules\Directives\Actions\ImportDirectives;
use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Directives\Filament\Resources\Directives\DirectiveResource;
use App\Modules\Directives\Models\Directive;
use App\Modules\Directives\Permissions;
use App\Modules\Directives\Sources\Configured\SpecRepository;
use App\Modules\Directives\Sources\DirectiveSource;
use App\Modules\Directives\Sources\SourceRegistry;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Throwable;

/**
 * The list itself.
 *
 * Manual entry and CSV import side by side, which is the shape the requirement was for:
 * "wo möglich per hersteller untermodul im modul ein download. wo das nicht geht
 * manuell und csv." A manufacturer adapter appears in the same import dialog
 * without anything here changing -- the select is fed from the registry.
 */
final class ListDirectives extends ListRecords
{
    protected static string $resource = DirectiveResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('issued_at', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label(__('directives.field.number'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('kind')
                    ->label(__('directives.field.kind'))
                    ->badge()
                    ->formatStateUsing(fn (DirectiveKind $state): string => $state->label())
                    ->color('gray'),

                // Read from bindingness, not derived from the kind: an adopted TM
                // is binding while still being a TM.
                TextColumn::make('bindingness')
                    ->label(__('directives.field.bindingness'))
                    ->badge()
                    ->formatStateUsing(fn (Bindingness $state): string => $state->label())
                    ->color(fn (Bindingness $state): string => $state->color()),

                TextColumn::make('title')
                    ->label(__('directives.field.title'))
                    ->searchable()
                    ->wrap()
                    // A manufacturer's subject can run 400 characters -- see the
                    // title-widening migration. Truncated for the list, whole on
                    // the detail page.
                    ->limit(90)
                    ->tooltip(fn (Directive $r): ?string => mb_strlen($r->title) > 90 ? $r->title : null),

                TextColumn::make('subject')
                    ->label(__('directives.field.subject_kind'))
                    ->state(fn (Directive $r): string => self::describeSubject($r))
                    ->wrap(),

                TextColumn::make('comply_before')
                    ->label(__('directives.field.comply_before'))
                    ->date('d.m.Y')
                    ->sortable()
                    ->color(fn (Directive $r): ?string => $r->isOverdue() ? 'danger' : null)
                    ->placeholder('—'),

                IconColumn::make('is_recurring')
                    ->label(__('directives.field.is_recurring'))
                    ->boolean(),

                TextColumn::make('assessments')
                    ->label(__('directives.field.state'))
                    ->state(fn (Directive $r): string => self::assessmentSummary($r))
                    ->badge()
                    ->separator(' '),

                TextColumn::make('source')
                    ->label('')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('directives.field.kind'))
                    ->options(fn (): array => collect(DirectiveKind::cases())
                        ->mapWithKeys(fn (DirectiveKind $k): array => [$k->value => $k->label()])
                        ->all()),

                SelectFilter::make('bindingness')
                    ->label(__('directives.field.bindingness'))
                    ->options(fn (): array => collect(Bindingness::cases())
                        ->mapWithKeys(fn (Bindingness $b): array => [$b->value => $b->label()])
                        ->all()),

                SelectFilter::make('subject_kind')
                    ->label(__('directives.field.subject_kind'))
                    ->options(fn (): array => collect(SubjectKind::cases())
                        ->mapWithKeys(fn (SubjectKind $k): array => [$k->value => $k->label()])
                        ->all()),

                TernaryFilter::make('superseded')
                    ->label(__('directives.field.superseded_by'))
                    ->placeholder(__('directives.filter.current_only'))
                    ->trueLabel(__('directives.filter.superseded_only'))
                    ->falseLabel(__('directives.filter.current_only'))
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('superseded_by_id'),
                        false: fn ($q) => $q->whereNull('superseded_by_id'),
                        blank: fn ($q) => $q->whereNull('superseded_by_id'),
                    ),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->sourceProblemsAction(),
            $this->importAction(),
            CreateAction::make()
                ->label(__('directives.action.assess') === '' ? 'Neu' : __('filament-actions::create.single.label'))
                ->schema(self::formSchema()),
        ];
    }

    /** @return list<Component> */
    public static function formSchema(): array
    {
        return [
            TextInput::make('number')
                ->label(__('directives.field.number'))
                ->required()
                ->maxLength(64),

            TextInput::make('title')
                ->label(__('directives.field.title'))
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            Select::make('kind')
                ->label(__('directives.field.kind'))
                ->options(collect(DirectiveKind::cases())
                    ->mapWithKeys(fn (DirectiveKind $k): array => [$k->value => $k->label()])->all())
                ->default(DirectiveKind::Lta->value)
                ->required()
                ->helperText(__('directives.help.mandatory')),

            Select::make('bindingness')
                ->label(__('directives.field.bindingness'))
                ->options(collect(Bindingness::cases())
                    ->mapWithKeys(fn (Bindingness $b): array => [$b->value => $b->label()])->all())
                ->default(Bindingness::Mandatory->value)
                ->required()
                ->helperText(__('directives.help.refusal_optional_only')),

            TextInput::make('issuer')->label(__('directives.field.issuer'))->maxLength(160),

            DatePicker::make('issued_at')->label(__('directives.field.issued_at')),
            DatePicker::make('comply_before')->label(__('directives.field.comply_before')),

            Select::make('subject_kind')
                ->label(__('directives.field.subject_kind'))
                ->options(collect(SubjectKind::cases())
                    ->mapWithKeys(fn (SubjectKind $k): array => [$k->value => $k->label()])->all())
                ->default(SubjectKind::AircraftModel->value)
                ->required()
                ->live(),

            TextInput::make('subject_model')
                ->label(__('directives.field.subject_model'))
                ->maxLength(96)
                ->helperText(__('directives.help.model_match')),

            // Serial-based subjects only.
            TextInput::make('subject_designation')
                ->label(__('directives.field.subject_designation'))
                ->maxLength(160)
                ->visible(fn (Get $get): bool => SubjectKind::tryFrom((string) $get('subject_kind'))?->isSerialBased() ?? false),

            TextInput::make('subject_part_number')
                ->label(__('directives.field.subject_part_number'))
                ->maxLength(96)
                ->visible(fn (Get $get): bool => SubjectKind::tryFrom((string) $get('subject_kind'))?->isSerialBased() ?? false),

            TextInput::make('serial_from')
                ->label(__('directives.field.serial_from'))
                ->maxLength(64)
                ->visible(fn (Get $get): bool => SubjectKind::tryFrom((string) $get('subject_kind'))?->isSerialBased() ?? false),

            TextInput::make('serial_to')
                ->label(__('directives.field.serial_to'))
                ->maxLength(64)
                ->visible(fn (Get $get): bool => SubjectKind::tryFrom((string) $get('subject_kind'))?->isSerialBased() ?? false),

            Textarea::make('summary')->label(__('directives.field.summary'))->rows(3)->columnSpanFull(),

            Checkbox::make('is_recurring')
                ->label(__('directives.field.is_recurring'))
                ->live()
                ->helperText(__('directives.help.recurrence')),

            TextInput::make('interval_months')
                ->label(__('directives.field.interval_months'))
                ->numeric()
                ->minValue(1)
                ->visible(fn (Get $get): bool => (bool) $get('is_recurring')),

            Select::make('interval_counter')
                ->label(__('directives.field.interval_counter'))
                ->options([
                    'flight_hours' => __('fleet.counter.flight_hours'),
                    'landings' => __('fleet.counter.landings'),
                    'starts' => __('fleet.counter.starts'),
                    'engine_hours' => __('fleet.counter.engine_hours'),
                    'cycles' => __('fleet.counter.cycles'),
                ])
                ->visible(fn (Get $get): bool => (bool) $get('is_recurring')),

            TextInput::make('interval_value')
                ->label(__('directives.field.interval_value'))
                ->numeric()
                ->visible(fn (Get $get): bool => (bool) $get('is_recurring')),

            TextInput::make('reference_url')
                ->label(__('directives.field.reference_url'))
                ->url()
                ->maxLength(500)
                ->columnSpanFull(),
        ];
    }

    /**
     * Manufacturer files that could not be loaded.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Until now a broken spec was skipped silently, and the only symptom was a
     * source missing from the import dialog -- which looks exactly like a source
     * nobody has configured. Somebody who drops their own YAML into
     * storage/app/directive-sources/ and mistypes a pattern had no way to find
     * out except by reading the loader.
     *
     * The button only appears when something IS broken. A permanently visible
     * "0 problems" is a button people stop seeing.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private function sourceProblemsAction(): Action
    {
        return Action::make('sourceProblems')
            ->label(fn (): string => __('directives.source_problems.action', [
                'count' => count(app(SpecRepository::class)->problems()),
            ]))
            ->icon('heroicon-o-exclamation-triangle')
            ->color('danger')
            ->visible(fn (): bool => app(SpecRepository::class)->problems() !== []
                && (auth()->user()?->can(Permissions::DIRECTIVES_MANAGE) ?? false))
            ->modalDescription(__('directives.source_problems.help'))
            ->modalSubmitAction(false)
            ->schema(fn (): array => array_map(
                fn (string $file, string $reason): Placeholder => Placeholder::make('problem_'.md5($file))
                    ->label($file)
                    ->content($reason),
                array_keys(app(SpecRepository::class)->problems()),
                array_values(app(SpecRepository::class)->problems()),
            ));
    }

    /**
     * Bringing a list in.
     *
     * The source select comes from the registry, so a manufacturer adapter shows
     * up here by registering itself -- no change to this file.
     */
    private function importAction(): Action
    {
        return Action::make('import')
            ->label(__('directives.action.import'))
            ->icon('heroicon-o-arrow-down-tray')
            ->visible(fn (): bool => auth()->user()?->can(Permissions::DIRECTIVES_MANAGE) ?? false)
            ->modalDescription(__('directives.help.list_grows'))
            ->schema([
                Select::make('source')
                    ->label(__('directives.field.source') ?? 'Quelle')

                    /*
                     * A source whose credentials are missing is labelled as such
                     * rather than left to fail on submit -- the refusal is
                     * accurate but arrives after somebody has filled the form.
                     */
                    ->options(fn (): array => collect(app(SourceRegistry::class)->all())
                        ->map(fn (DirectiveSource $s): string => $s->label()
                            .(method_exists($s, 'isUsable') && ! $s->isUsable()
                                ? ' — '.__('directives.source_problems.no_credentials')
                                : ''))
                        /*
                         * Alphabetisch und durchsuchbar -- Feldtest: Bei knapp
                         * fuenfzig Quellen in Registrierungsreihenfolge war die
                         * Liste "unuebersichtlich". Hand und CSV zuerst, denn
                         * sie sind keine Hersteller, sondern Werkzeuge.
                         */
                        ->sortBy(fn (string $label, string $key): string => match ($key) {
                            'manual' => "\x000",
                            'csv' => "\x001",
                            default => mb_strtolower($label),
                        }, SORT_NATURAL)
                        ->all())
                    ->searchable()
                    ->default('csv')
                    ->required()
                    ->live(),

                Select::make('kind')
                    ->label(__('directives.field.kind'))
                    ->options(collect(DirectiveKind::cases())
                        ->mapWithKeys(fn (DirectiveKind $k): array => [$k->value => $k->label()])->all())
                    ->default(DirectiveKind::Lta->value),

                Select::make('subject_kind')
                    ->label(__('directives.field.subject_kind'))
                    ->options(collect(SubjectKind::cases())
                        ->mapWithKeys(fn (SubjectKind $k): array => [$k->value => $k->label()])->all())
                    ->default(SubjectKind::AircraftModel->value),

                // Left blank derives from the kind: LTA/AD binding, TM/SB not.
                Select::make('bindingness')
                    ->label(__('directives.field.bindingness'))
                    ->options(collect(Bindingness::cases())
                        ->mapWithKeys(fn (Bindingness $b): array => [$b->value => $b->label()])->all())
                    ->placeholder(__('directives.help.bindingness_from_kind')),

                TextInput::make('model')->label(__('directives.field.subject_model'))->maxLength(96),
                TextInput::make('issuer')->label(__('directives.field.issuer'))->maxLength(160),

                // Pasting is for the sources that cannot fetch; a manufacturer
                // adapter hides it and asks for a model instead.
                Textarea::make('body')
                    ->label(__('directives.field.list'))
                    ->rows(10)
                    ->helperText(__('directives.help.csv_columns'))
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => ! self::sourceIsAutomatic($get('source'))),

                Checkbox::make('all')
                    ->label(__('directives.help.fetch_all'))
                    ->helperText(__('directives.help.schleicher_model'))
                    ->visible(fn (Get $get): bool => self::sourceIsAutomatic($get('source')))
                    ->columnSpanFull(),

                Placeholder::make('scope')
                    ->label('')
                    ->content(__('directives.help.schleicher_scope'))
                    ->visible(fn (Get $get): bool => (string) $get('source') === 'schleicher')
                    ->columnSpanFull(),
            ])
            ->action(function (array $data): void {
                try {
                    $result = app(ImportDirectives::class)->fromSource(
                        (string) $data['source'],
                        auth()->user(),
                        $data,
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
                    ->title(__('directives.notification.imported', $result))
                    ->send();

                /*
                 * A separate, persistent warning rather than a line appended to
                 * the success message. The counts above are correct and say
                 * nothing about this: a number the manufacturer used twice was
                 * read and NOT stored, and the success toast disappears in four
                 * seconds. This one stays until it is dismissed.
                 */
                if (($result['collisions'] ?? []) !== []) {
                    Notification::make()
                        ->warning()
                        ->title(__('directives.notification.collisions'))
                        ->body(__('directives.notification.collisions_body', [
                            'numbers' => implode(', ', $result['collisions']),
                        ]))
                        ->persistent()
                        ->send();
                }
            });
    }

    /**
     * Whether the chosen source fetches for itself.
     *
     * Guarded, because the select is live and a half-typed value would otherwise
     * reach the registry and throw while somebody is still choosing.
     */
    private static function sourceIsAutomatic(mixed $source): bool
    {
        $name = (string) ($source ?? '');
        $registry = app(SourceRegistry::class);

        return $registry->has($name) && $registry->get($name)->isAutomatic();
    }

    private static function describeSubject(Directive $directive): string
    {
        $parts = [$directive->subject_kind->label()];

        foreach ([$directive->subject_model, $directive->subject_designation, $directive->subject_part_number] as $v) {
            if (filled($v)) {
                $parts[] = $v;
            }
        }

        if (filled($directive->serial_from) || filled($directive->serial_to)) {
            $parts[] = sprintf('S/N %s–%s', $directive->serial_from ?? '', $directive->serial_to ?? '');
        }

        return implode(' · ', $parts);
    }

    /**
     * How the fleet stands on this line, in one cell.
     *
     * Counted rather than listed: the detail view has the per-aircraft rows, and
     * what a list wants is "is anything still open here".
     */
    private static function assessmentSummary(Directive $directive): string
    {
        $applications = $directive->applications;

        if ($applications->isEmpty()) {
            return __('directives.open.never_assessed');
        }

        $outstanding = $applications->filter(fn ($a): bool => $a->isOutstanding())->count();

        return $outstanding === 0
            ? __('directives.summary.all_clear', ['count' => $applications->count()])
            : __('directives.summary.outstanding', ['count' => $outstanding]);
    }
}
