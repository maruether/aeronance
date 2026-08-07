<?php

declare(strict_types=1);

namespace App\Modules\Tooling\Filament\Resources\Tools\RelationManagers;

use App\Modules\Tooling\Actions\RecordCalibration;
use App\Modules\Tooling\Enums\CalibrationResult;
use App\Modules\Tooling\Models\ToolCalibration;
use App\Modules\Tooling\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Die Kalibrierhistorie eines Werkzeugs — und die offenen Bewertungen darin.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE SPALTE „LÜCKE" IST DER GRUND, WARUM ES DIESE ANSICHT GIBT. Ein
 * Kalibrierschein allein ist eine Ablage; die Frage, ob zwischen zwei Scheinen
 * eine Zeit ohne belegte Genauigkeit lag, beantwortet nur die Reihe.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class CalibrationsRelationManager extends RelationManager
{
    protected static string $relationship = 'calibrations';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('tooling.field.certificate');
    }

    public function form(Schema $schema): Schema
    {
        // Eingetragen wird ueber die Aktion am Werkzeug, die die Luecke selbst
        // erkennt -- ein zweites Formular hier waere der Weg daran vorbei.
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('performed_at', 'desc')
            ->columns([
                TextColumn::make('performed_at')
                    ->label(__('tooling.field.performed_at'))
                    ->date('d.m.Y'),

                TextColumn::make('result')
                    ->label(__('tooling.field.result'))
                    ->badge()
                    ->placeholder(__('tooling.result.unknown'))
                    ->formatStateUsing(fn (CalibrationResult $state): string => $state->label())
                    ->color(fn (?CalibrationResult $state): string => $state?->color() ?? 'gray'),

                TextColumn::make('valid_until')
                    ->label(__('tooling.field.valid_until'))
                    ->date('d.m.Y')
                    ->placeholder('—'),

                TextColumn::make('provider')
                    ->label(__('tooling.field.provider'))
                    ->placeholder('—'),

                TextColumn::make('certificate_reference')
                    ->label(__('tooling.field.certificate_reference'))
                    ->placeholder('—'),

                TextColumn::make('gap_started_at')
                    ->label(__('tooling.field.gap'))
                    ->badge()
                    ->placeholder(__('tooling.gap.none'))
                    ->formatStateUsing(fn ($state, ToolCalibration $record): string => __('tooling.gap.length', [
                        'days' => $record->gapDays(),
                    ]))
                    ->color(fn (ToolCalibration $record): string => $record->gapNeedsReview()
                        ? ($record->gap_reason?->color() ?? 'danger')
                        : 'gray')
                    ->description(fn (ToolCalibration $record): ?string => match (true) {
                        ! $record->hasGap() => null,
                        default => ($record->gap_reason?->label() ?? '').' — '.($record->gapNeedsReview()
                            ? __('tooling.gap.open')
                            : __('tooling.gap.reviewed')),
                    }),

                TextColumn::make('gap_review_note')
                    ->label(__('tooling.field.gap_review_note'))
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
            ])
            ->recordActions([$this->reviewGap()]);
    }

    /**
     * Die Bewertung der Lücke festhalten.
     *
     * Eigene Berechtigung: „In den vier Monaten wurde damit nichts Kritisches
     * gemacht" ist eine fachliche Aussage, keine Bürotätigkeit.
     */
    private function reviewGap(): Action
    {
        return Action::make('reviewGap')
            ->label(__('tooling.action.review_gap'))
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->visible(fn (ToolCalibration $record): bool => $record->gapNeedsReview()
                && (auth()->user()?->can(Permissions::TOOLS_ASSESS) ?? false))
            ->modalDescription(__('tooling.help.gap'))
            ->schema([
                Textarea::make('note')
                    ->label(__('tooling.field.gap_review_note'))
                    ->rows(4)
                    ->required(),
            ])
            ->action(function (ToolCalibration $record, array $data): void {
                try {
                    app(RecordCalibration::class)->reviewGap($record, auth()->user(), $data['note'] ?? '');

                    Notification::make()->success()->title(__('tooling.action.reviewed'))->send();
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
}
