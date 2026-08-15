<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\Weighings\Schemas;

use App\Modules\Fleet\Enums\WeighingKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\Weighing;
use App\Modules\Fleet\Models\WeighingEntry;
use App\Modules\Fleet\Support\WeighingCalculator;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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

                    Select::make('kind')
                        ->label(__('fleet.weighing.kind.glider').' / '.__('fleet.weighing.kind.powered'))
                        ->options(collect(WeighingKind::cases())
                            ->mapWithKeys(fn (WeighingKind $k): array => [$k->value => $k->label()])
                            ->all())
                        ->default(WeighingKind::Glider->value)
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
                        ->schema([
                            TextInput::make('label')->hiddenLabel()->required()->columnSpan(2),
                            TextInput::make('mass_kg')->label(__('fleet.weighing.field.empty_mass'))->numeric()->live(onBlur: true)->suffix('kg'),
                            TextInput::make('non_lifting_kg')
                                ->label('M.N.T.')
                                ->numeric()
                                ->live(onBlur: true)
                                ->suffix('kg')
                                ->helperText(__('fleet.weighing.help.non_lifting')),
                        ])
                        ->columns(5)
                        ->defaultItems(0)
                        ->addActionLabel(__('fleet.weighing.section.component')),
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
                        ->schema([
                            TextInput::make('label')->hiddenLabel()->required(),
                            TextInput::make('gross_kg')->label(__('fleet.weighing.field.gross'))->numeric()->live(onBlur: true)->suffix('kg'),
                            TextInput::make('tare_kg')->label(__('fleet.weighing.field.tare'))->numeric()->live(onBlur: true)->suffix('kg'),
                            TextInput::make('arm_mm')->label(__('fleet.weighing.field.arm'))->numeric()->live(onBlur: true)->suffix('mm'),
                        ])
                        ->columns(4)
                        ->defaultItems(0)
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
                        ->schema([
                            TextInput::make('label')->hiddenLabel()->required(),
                            TextInput::make('volume_litres')->label(__('fleet.weighing.field.volume'))->numeric()->live(onBlur: true),
                            TextInput::make('density_kg_per_litre')
                                ->label(__('fleet.weighing.field.density'))
                                ->numeric()
                                ->default(0.72)
                                ->live(onBlur: true),
                            TextInput::make('arm_mm')->label(__('fleet.weighing.field.arm'))->numeric()->live(onBlur: true)->suffix('mm'),
                        ])
                        ->columns(4)
                        ->defaultItems(0),
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
                        ->schema([
                            TextInput::make('label')->hiddenLabel()->required(),
                            TextInput::make('arm_mm')
                                ->label(__('fleet.loading.arm'))
                                ->numeric()->live(onBlur: true)->suffix('mm'),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
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
                    TextInput::make('cockpit_load_min_kg')->label(__('fleet.weighing.field.cockpit_load').' min')->numeric()->suffix('kg'),
                    TextInput::make('cockpit_load_max_kg')->label(__('fleet.weighing.field.cockpit_load').' max')->numeric()->suffix('kg'),
                ])
                ->columns(3)
                ->collapsed(),

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
        $fmt = fn (?float $v, int $d = 2): string => $v === null ? '' : number_format($v, $d, ',', '.');
        $supports = self::supportsFromForm($get, $record);
        $kind = WeighingKind::tryFrom((string) $get('kind')) ?? $record?->kind ?? WeighingKind::Glider;

        $total = array_sum(array_map(static fn (array $s): float => $s['mass'], $supports));

        // Zwei Auflagen: der Hebel des Segelflugblattes.
        if (count($supports) === 2 && $kind !== WeighingKind::Powered) {
            $a = (float) ($get('front_support_arm_mm') ?? $record?->front_support_arm_mm ?? 0);
            $b = $get('support_distance_mm') ?? $record?->support_distance_mm;
            $b = $b === null || $b === '' ? null : (float) $b;

            $x = ($b !== null && $total > 0)
                ? round(($supports[1]['mass'] * $b) / $total + $a, 2)
                : null;

            return new HtmlString(view('fleet.print._weighing_sketch', [
                'a' => $a,
                'b' => $b,
                'g1' => $supports[0]['mass'],
                'g2' => $supports[1]['mass'],
                'g' => $total,
                'x' => $x,
                'fmt' => $fmt,
            ])->render());
        }

        // Drei und mehr: Momente, jede Auflage mit ihrem Arm.
        if (count($supports) >= 3 || ($kind === WeighingKind::Powered && count($supports) >= 2)) {
            $moment = 0.0;
            $vollstaendig = true;

            foreach ($supports as $s) {
                if ($s['arm'] === null) {
                    $vollstaendig = false;

                    continue;
                }

                $moment += $s['mass'] * $s['arm'];
            }

            return new HtmlString(view('fleet.print._weighing_moment_sketch', [
                'supports' => $supports,
                'total' => $total,
                'x' => ($vollstaendig && $total > 0) ? round($moment / $total, 2) : null,
                'fmt' => $fmt,
            ])->render());
        }

        /*
         * Nichts zu zeichnen -- aber NICHT schweigen: Eine leere Stelle sieht
         * aus wie ein Fehler, und genau so wurde sie auch gemeldet.
         */
        return new HtmlString(
            '<p class="text-sm text-gray-500 dark:text-gray-400">'
            .e(__('fleet.weighing.sketch_pending'))
            .'</p>',
        );
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
