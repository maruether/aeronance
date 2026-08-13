<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Filament\Pages;

use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Actions\RecordFinding;
use App\Modules\TaskCards\Models\Finding;
use App\Modules\TaskCards\Permissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Der Befundbericht -- melden, was einem am Flugzeug aufgefallen ist.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Feldtest: "Ein Befundbericht sollte durch jeden P/O oder höher angelegt
 * werden können. Aus einzelnen oder mehreren Punkten soll dann eine
 * Arbeitskarte erstellt werden können."
 *
 * Der Bericht ist ein FORMULAR, keine eigene Entität: Jeder Punkt wird ein
 * gewöhnlicher Befund mit eigener Nummer -- genau die Einheit, die sich
 * später einzeln oder gebündelt auf eine Arbeitskarte heben lässt (Aktion
 * "Arbeitskarte erstellen" in der Befundliste). Eine Berichtshülle darum
 * hätte eine zweite Nummernwelt und eine zweite Liste erzeugt, ohne dass
 * irgendjemand sie je aufschlüge.
 *
 * Was die meldende Person NICHT entscheidet: ob es harmlos ist. Jeder
 * gemeldete Punkt steht als blockierend im Buch -- herabstufen (zurückstellen,
 * verwerfen) ist eine Feststellung und verlangt die Qualifikation (E8).
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ReportFindings extends Page
{
    protected string $view = 'taskcards.filament.pages.report-findings';

    protected static ?string $slug = 'befund-melden';

    protected static ?int $navigationSort = 25;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.taskcards');
    }

    public static function getNavigationLabel(): string
    {
        return __('taskcards.report.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('taskcards.report.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('taskcards.report.subheading');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedMegaphone;
    }

    public static function canAccess(): bool
    {
        // Das Werkstattrecht schließt das Melderecht ein -- wer über Befunde
        // entscheiden darf, darf sie erst recht erwähnen.
        return (auth()->user()?->can(Permissions::FINDINGS_REPORT) ?? false)
            || (auth()->user()?->can(Permissions::FINDINGS_RECORD) ?? false);
    }

    public function mount(): void
    {
        $this->form->fill([
            'found_on' => now()->toDateString(),
            'points' => [['title' => '', 'description' => '']],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('taskcards.report.section.what'))
                    ->columns(2)
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
                            ->maxDate(now())
                            ->required(),

                        Repeater::make('points')
                            ->label(__('taskcards.report.field.points'))
                            ->addActionLabel(__('taskcards.report.add_point'))
                            ->minItems(1)
                            ->columnSpanFull()
                            ->schema([
                                TextInput::make('title')
                                    ->label(__('taskcards.finding.field.title'))
                                    ->required()
                                    // 160 wie die Spalte und wie der
                                    // Werkstatt-Dialog -- 200 liefe bis zur
                                    // Datenbank und platzte erst dort.
                                    ->maxLength(160),

                                Textarea::make('description')
                                    ->label(__('taskcards.finding.field.description'))
                                    ->helperText(__('taskcards.report.help.description'))
                                    ->required()
                                    ->rows(2),
                            ]),
                    ]),
            ]);
    }

    public function submit(): void
    {
        $data = $this->form->getState();
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

        /*
         * Die Nummern gehören in die Bestätigung: Sie sind das, was die
         * meldende Person der Werkstatt nennen kann -- und der Beweis, dass
         * aus dem Bericht wirklich Buchzeilen geworden sind.
         */
        Notification::make()
            ->success()
            ->title(__('taskcards.report.done', ['count' => count($findings)]))
            ->body(implode(', ', array_map(
                static fn (Finding $f): string => $f->number,
                $findings,
            )))
            ->persistent()
            ->send();

        $this->form->fill([
            'found_on' => now()->toDateString(),
            'points' => [['title' => '', 'description' => '']],
        ]);
    }

    /**
     * Was diese Person gemeldet hat und noch offen ist.
     *
     * Für die, die NUR melden dürfen: Die Befundliste ist ihnen zu Recht
     * verschlossen (Werkstattsicht), aber die eigene Meldung einfach im
     * Nichts verschwinden zu sehen wäre der sichere Weg, dass niemand
     * mehr meldet. Review-Fund; die Nummern-Notification allein ist flüchtig.
     *
     * @return Collection<int, Finding>
     */
    public function myOutstanding(): Collection
    {
        return Finding::query()
            ->where('found_by', auth()->id())
            ->whereIn('state', ['open', 'scheduled', 'deferred'])
            ->orderByDesc('found_on')
            ->limit(20)
            ->get();
    }

    /** @return list<Action> */
    public function getFormActions(): array
    {
        return [
            Action::make('submit')
                ->label(__('taskcards.report.submit'))
                ->submit('submit'),
        ];
    }
}
