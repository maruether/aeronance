<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\Weighings\Pages;

use App\Modules\Fleet\Filament\Resources\Weighings\WeighingResource;
use App\Modules\Fleet\Models\Weighing;
use App\Modules\Fleet\Models\WeighingEntry;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Das Wägeblatt ausfüllen — als Blatt, nicht als Formularsammlung.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Feldtest, dreimal: „Ich will das BWLV Formular quasi 1:1 haben zum digital
 * ausfüllen", „der jetztige istzustand ist für den täglichen betrieb nicht
 * brauchbar", und schliesslich unmissverständlich: „Wichtig ist das ich sowohl
 * in der eingabe als auch im druck ein solches formular sehen will, mit den
 * tabellen etc. Ich will auf keinen fall die aktuellen Kacheln die es sind."
 *
 * WARUM DIESE SEITE KEIN FILAMENT-FORMULAR MEHR IST.
 *
 * Ein Filament-Schema rendert jeden Abschnitt als eigene Karte. Selbst mit
 * Tabellenzeilen INNERHALB der Karten bleibt die Seite eine Spalte aus Kacheln
 * -- und ein Wägeblatt ist keine Spalte aus Kacheln, sondern ein Blatt mit
 * nebeneinanderstehenden Tabellen. Das ist nicht durch Feineinstellung zu
 * erreichen; das Kachelraster IST das Formularsystem.
 *
 * Also rendert diese Seite den Blattkörper direkt -- denselben, den der
 * Ausdruck rendert. Der Zustand liegt als schlichte Felder (`kopf`,
 * `bauteile`, `auflagen`), Livewire bindet die Zellen daran, und beim Speichern
 * schreibt diese Klasse zurück.
 *
 * WAS DABEI NICHT VERLORENGEHT: Die Rechte kommen weiter aus der Resource
 * (canEdit), das Einfrieren nach der Abzeichnung sitzt unverändert im Model,
 * und die Rechnung bleibt im Calculator.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class EditWeighing extends Page
{
    /*
     * Filaments eigener Umgang mit dem Datensatz -- und nicht etwa eine eigene
     * Eigenschaft `$record`. Livewire schiebt den Routenparameter in eine
     * gleichnamige oeffentliche Eigenschaft, und eine mit `?Weighing`
     * getypte bekommt dann eine Zahl zugewiesen: „Cannot assign int to
     * property ... of type ?Weighing". Der Trait deklariert sie als
     * `Model|int|string|null` und loest sie selbst auf.
     */
    use InteractsWithRecord;

    protected static string $resource = WeighingResource::class;

    protected string $view = 'fleet.sheet.edit';

    /** @var array<string, mixed> */
    public array $kopf = [];

    /** @var list<array<string, mixed>> */
    public array $bauteile = [];

    /** @var list<array<string, mixed>> */
    public array $auflagen = [];

    /** @var list<array<string, mixed>> Nur beim Motorflugblatt: ausfliegbare Stoffe. */
    public array $abzuege = [];

    /** @var list<array<string, mixed>> Nur beim Motorflugblatt: zugelassene Konfigurationen. */
    public array $konfigurationen = [];

    /** Welche Kopffelder das Blatt kennt -- Lesen und Schreiben aus einer Liste. */
    private const KOPFFELDER = [
        'order_reference', 'datum_reference', 'reference_line',
        'datum_plane', 'fuselage_reference_plane',
        'front_support_arm_mm', 'support_distance_mm',
        'max_mass_kg', 'max_mass_water_kg', 'max_non_lifting_kg',
        'cockpit_load_min_kg', 'cockpit_load_max_kg',
        'cg_range_from_mm', 'cg_range_to_mm', 'cg_range_at_mass_kg',
        'remarks',
    ];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(WeighingResource::canViewAny(), 403);

        $this->blatt()->load(['entries', 'aircraft']);

        $this->fuellen();
    }

    /** Das Blatt, getypt -- der Trait gibt nur ein Model heraus. */
    public function blatt(): Weighing
    {
        $blatt = $this->getRecord();

        assert($blatt instanceof Weighing);

        return $blatt;
    }

    public function getTitle(): string|Htmlable
    {
        return __('fleet.weighing.singular').' '.($this->blatt()->aircraft?->registration ?? '');
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('speichern')
                ->label(__('settings.save'))
                ->icon('heroicon-o-check')
                ->visible(fn (): bool => $this->darfSchreiben())
                ->action('speichern'),

            Action::make('drucken')
                ->label(__('fleet.weighing.print') ?? 'Drucken')
                ->icon('heroicon-o-printer')
                ->url(fn (): string => route('fleet.weighing', ['weighing' => $this->blatt()]))
                ->openUrlInNewTab(),
        ];
    }

    /**
     * Eine weitere Zeile — wie eine zusätzliche Zeile auf dem Papier.
     *
     * Nur im Zustand, nicht in der Datenbank: Gespeichert wird beim Speichern,
     * und eine leere Zeile, die jemand versehentlich anlegt, verschwindet
     * dadurch von selbst wieder.
     */
    public function zeileHinzufuegen(string $abschnitt): void
    {
        match ($abschnitt) {
            'bauteile' => $this->bauteile[] = ['label' => '', 'mass_kg' => null, 'non_lifting_kg' => null],
            'abzuege' => $this->abzuege[] = [
                'label' => '', 'volume_litres' => null,
                'density_kg_per_litre' => 0.72, 'arm_mm' => null,
            ],
            'konfigurationen' => $this->konfigurationen[] = [
                'label' => '', 'useful_load_kg' => null, 'max_mass_kg' => null,
                'cg_from_mm' => null, 'cg_to_mm' => null,
            ],
            default => $this->auflagen[] = [
                'label' => '', 'gross_kg' => null, 'tare_kg' => null, 'arm_mm' => null,
            ],
        };
    }

    public function speichern(): void
    {
        if (! $this->darfSchreiben()) {
            Notification::make()->danger()->title(__('fleet.weighing.locked'))->send();

            return;
        }

        try {
            DB::transaction(function (): void {
                $this->blatt()->fill($this->kopfWerte())->save();

                $this->zeilenSchreiben(WeighingEntry::SECTION_COMPONENT, $this->bauteile, [
                    'mass_kg', 'non_lifting_kg',
                ]);
                $this->zeilenSchreiben(WeighingEntry::SECTION_SUPPORT, $this->auflagen, [
                    'gross_kg', 'tare_kg', 'arm_mm',
                ]);
                $this->zeilenSchreiben(WeighingEntry::SECTION_DEDUCTION, $this->abzuege, [
                    'volume_litres', 'density_kg_per_litre', 'arm_mm',
                ]);
                $this->zeilenSchreiben(WeighingEntry::SECTION_CONFIGURATION, $this->konfigurationen, [
                    'useful_load_kg', 'max_mass_kg', 'cg_from_mm', 'cg_to_mm',
                ]);
            });

            $this->blatt()->load('entries')->recalculate();
        } catch (Throwable $e) {
            Notification::make()->danger()->title(__('fleet.weighing.save_failed'))
                ->body($e->getMessage())->persistent()->send();

            return;
        }

        $this->blatt()->refresh()->load(['entries', 'aircraft']);
        $this->fuellen();

        Notification::make()->success()->title(__('settings.saved'))->send();
    }

    /**
     * Zeilen eines Abschnitts abgleichen: vorhandene aktualisieren, neue
     * anlegen, entfernte löschen.
     *
     * Über die Reihenfolge und nicht über Schlüssel, weil das Blatt eine
     * Reihenfolge HAT -- die des Papiers. Wer eine Zeile einfügt, verschiebt
     * die darunter, und genau das soll auch in der Datenbank ankommen.
     *
     * @param  list<array<string, mixed>>  $zeilen
     * @param  list<string>  $felder
     */
    private function zeilenSchreiben(string $section, array $zeilen, array $felder): void
    {
        $vorhanden = $this->blatt()->entries
            ->where('section', $section)
            ->sortBy([['position', 'asc'], ['id', 'asc']])
            ->values();

        $position = 0;

        foreach ($zeilen as $zeile) {
            // Eine ganz leere Zeile ist keine Zeile -- sie entsteht durch einen
            // Klick auf „hinzufuegen", den jemand nicht gebraucht hat.
            if (trim((string) ($zeile['label'] ?? '')) === '' && $this->alleLeer($zeile, $felder)) {
                continue;
            }

            $werte = ['label' => (string) ($zeile['label'] ?? ''), 'position' => $position];

            foreach ($felder as $feld) {
                $werte[$feld] = $this->zahl($zeile[$feld] ?? null);
            }

            $satz = $vorhanden->get($position);

            if ($satz instanceof WeighingEntry) {
                $satz->update($werte);
            } else {
                WeighingEntry::create($werte + [
                    'weighing_id' => $this->blatt()->id,
                    'section' => $section,
                ]);
            }

            $position++;
        }

        // Was hinten übrig bleibt, hat jemand entfernt.
        $vorhanden->slice($position)->each(fn (WeighingEntry $e) => $e->delete());
    }

    /**
     * @param  array<string, mixed>  $zeile
     * @param  list<string>  $felder
     */
    private function alleLeer(array $zeile, array $felder): bool
    {
        foreach ($felder as $feld) {
            if ($this->zahl($zeile[$feld] ?? null) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Eingetipptes in eine Zahl — mit Komma, weil hier deutsch getippt wird.
     *
     * „82,5" ist die Schreibweise auf dem Blatt und die, die jemand eingibt;
     * (float) macht daraus 82. Ein Blatt, das stillschweigend die
     * Nachkommastellen verliert, wäre die schlimmste Sorte Fehler.
     */
    private function zahl(mixed $wert): ?float
    {
        if ($wert === null || $wert === '') {
            return null;
        }

        $text = str_replace([' ', '.'], '', (string) $wert);
        $text = str_replace(',', '.', $text);

        return is_numeric($text) ? (float) $text : null;
    }

    /** @return array<string, mixed> */
    private function kopfWerte(): array
    {
        $werte = [];

        foreach (self::KOPFFELDER as $feld) {
            $roh = $this->kopf[$feld] ?? null;

            $werte[$feld] = in_array($feld, ['order_reference', 'datum_reference', 'reference_line', 'remarks'], true)
                ? (($roh === '' ? null : $roh))
                : $this->zahl($roh);
        }

        return $werte;
    }

    private function darfSchreiben(): bool
    {
        return ! $this->blatt()->isSignedOff()
            && WeighingResource::canEdit($this->blatt());
    }

    private function fuellen(): void
    {
        $this->kopf = [];

        foreach (self::KOPFFELDER as $feld) {
            $this->kopf[$feld] = $this->blatt()->{$feld};
        }

        $this->bauteile = $this->blatt()->entriesOf(WeighingEntry::SECTION_COMPONENT)
            ->map(fn (WeighingEntry $e): array => [
                'label' => $e->label,
                'mass_kg' => $e->mass_kg,
                'non_lifting_kg' => $e->non_lifting_kg,
            ])->all();

        $this->auflagen = $this->blatt()->entriesOf(WeighingEntry::SECTION_SUPPORT)
            ->map(fn (WeighingEntry $e): array => [
                'label' => $e->label,
                'gross_kg' => $e->gross_kg,
                'tare_kg' => $e->tare_kg,
                'arm_mm' => $e->arm_mm,
            ])->all();

        $this->abzuege = $this->blatt()->entriesOf(WeighingEntry::SECTION_DEDUCTION)
            ->map(fn (WeighingEntry $e): array => [
                'label' => $e->label,
                'volume_litres' => $e->volume_litres,
                'density_kg_per_litre' => $e->density_kg_per_litre,
                'arm_mm' => $e->arm_mm,
            ])->all();

        $this->konfigurationen = $this->blatt()->entriesOf(WeighingEntry::SECTION_CONFIGURATION)
            ->map(fn (WeighingEntry $e): array => [
                'label' => $e->label,
                'useful_load_kg' => $e->useful_load_kg,
                'max_mass_kg' => $e->max_mass_kg,
                'cg_from_mm' => $e->cg_from_mm,
                'cg_to_mm' => $e->cg_to_mm,
            ])->all();
    }
}
