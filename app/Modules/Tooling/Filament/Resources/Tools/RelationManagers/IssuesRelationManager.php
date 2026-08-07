<?php

declare(strict_types=1);

namespace App\Modules\Tooling\Filament\Resources\Tools\RelationManagers;

use App\Modules\Tooling\Models\ToolIssue;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Wer das Werkzeug wann hatte.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE LISTE IST DER NACHWEIS, nicht die Bedienung. Ausgegeben und
 * zurückgenommen wird über die Aktionen am Werkzeug; hier steht, was daraus
 * geworden ist.
 *
 * Ihr eigentlicher Zweck zeigt sich erst im schlechten Fall: Fällt das Werkzeug
 * bei der Kalibrierung durch, liefert der Nachprüfzeitraum das Zeitfenster —
 * und diese Reihe die Vorgänge, an denen in dieser Zeit damit gearbeitet wurde.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class IssuesRelationManager extends RelationManager
{
    protected static string $relationship = 'issues';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('tooling.issue.heading');
    }

    public function form(Schema $schema): Schema
    {
        // Kein Formular: Ausgegeben und zurueckgenommen wird ueber die
        // Aktionen am Werkzeug, wo die Sperren greifen.
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('issued_at', 'desc')
            ->columns([
                TextColumn::make('issued_to_name')
                    ->label(__('tooling.field_issue.issued_to'))
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('issued_at')
                    ->label(__('tooling.field_issue.issued_at'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('returned_at')
                    ->label(__('tooling.field_issue.returned_at'))
                    ->dateTime('d.m.Y H:i')
                    /*
                     * Was NICHT zurueck ist, faellt hier auf -- und genau
                     * darum geht es beim Zumachen einer Flaeche.
                     */
                    ->placeholder(__('tooling.issue.heading'))
                    ->badge(fn (ToolIssue $record): bool => $record->isOutstanding())
                    ->color(fn (ToolIssue $record): string => match (true) {
                        ! $record->isOutstanding() => 'gray',
                        $record->isOverdue() => 'danger',
                        default => 'warning',
                    })
                    ->description(fn (ToolIssue $record): ?string => $record->isOutstanding()
                        ? __('tooling.issue.since', ['days' => $record->daysOut()])
                        : null),

                TextColumn::make('work_order_reference')
                    ->label(__('tooling.field_issue.work_order_reference'))
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('due_back_at')
                    ->label(__('tooling.field_issue.due_back_at'))
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('note')
                    ->label(__('tooling.field.note'))
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ]);
    }
}
