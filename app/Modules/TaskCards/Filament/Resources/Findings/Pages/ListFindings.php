<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Filament\Resources\Findings\Pages;

use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Actions\RecordFinding;
use App\Modules\TaskCards\Filament\Resources\Findings\FindingResource;
use App\Modules\TaskCards\Models\Finding;
use App\Modules\TaskCards\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ListFindings extends ListRecords
{
    protected static string $resource = FindingResource::class;

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [$this->reportAction()];
    }

    /**
     * Der Befundbericht -- als Knopf AN der Liste, nicht als eigene Seite.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Feldtest: "es braucht keine extra Seite sondern es reicht ein button
     * bei den befunden zum anlegen." Die eigene Seite existierte nur, weil
     * Melder die Befundliste nicht sehen durften -- seit das Melderecht die
     * Liste öffnet (canViewAny), trägt sie nichts mehr. Damit entfällt auch
     * ihr "Meine offenen Meldungen"-Ersatz: Wer meldet, sieht jetzt die
     * Liste selbst.
     *
     * Alles Fachliche bleibt in RecordFinding::report(): jeder Punkt ein
     * eigener Befund, immer blockierend, abgezeichnet mit der Nummer, die
     * zu Freigaben berechtigt (Part-66, sonst P/O fürs Luftfahrzeug).
     * ─────────────────────────────────────────────────────────────────────────
     */
    private function reportAction(): Action
    {
        return Action::make('report')
            ->label(__('taskcards.report.title'))
            ->icon('heroicon-o-megaphone')
            ->visible(fn (): bool => (auth()->user()?->can(Permissions::FINDINGS_REPORT) ?? false)
                || (auth()->user()?->can(Permissions::FINDINGS_RECORD) ?? false))
            ->modalDescription(__('taskcards.report.subheading'))
            ->schema([
                Select::make('aircraft_id')
                    ->label(__('fleet.aircraft.singular'))
                    ->options(fn (): array => Aircraft::query()
                        ->orderBy('registration')
                        ->pluck('registration', 'id')
                        ->all())
                    ->searchable()
                    ->required(),

                DatePicker::make('found_on')
                    ->label(__('taskcards.finding.field.found_on'))
                    ->default(now())
                    ->maxDate(now())
                    ->required(),

                Repeater::make('points')
                    ->label(__('taskcards.report.field.points'))
                    ->addActionLabel(__('taskcards.report.add_point'))
                    ->minItems(1)
                    ->schema([
                        TextInput::make('title')
                            ->label(__('taskcards.finding.field.title'))
                            ->required()
                            // 160 wie die Spalte -- 200 platzte erst in der DB.
                            ->maxLength(160),

                        Textarea::make('description')
                            ->label(__('taskcards.finding.field.description'))
                            ->helperText(__('taskcards.report.help.description'))
                            ->required()
                            ->rows(2),
                    ]),
            ])
            ->action(function (array $data): void {
                $aircraft = Aircraft::find($data['aircraft_id'] ?? null);

                if ($aircraft === null) {
                    return;
                }

                try {
                    /** @var list<Finding> $findings */
                    $findings = DB::transaction(function () use ($aircraft, $data): array {
                        $findings = [];

                        foreach ((array) ($data['points'] ?? []) as $point) {
                            $findings[] = app(RecordFinding::class)->report(
                                aircraft: $aircraft,
                                title: (string) ($point['title'] ?? ''),
                                description: (string) ($point['description'] ?? ''),
                                user: auth()->user(),
                                foundOn: $data['found_on'] ?? null,
                            );
                        }

                        return $findings;
                    });
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('taskcards.report.refused'))
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                // Die Nummern gehören in die Bestätigung: der Beweis, dass aus
                // dem Bericht Buchzeilen geworden sind -- und die Zeilen stehen
                // jetzt direkt darunter in der Liste.
                Notification::make()
                    ->success()
                    ->title(__('taskcards.report.done', ['count' => count($findings)]))
                    ->body(implode(', ', array_map(
                        static fn (Finding $f): string => $f->number,
                        $findings,
                    )))
                    ->persistent()
                    ->send();
            });
    }
}
