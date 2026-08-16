<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\Weighings\Schemas;

use App\Modules\Fleet\Enums\SheetVariant;
use App\Modules\Fleet\Enums\Undercarriage;
use App\Modules\Fleet\Enums\WeighingKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\Weighing;
use App\Modules\Fleet\Models\WeighingEntry;
use App\Modules\Fleet\Support\WeighingCalculator;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * The weighing sheet as a form that adds up.
 *
 * Laid out like the BWLV paper, because the person filling it in is copying from
 * that paper, and a form in a different order turns transcription into
 * translation.
 *
 * The result panel recalculates as the rows change, which is the whole point of
 * doing this in software rather than with a calculator beside the sheet -- and
 * it shows the findings, so a centre of gravity out of range is visible before
 * anybody signs rather than after.
 */
final class WeighingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('fleet.weighing.singular'))
                ->schema([
                    Select::make('aircraft_id')
                        ->label(__('fleet.aircraft.singular'))
                        ->options(fn (): array => Aircraft::orderBy('registration')
                            ->pluck('registration', 'id')->all())
                        ->searchable()
                        ->required(),

                    /*
                     * DIE BLATTART, nicht der Rechenweg -- „drei, wie auf dem
                     * papier". Gerechnet wird auf zwei Arten, ueberschrieben
                     * sind die Blaetter mit drei Namen, und danach sucht
                     * derjenige, der abschreibt. `kind` faellt daraus ab und
                     * steht unsichtbar daneben.
                     */
                    Select::make('sheet_variant')
                        ->label(__('fleet.weighing.field.sheet_variant'))
                        ->options(SheetVariant::options())
                        ->default(SheetVariant::Glider->value)
                        ->selectablePlaceholder(false)
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                            $variante = SheetVariant::tryFrom((string) $state) ?? SheetVariant::Glider;

                            $set('kind', $variante->kind()->value);
                            $set('undercarriage', Undercarriage::defaultFor($variante)->value);
                        }),

                    Hidden::make('kind')->default(WeighingKind::Glider->value),

                    /*
                     * WORAUF ES BEIM WIEGEN STEHT. Bestimmt die Zahl der
                     * Waegepunkte und die Zeichnung -- vorher hing beides an
                     * der Blattart, was beim einraedrigen Segelflugzeug
                     * zufaellig stimmte und beim Motorsegler mit Bugrad nicht.
                     */
                    Select::make('undercarriage')
                        ->label(__('fleet.weighing.field.undercarriage'))
                        ->options(Undercarriage::options())
                        ->default(Undercarriage::TailwheelOneMain->value)
                        ->selectablePlaceholder(false)
                        ->required()
                        ->live(),

                    DatePicker::make('weighed_at')
                        ->label(__('fleet.weighing.field.weighed_at'))
                        ->default(now())
                        ->required(),

                    DatePicker::make('valid_until')
                        ->label(__('fleet.weighing.field.valid_until')),

                    TextInput::make('place')->label(__('fleet.weighing.field.place'))->maxLength(120),
                    TextInput::make('order_reference')->label(__('fleet.weighing.field.order_reference'))->maxLength(64),

                    TextInput::make('datum_reference')
                        ->label(__('fleet.weighing.field.datum_reference'))
                        ->maxLength(160),

                    TextInput::make('reference_line')
                        ->label(__('fleet.weighing.field.reference_line'))
                        ->maxLength(160),
                ])
                ->columns(2),

            /*
             * The glider sheet, weighed component by component. Each row carries
             * two figures because the non-lifting parts have a limit of their
             * own -- a wing lifts, a fuselage does not, and no arithmetic on the
             * totals can tell them apart.
             */
            Section::make(__('fleet.weighing.section.component'))
                ->visible(fn (callable $get): bool => $get('kind') === WeighingKind::Glider->value)
                ->schema([
                    Repeater::make('componentEntries')
                        ->hiddenLabel()
                        ->relationship('entries', fn ($query) => $query->where('section', WeighingEntry::SECTION_COMPONENT))
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => $data + ['section' => WeighingEntry::SECTION_COMPONENT])
                        /*
                         * ALS TABELLE, NICHT ALS KACHELN. Feldtest: „immer noch
                         * beim anlegen der wägung die kacheln zum werte
                         * eintragen ... Ich will das Formular quasi 1:1 haben
                         * zum digital ausfüllen." Ein Wiederholfeld rendert ab
                         * Werk je Zeile eine umrandete Karte; auf einem
                         * Waegeblatt mit dreizehn vorgedruckten Zeilen ist das
                         * dreizehnmal derselbe Rahmen um zwei Zahlen.
                         */
                        ->table([
                            TableColumn::make(__('fleet.weighing.field.component'))->markAsRequired(),
                            TableColumn::make(__('fleet.weighing.field.empty_mass').' [kg]'),
                            TableColumn::make('M.N.T. [kg]'),
                        ])
                        ->schema([
                            TextInput::make('label')->hiddenLabel()->required(),
                            TextInput::make('mass_kg')->hiddenLabel()->numeric()->live(onBlur: true),
                            TextInput::make('non_lifting_kg')->hiddenLabel()->numeric()->live(onBlur: true),
                        ])
                        ->defaultItems(0)
                        // Die Reihenfolge traegt: Sie ist die des Papierblatts.
                        ->orderColumn('position')
                        ->addActionLabel(__('fleet.weighing.add_component')),
                ]),

            Section::make(__('fleet.weighing.section.support'))
                ->description(__('fleet.weighing.help.arm_sign'))
                ->schema([
                    TextInput::make('front_support_arm_mm')
                        ->label(__('fleet.weighing.field.front_support_arm'))
                        ->numeric()
                        ->live(onBlur: true)
                        ->suffix('mm'),

                    TextInput::make('support_distance_mm')
                        ->label(__('fleet.weighing.field.support_distance'))
                        ->numeric()
                        ->live(onBlur: true)
                        ->suffix('mm'),

                    Repeater::make('supportEntries')
                        ->hiddenLabel()
                        ->relationship('entries', fn ($query) => $query->where('section', WeighingEntry::SECTION_SUPPORT))
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => $data + ['section' => WeighingEntry::SECTION_SUPPORT])
                        ->table([
                            TableColumn::make(__('fleet.weighing.field.support'))->markAsRequired(),
                            TableColumn::make(__('fleet.weighing.field.gross').' [kg]'),
                            TableColumn::make(__('fleet.weighing.field.tare').' [kg]'),
                            TableColumn::make(__('fleet.weighing.field.arm').' [mm]'),
                        ])
                        ->schema([
                            TextInput::make('label')->hiddenLabel()->required(),
                            TextInput::make('gross_kg')->hiddenLabel()->numeric()->live(onBlur: true),
                            TextInput::make('tare_kg')->hiddenLabel()->numeric()->live(onBlur: true),
                            TextInput::make('arm_mm')->hiddenLabel()->numeric()->live(onBlur: true),
                        ])
                        ->defaultItems(0)
                        ->orderColumn('position')
                        // Ohne eigene Beschriftung hiess der Knopf "Zu support
                        // entries hinzufügen" -- maschinell aus dem Feldnamen
                        // abgeleitet und in einem deutschen Wägeformular
                        // schlicht falsch.
                        ->addActionLabel(__('fleet.weighing.add_support'))
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make(__('fleet.weighing.section.deduction'))
                ->description(__('fleet.weighing.help.deduction_arm'))
                ->visible(fn (callable $get): bool => $get('kind') === WeighingKind::Powered->value)
                ->schema([
                    Repeater::make('deductionEntries')
                        ->hiddenLabel()
                        ->relationship('entries', fn ($query) => $query->where('section', WeighingEntry::SECTION_DEDUCTION))
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => $data + ['section' => WeighingEntry::SECTION_DEDUCTION])
                        ->table([
                            TableColumn::make(__('fleet.weighing.field.tank'))->markAsRequired(),
                            TableColumn::make(__('fleet.weighing.field.volume').' [l]'),
                            TableColumn::make(__('fleet.weighing.field.density').' [kg/l]'),
                            TableColumn::make(__('fleet.weighing.field.arm').' [mm]'),
                        ])
                        ->schema([
                            TextInput::make('label')->hiddenLabel()->required(),
                            TextInput::make('volume_litres')->hiddenLabel()->numeric()->live(onBlur: true),
                            TextInput::make('density_kg_per_litre')->hiddenLabel()->numeric()->default(0.72)->live(onBlur: true),
                            TextInput::make('arm_mm')->hiddenLabel()->numeric()->live(onBlur: true),
                        ])
                        ->defaultItems(0)
                        ->orderColumn('position')
                        ->addActionLabel(__('fleet.weighing.add_deduction')),
                ]),

            /*
             * The loading plan's inputs. Separate from the empty-mass limits
             * above, and the separation is the point: the in-flight range is a
             * different pair of numbers, and using one for the other would be
             * wrong in the direction that lets somebody heavy sit down.
             */
            Section::make(__('fleet.loading.title'))
                ->description(__('fleet.loading.check_manual'))
                ->schema([
                    TextInput::make('flight_cg_from_mm')
                        ->label(__('fleet.loading.flight_cg').' xv')
                        ->numeric()->live(onBlur: true)->suffix('mm'),

                    TextInput::make('flight_cg_to_mm')
                        ->label(__('fleet.loading.flight_cg').' xh')
                        ->numeric()->live(onBlur: true)->suffix('mm'),

                    Repeater::make('seatEntries')
                        ->label(__('fleet.loading.seat'))
                        ->relationship('entries', fn ($query) => $query->where('section', WeighingEntry::SECTION_SEAT))
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => $data + ['section' => WeighingEntry::SECTION_SEAT])
                        ->table([
                            TableColumn::make(__('fleet.loading.seat'))->markAsRequired(),
                            TableColumn::make(__('fleet.loading.arm').' [mm]'),
                        ])
                        ->schema([
                            TextInput::make('label')->hiddenLabel()->required(),
                            TextInput::make('arm_mm')->hiddenLabel()->numeric()->live(onBlur: true),
                        ])
                        ->defaultItems(0)
                        ->orderColumn('position')
                        ->columnSpanFull()
                        ->addActionLabel(__('fleet.loading.seat')),

                    Placeholder::make('loading_plan')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->content(fn (?Weighing $record): HtmlString => self::loadingPanel($record)),
                ])
                ->columns(2),

            Section::make(__('fleet.weighing.section.limits'))
                ->schema([
                    TextInput::make('max_mass_kg')->label(__('fleet.weighing.field.max_mass'))->numeric()->live(onBlur: true)->suffix('kg'),
                    TextInput::make('max_mass_water_kg')->label(__('fleet.weighing.field.max_mass_water'))->numeric()->suffix('kg'),
                    TextInput::make('max_non_lifting_kg')->label(__('fleet.weighing.field.max_non_lifting'))->numeric()->live(onBlur: true)->suffix('kg'),
                    TextInput::make('cg_range_from_mm')->label(__('fleet.weighing.field.cg_range').' von')->numeric()->live(onBlur: true)->suffix('mm'),
                    TextInput::make('cg_range_to_mm')->label(__('fleet.weighing.field.cg_range').' bis')->numeric()->live(onBlur: true)->suffix('mm'),
                    // „... bei Leermasse __ kg" -- die Bezugsmasse stand auf
                    // dem Blatt und nirgends im Schema. Ohne sie ist der
                    // Bereich nur die halbe Aussage.
                    TextInput::make('cg_range_at_mass_kg')->label(__('fleet.weighing.field.cg_range_at_mass'))->numeric()->suffix('kg'),
                    TextInput::make('cockpit_load_min_kg')->label(__('fleet.weighing.field.cockpit_load').' min')->numeric()->suffix('kg'),
                    TextInput::make('cockpit_load_max_kg')->label(__('fleet.weighing.field.cockpit_load').' max')->numeric()->suffix('kg'),
                ])
                ->columns(3),

            /*
             * The answer, recalculated as the rows change. The reason for doing
             * this in software rather than with a calculator beside the sheet:
             * a centre of gravity out of range shows up before somebody signs,
             * not after.
             */
            Section::make(__('fleet.weighing.section.result'))
                ->schema([
                    Placeholder::make('result')
                        ->hiddenLabel()
                        ->content(fn (?Weighing $record): HtmlString => self::resultPanel($record)),

                    /*
                     * Die Skizze auch HIER, nicht nur im Druck -- Feldtest:
                     * "bei den wägungen will ich die grafik nicht nur beim
                     * drucken, sondern auch in der maske haben."
                     *
                     * AUS DEM FORMULARZUSTAND, nicht aus dem gespeicherten
                     * Datensatz: Die erste Fassung las den Record und blieb
                     * deshalb beim Ausfüllen leer -- Feldtest: "hab ich immer
                     * noch das alte Formular statt der gewünschten grafik".
                     * Die Auflagenfelder sind live, also zeichnet sie mit.
                     */
                    Placeholder::make('sketch')
                        ->hiddenLabel()
                        ->content(fn (Get $get, ?Weighing $record): HtmlString => self::sketchPanel($get, $record))
                        ->columnSpanFull(),
                ]),

            Section::make(__('fleet.review.field.issued_by_name'))
                ->schema([
                    TextInput::make('signed_by_name')->label(__('fleet.review.field.issued_by_name'))->maxLength(160),
                    TextInput::make('signed_by_approval')->label(__('fleet.review.field.issued_by_approval'))->maxLength(64),
                    DatePicker::make('equipment_list_dated')->label(__('fleet.weighing.field.equipment_list_dated')),
                    Textarea::make('remarks')->label(__('fleet.aircraft.field.note'))->rows(2)->columnSpanFull(),
                ])
                ->columns(3),
        ]);
    }

    /**
     * Die Skizze zur Schwerpunktermittlung -- passend zur Wägungsart.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * ZWEI ZEICHNUNGEN, weil es zwei Rechenwege sind: Das Segelflugblatt legt
     * einen Hebel zwischen zwei Auflagen (X = G2·b/G + a), das Motorflugblatt
     * summiert Momente über drei Auflagen mit je eigenem Arm. Dieselbe Skizze
     * für beides wäre das falsche Bild zur richtigen Zahl.
     *
     * Gelesen wird der FORMULARZUSTAND: Wer die Wägung gerade einträgt, soll
     * die Skizze mitwachsen sehen -- nicht erst nach dem Speichern.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private static function sketchPanel(Get $get, ?Weighing $record): HtmlString
    {
        $entwurf = self::sheetFromForm($get, $record);

        $auflagen = $entwurf->entriesOf(WeighingEntry::SECTION_SUPPORT);

        /*
         * Nichts zu zeichnen -- aber NICHT schweigen: Eine leere Stelle sieht
         * aus wie ein Fehler, und genau so wurde sie auch gemeldet.
         */
        if ($auflagen->isEmpty()) {
            return new HtmlString(
                '<p class="text-sm text-gray-500 dark:text-gray-400">'
                .e(__('fleet.weighing.sketch_pending'))
                .'</p>',
            );
        }

        /*
         * DAS BILD FOLGT DER BLATTART, nicht der Zahl der Auflagen.
         *
         * Vorher entschied `count($supports) >= 3` -- und lag damit falsch,
         * sobald ein Motorsegler auf zwei Punkten stand oder jemand beim
         * Segelflugzeug eine dritte Zeile anlegte: Gezeichnet wurde das
         * Momentenbild, gerechnet der Hebel. Ein Bild, das der danebenstehenden
         * Zahl widerspricht, ist schlechter als gar keines -- geglaubt wird das
         * Bild.
         */
        $partial = $entwurf->kind === WeighingKind::Glider
            ? 'fleet.sheet._sketch_lever'
            : 'fleet.sheet._sketch_moments';

        return new HtmlString(view($partial, ['weighing' => $entwurf])->render());
    }

    /**
     * Der Formularstand als Blatt -- ungespeichert.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Zeichnung und Druck teilen sich EIN Partial, und das erwartet ein
     * Weighing. Die Maske hat aber noch keins: Wer gerade eintippt, hat nichts
     * gespeichert -- und genau da soll die Skizze mitwachsen („bei den wägungen
     * will ich die grafik nicht nur beim drucken, sondern auch in der maske
     * haben", und später: „hab ich immer noch das alte Formular").
     *
     * Also wird eins gebaut, das nie in die Datenbank geht, und seine
     * Beziehung von Hand gesetzt. Der Umweg ist der Preis dafür, dass Bild und
     * Ausdruck von derselben Vorlage kommen -- zwei Vorlagen waren zwei Orte,
     * an denen dieselbe Zeichnung auseinanderlief, und das ist genau passiert.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private static function sheetFromForm(Get $get, ?Weighing $record): Weighing
    {
        $wert = function (string $feld) use ($get, $record) {
            $eingabe = $get($feld);

            if ($eingabe !== null && $eingabe !== '') {
                return $eingabe;
            }

            return $record?->{$feld};
        };

        $entwurf = new Weighing;

        $entwurf->kind = WeighingKind::tryFrom((string) $get('kind'))
            ?? $record?->kind
            ?? WeighingKind::Glider;

        $entwurf->undercarriage = Undercarriage::tryFrom((string) $get('undercarriage'))
            ?? $record?->undercarriage;

        $entwurf->front_support_arm_mm = $wert('front_support_arm_mm');
        $entwurf->support_distance_mm = $wert('support_distance_mm');
        $entwurf->datum_reference = $wert('datum_reference');
        $entwurf->reference_line = $wert('reference_line');

        $zeilen = [];

        foreach (self::supportsFromForm($get, $record) as $position => $auflage) {
            $zeile = new WeighingEntry;
            $zeile->section = WeighingEntry::SECTION_SUPPORT;
            $zeile->label = $auflage['label'];
            $zeile->position = $position;
            // netto() rechnet Brutto minus Tara; hier ist die Netto-Masse schon
            // ermittelt, also steht sie als Brutto ohne Tara.
            $zeile->gross_kg = $auflage['mass'];
            $zeile->arm_mm = $auflage['arm'];

            $zeilen[] = $zeile;
        }

        $entwurf->setRelation('entries', collect($zeilen));

        return $entwurf;
    }

    /**
     * Die Auflagen, wie sie GERADE im Formular stehen.
     *
     * Fällt auf den gespeicherten Stand zurück, solange der Repeater noch
     * nichts in den Zustand geschrieben hat (frisch geöffnetes Formular).
     *
     * @return list<array{label: string, mass: float, arm: float|null}>
     */
    private static function supportsFromForm(Get $get, ?Weighing $record): array
    {
        $rows = $get('supportEntries');

        if (! is_array($rows) || $rows === []) {
            return $record === null ? [] : $record->entriesOf(WeighingEntry::SECTION_SUPPORT)
                ->map(fn (WeighingEntry $e): array => [
                    'label' => (string) $e->label,
                    'mass' => $e->netto(),
                    'arm' => $e->arm_mm === null ? null : (float) $e->arm_mm,
                ])
                ->values()
                ->all();
        }

        $supports = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $arm = $row['arm_mm'] ?? null;

            $supports[] = [
                'label' => (string) ($row['label'] ?? ''),
                // Netto wie im Modell: brutto minus Tara, nie negativ.
                'mass' => max(0.0, (float) ($row['gross_kg'] ?? 0) - (float) ($row['tare_kg'] ?? 0)),
                'arm' => ($arm === null || $arm === '') ? null : (float) $arm,
            ];
        }

        return $supports;
    }

    /**
     * The permitted seat loads, shown while the sheet is being filled in.
     */
    private static function loadingPanel(?Weighing $record): HtmlString
    {
        if ($record === null) {
            return new HtmlString('');
        }

        $plan = $record->load('entries')->loadingPlan();

        if (! $plan->computable) {
            return new HtmlString('<p class="text-sm text-gray-500">'.e($plan->notes[0] ?? '').'</p>');
        }

        $html = '<table class="w-full text-sm"><thead><tr class="text-left text-xs text-gray-500">'
            .'<th>'.e(__('fleet.loading.seat')).'</th>'
            .'<th class="text-right">'.e(__('fleet.loading.min')).'</th>'
            .'<th class="text-right">'.e(__('fleet.loading.max')).'</th>'
            .'<th></th></tr></thead><tbody>';

        foreach ($plan->seats as $seat) {
            $html .= '<tr>'
                .'<td>'.e($seat['seat']).'</td>'
                .'<td class="text-right">'.number_format($seat['min'], 1, ',', '.').' kg</td>'
                .'<td class="text-right font-medium">'.number_format($seat['max'], 1, ',', '.').' kg</td>'
                .'<td class="text-xs text-gray-500">'.e(__('fleet.loading.limited_by.'.$seat['limited_by'])).'</td>'
                .'</tr>';
        }

        return new HtmlString($html.'</tbody></table>');
    }

    private static function resultPanel(?Weighing $record): HtmlString
    {
        if ($record === null) {
            return new HtmlString('<p class="text-sm text-gray-500">'.e(__('fleet.weighing.help.stored')).'</p>');
        }

        $result = app(WeighingCalculator::class)->calculate($record->load('entries'));

        $rows = [
            __('fleet.weighing.field.empty_mass') => number_format($result->emptyMassKg, 2, ',', '.').' kg',
            __('fleet.weighing.field.empty_cg') => $result->emptyCgMm === null
                ? '—'
                : number_format($result->emptyCgMm, 1, ',', '.').' mm',
            __('fleet.weighing.field.non_lifting') => $result->nonLiftingMassKg === null
                ? '—'
                : number_format($result->nonLiftingMassKg, 2, ',', '.').' kg',
            __('fleet.weighing.field.useful_load') => $result->usefulLoadKg === null
                ? '—'
                : number_format($result->usefulLoadKg, 2, ',', '.').' kg',
        ];

        $html = '<dl class="grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-4">';

        foreach ($rows as $label => $value) {
            $html .= '<div><dt class="text-xs text-gray-500">'.e($label).'</dt>'
                .'<dd class="font-medium">'.e($value).'</dd></div>';
        }

        $html .= '</dl>';

        if (! $record->figuresMatchRows()) {
            $html .= '<p class="mt-3 text-sm font-medium text-warning-600 dark:text-warning-400">'
                .e(__('fleet.weighing.figures_drifted')).'</p>';
        }

        if ($result->isAcceptable()) {
            $html .= '<p class="mt-3 text-sm text-success-600 dark:text-success-400">'
                .e(__('fleet.weighing.in_range')).'</p>';
        } else {
            foreach ($result->findings as $finding) {
                $html .= '<p class="mt-3 text-sm font-medium text-danger-600 dark:text-danger-400">'
                    .e($finding).'</p>';
            }
        }

        return new HtmlString($html);
    }
}
