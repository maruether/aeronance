<?php

declare(strict_types=1);

namespace App\Core\Filament\Pages;

use App\Core\Access\CorePermissions;
use App\Core\Models\Activity;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
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
 * The audit trail.
 *
 * A diagnostic tool, not a dashboard. the own framing: the log is not meant
 * to be read continuously but to help when something looks wrong -- so what
 * matters is searching and filtering by person, object, period and kind of
 * action, and there is deliberately no live feed on the front page.
 *
 * Read-only in the strongest sense: entries cannot be edited or deleted from
 * here, because they cannot be edited or deleted at all. See decision E3.
 */
final class AuditTrail extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'core.filament.pages.audit-trail';

    protected static ?int $navigationSort = 80;

    protected static ?string $slug = 'protokoll';

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.system');
    }

    public static function getNavigationLabel(): string
    {
        return __('audit.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('audit.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('audit.subheading');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedClipboardDocumentList;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(CorePermissions::AUDIT_VIEW) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Activity::query()->with(['causer', 'subject']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('audit.field.when'))
                    ->dateTime('d.m.Y H:i')
                    ->timezone(config('aeronance.organisation.timezone'))
                    ->sortable(),

                TextColumn::make('causer.name')
                    ->label(__('audit.field.who'))
                    ->placeholder(__('audit.system'))
                    ->searchable(),

                TextColumn::make('log_name')
                    ->label(__('audit.field.area'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state !== null
                        ? __('audit.area.'.$state)
                        : '—'),

                TextColumn::make('description')
                    ->label(__('audit.field.what'))
                    ->formatStateUsing(fn (string $state): string => __('audit.event.'.$state))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'info',
                        'deleted', 'access_locked', 'login_failed' => 'danger',
                        'access_unlocked' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('subject')
                    ->label(__('audit.field.object'))
                    ->state(fn (Activity $record): string => $this->describeSubject($record))
                    ->wrap(),

                TextColumn::make('changes')
                    ->label(__('audit.field.changes'))
                    ->state(fn (Activity $record): string => $this->describeChanges($record))
                    ->wrap()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('log_name')
                    ->label(__('audit.field.area'))
                    ->options(fn (): array => Activity::query()
                        ->distinct()
                        ->whereNotNull('log_name')
                        ->pluck('log_name', 'log_name')
                        ->map(fn (string $name): string => __('audit.area.'.$name))
                        ->all()),

                /*
                 * Aus dem Bestand und nicht aus einer festen Liste: Hier
                 * standen drei Ereignisse, waehrend der Code inzwischen sieben
                 * schreibt -- nach einer Sperre oder einem fehlgeschlagenen
                 * Anmeldeversuch liess sich also gar nicht filtern. Dieselbe
                 * Lehre wie bei den Rechtetests: Eine handgepflegte Liste
                 * driftet, und sie driftet still.
                 */
                SelectFilter::make('description')
                    ->label(__('audit.field.what'))
                    ->options(fn (): array => Activity::query()
                        ->distinct()
                        ->orderBy('description')
                        ->pluck('description', 'description')
                        ->map(fn (string $name): string => __('audit.event.'.$name))
                        ->all()),

                SelectFilter::make('causer_id')
                    ->label(__('audit.field.who'))
                    ->options(fn (): array => User::orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),

                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label(__('audit.filter.from')),
                        DatePicker::make('until')->label(__('audit.filter.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $d): Builder => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, string $d): Builder => $q->whereDate('created_at', '<=', $d))),
            ])
            ->paginated([25, 50, 100]);
    }

    private function describeSubject(Activity $record): string
    {
        if ($record->subject === null) {
            // The record it referred to is gone; the entry stays, which is the
            // point of an append-only trail.
            return $record->subject_type !== null
                ? __('audit.subject_gone', ['type' => class_basename($record->subject_type)])
                : '—';
        }

        return sprintf(
            '%s: %s',
            __('audit.subject.'.class_basename($record->subject_type)),
            $record->subject->name ?? $record->subject->getKey(),
        );
    }

    /**
     * Old value to new value, in a form a human can scan.
     *
     * Read from attribute_changes, not from properties: version 5 of the
     * logging package moved the recorded changes into a column of their own,
     * and properties now holds only what a caller attached by hand. A smoke
     * test against a real record is what turned that up -- the entries were
     * being written, but the interface showed every one of them as empty.
     */
    private function describeChanges(Activity $record): string
    {
        $changes = $record->attribute_changes?->toArray() ?? [];
        $new = $changes['attributes'] ?? [];
        $old = $changes['old'] ?? [];

        if ($new === []) {
            /*
             * ─────────────────────────────────────────────────────────────────
             * KEIN GEAENDERTES FELD HEISST NICHT: NICHTS ZU SEHEN.
             *
             * Eintraege, die kein Modell aendern, tragen ihre Aussage in den
             * Eigenschaften: der GRUND einer Sperre, die Kennung eines
             * gescheiterten Anmeldeversuchs, die IP-Adresse. Ohne diesen Zweig
             * stuende dort ein Gedankenstrich -- und ein Protokoll, das den
             * Grund einer Sperre nicht zeigt, beantwortet genau die Frage
             * nicht, wegen der jemand es aufschlaegt.
             * ─────────────────────────────────────────────────────────────────
             */
            return $this->describeProperties($record);
        }

        $lines = [];

        foreach ($new as $field => $value) {
            $before = $old[$field] ?? null;

            $lines[] = array_key_exists($field, $old)
                ? sprintf('%s: %s → %s', $field, $this->format($before), $this->format($value))
                : sprintf('%s: %s', $field, $this->format($value));
        }

        return implode("\n", $lines);
    }

    /**
     * Die Eigenschaften eines Eintrags, in derselben Form wie Aenderungen.
     *
     * Die Feldnamen werden uebersetzt, wo es eine Uebersetzung gibt --
     * "identifier" steht sonst als englischer Bezeichner in einer deutschen
     * Oberflaeche. Wo keine da ist, bleibt der Name stehen: unschoen, aber
     * ehrlicher als ein leeres Feld.
     */
    private function describeProperties(Activity $record): string
    {
        $properties = $record->properties?->toArray() ?? [];

        if ($properties === []) {
            return '—';
        }

        $lines = [];

        foreach ($properties as $field => $value) {
            $schluessel = 'audit.auth.'.$field;
            $label = trans()->has($schluessel) ? __($schluessel) : $field;

            $lines[] = sprintf('%s: %s', $label, $this->format($value));
        }

        return implode("\n", $lines);
    }

    private function format(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? __('audit.yes') : __('audit.no'),
            default => (string) $value,
        };
    }
}
