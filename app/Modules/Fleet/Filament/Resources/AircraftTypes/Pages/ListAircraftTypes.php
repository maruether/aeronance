<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\AircraftTypes\Pages;

use App\Modules\Fleet\Actions\AdoptTypeCertificate;
use App\Modules\Fleet\Filament\Resources\AircraftTypes\AircraftTypeResource;
use App\Modules\Fleet\Models\AircraftType;
use App\Modules\Fleet\Permissions;
use App\Modules\Fleet\TypeCertificates\CertificateSubject;
use App\Modules\Fleet\TypeCertificates\TypeCertificateCandidate;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Throwable;

/**
 * The searchable list, with the authority lookup hanging off it.
 *
 * The lookup is a two-step action on purpose: search, then pick. A one-shot
 * "fetch the certificate for this name" would have to guess which hit is right,
 * and "ASK 21" against an authority's library returns several.
 */
final class ListAircraftTypes extends ListRecords
{
    protected static string $resource = AircraftTypeResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('designation')
            ->columns([
                TextColumn::make('designation')
                    ->label(__('fleet.type.field.designation'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('manufacturer')
                    ->label(__('fleet.type.field.manufacturer'))
                    ->searchable()
                    ->placeholder('—'),

                /*
                 * One column for both answers, because they answer one question.
                 * The orphaned case is a badge in danger colours rather than a
                 * name, since it is the only value here that changes how every
                 * directive list for this type has to be read.
                 */
                TextColumn::make('type_support')
                    ->label(__('fleet.type.field.type_support'))
                    ->searchable()
                    ->badge()
                    ->state(fn (AircraftType $r): string => $r->isOrphaned()
                        ? __('fleet.type.orphaned.badge')
                        : ($r->type_support ?: '—'))
                    ->color(fn (AircraftType $r): string => $r->isOrphaned() ? 'danger' : 'gray'),

                TextColumn::make('type_certificate')
                    ->label(__('fleet.type.field.type_certificate'))
                    ->searchable()
                    ->badge()
                    ->color(fn (?string $state): string => $state === null ? 'warning' : 'success')
                    ->placeholder(__('fleet.type.no_certificate')),

                /*
                 * Die weiteren Nummern, damit sichtbar ist, wonach ein Muster
                 * sonst noch gefunden wird. Standardmässig eingeklappt: sie
                 * betreffen nur Muster mit Vorgeschichte, und die Spalte, die
                 * jeden Tag zählt, ist die führende Nummer darüber.
                 */
                TextColumn::make('other_certificates')
                    ->label(__('fleet.type.field.other_certificates'))
                    ->state(fn (AircraftType $r): string => $r->certificates()
                        ->where('is_primary', false)
                        ->pluck('number')
                        ->implode(', ') ?: '—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('certificate_authority')
                    ->label(__('fleet.type.field.authority'))
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? '—'
                        : __('fleet.type.authority.'.$state))
                    ->toggleable(),

                IconColumn::make('has_data_sheet')
                    ->label(__('fleet.type.field.data_sheet'))
                    ->boolean()
                    ->state(fn (AircraftType $r): bool => $r->hasDataSheet()),

                TextColumn::make('aircraft_count')
                    ->label(__('fleet.type.field.in_fleet'))
                    ->counts('aircraft')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('data_sheet_checked_at')
                    ->label(__('fleet.type.field.checked_at'))
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('documented')
                    ->label(__('fleet.type.filter.documented'))
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('type_certificate'),
                        false: fn ($q) => $q->whereNull('type_certificate'),
                        blank: fn ($q) => $q,
                    ),

                TernaryFilter::make('without_type_support')
                    ->label(__('fleet.type.filter.orphaned'))
                    ->queries(
                        true: fn ($q) => $q->where('without_type_support', true),
                        false: fn ($q) => $q->where('without_type_support', false),
                        blank: fn ($q) => $q,
                    ),
            ])
            ->recordActions([
                $this->lookupAction(),
                EditAction::make()->schema(self::formSchema()),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->schema(self::formSchema()),
        ];
    }

    /** @return list<Component> */
    public static function formSchema(): array
    {
        return [
            TextInput::make('designation')
                ->label(__('fleet.type.field.designation'))
                ->required()
                ->maxLength(96)
                ->unique(ignoreRecord: true)
                ->helperText(__('fleet.type.help.free_text')),

            TextInput::make('manufacturer')
                ->label(__('fleet.type.field.manufacturer'))
                ->maxLength(160),

            /*
             * The two halves of one question, kept side by side: who looks after
             * the type, and the case where the answer is nobody.
             *
             * The checkbox is NOT derived from an empty name field. "Nobody has
             * filled this in yet" and "there is nobody left" are different
             * statements, and only the second one earns a warning on every list.
             *
             * Both stay plainly editable rather than switching each other on and
             * off. Should somebody enter both, the flag wins wherever it is read
             * -- see AircraftType::typeSupport() -- so the contradiction is
             * resolved in one place instead of being fought over in form state.
             */
            TextInput::make('type_support')
                ->label(__('fleet.type.field.type_support'))
                ->maxLength(160)
                ->helperText(__('fleet.type.help.type_support')),

            Checkbox::make('without_type_support')
                ->label(__('fleet.type.field.without_type_support'))
                ->helperText(__('fleet.type.help.without_type_support')),

            TextInput::make('type_certificate')
                ->label(__('fleet.type.field.type_certificate'))
                ->maxLength(64)
                ->helperText(__('fleet.type.help.certificate_notation')),

            Select::make('certificate_authority')
                ->label(__('fleet.type.field.authority'))
                ->options([
                    AircraftType::AUTHORITY_EASA => __('fleet.type.authority.easa'),
                    AircraftType::AUTHORITY_FAA => __('fleet.type.authority.faa'),
                    AircraftType::AUTHORITY_LBA => __('fleet.type.authority.lba'),
                    AircraftType::AUTHORITY_OTHER => __('fleet.type.authority.other'),
                ]),

            /*
             * ─────────────────────────────────────────────────────────────────
             * DIE WEITEREN KENNBLATTNUMMERN DESSELBEN MUSTERS.
             *
             * Vorgabe: ein Flugzeug, das ursprünglich national zugelassen war und
             * später geändert wurde, trägt zuerst ein LBA-Kennblatt und danach
             * ein EASA-TCDS. Oben steht das TCDS -- es zählt, sobald es eines
             * gibt. Hier stehen die übrigen, damit eine Veröffentlichung, die
             * noch die alte Nummer nennt, das Muster trotzdem findet.
             *
             * Von Hand pflegbar, weil nicht jede Nummer in einem Katalog steht,
             * den dieses System abfragen kann -- und weil ein Verein sein eigenes
             * Muster besser kennt als jede Suche.
             * ─────────────────────────────────────────────────────────────────
             */
            Repeater::make('certificates')
                ->relationship(
                    'certificates',
                    // Die führende Nummer steht schon oben im Formular; sie hier
                    // ein zweites Mal zu zeigen lüde dazu ein, sie an zwei
                    // Stellen verschieden zu ändern.
                    fn ($query) => $query->where('is_primary', false),
                )
                ->label(__('fleet.type.field.other_certificates'))
                ->helperText(__('fleet.type.help.other_certificates'))
                ->schema([
                    TextInput::make('number')
                        ->label(__('fleet.type.field.type_certificate'))
                        ->required()
                        ->maxLength(64)
                        ->helperText(__('fleet.type.help.certificate_notation')),

                    Select::make('authority')
                        ->label(__('fleet.type.field.authority'))
                        ->options([
                            AircraftType::AUTHORITY_EASA => __('fleet.type.authority.easa'),
                            AircraftType::AUTHORITY_FAA => __('fleet.type.authority.faa'),
                            AircraftType::AUTHORITY_LBA => __('fleet.type.authority.lba'),
                            AircraftType::AUTHORITY_OTHER => __('fleet.type.authority.other'),
                        ]),
                ])
                ->columns(2)
                ->defaultItems(0)
                ->addActionLabel(__('fleet.type.action.add_certificate'))
                ->columnSpanFull(),

            TextInput::make('data_sheet_url')
                ->label(__('fleet.type.field.data_sheet_url'))
                ->url()
                ->maxLength(500)
                ->helperText(__('fleet.type.help.link_is_enough'))
                ->columnSpanFull(),

            TextInput::make('directive_overview_url')
                ->label(__('fleet.type.field.overview_url'))
                ->url()
                ->maxLength(500)
                ->helperText(__('fleet.type.help.overview'))
                ->columnSpanFull(),

            Textarea::make('note')
                ->label(__('fleet.type.field.note'))
                ->rows(2)
                ->columnSpanFull(),
        ];
    }

    /**
     * Ask the authorities.
     *
     * Search and pick in one dialog, but two steps: the candidates are fetched
     * live from the designation, and only the chosen one costs a second request
     * for its details and its document.
     */
    private function lookupAction(): Action
    {
        return Action::make('lookup')
            ->label(__('fleet.type.action.lookup'))
            ->icon('heroicon-o-magnifying-glass')
            ->visible(fn (): bool => auth()->user()?->can(Permissions::FLEET_MANAGE) ?? false)
            ->modalDescription(__('fleet.type.help.lookup'))
            ->schema(fn (AircraftType $record): array => [
                TextInput::make('term')
                    ->label(__('fleet.type.field.search_term'))
                    ->default($record->designation)
                    ->required()
                    ->live(debounce: 600),

                Select::make('candidate')
                    ->label(__('fleet.type.field.candidate'))
                    ->options(fn (Get $get): array => self::candidateOptions((string) $get('term')))
                    ->required()
                    ->helperText(__('fleet.type.help.candidates'))
                    ->visible(fn (Get $get): bool => filled($get('term'))),

                Checkbox::make('store')
                    ->label(__('fleet.type.field.store_document'))
                    ->default(true)
                    ->helperText(__('fleet.type.help.store_document')),
            ])
            ->action(function (array $data, AircraftType $record): void {
                $candidate = self::candidateFromKey((string) $data['term'], (string) $data['candidate']);

                if ($candidate === null) {
                    Notification::make()->danger()->title(__('fleet.type.notification.gone'))->send();

                    return;
                }

                try {
                    $result = app(AdoptTypeCertificate::class)->adopt(
                        $record, $candidate, auth()->user(), (bool) ($data['store'] ?? true),
                    );
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }

                $note = Notification::make()
                    ->success()
                    ->title(__('fleet.type.notification.adopted', [
                        'certificate' => $result['type']->type_certificate ?? '',
                    ]));

                // A failed download is reported without undoing the number and
                // the link, which are useful on their own.
                if ($result['problem'] !== null) {
                    $note->warning()->body(__('fleet.type.notification.no_document', [
                        'reason' => $result['problem'],
                    ]));
                }

                $note->send();
            });
    }

    /**
     * The same two helpers, for the component catalogue.
     *
     * Public and reused rather than copied: the searching, the key round-trip
     * and -- most importantly -- the "nothing found because nobody was asked"
     * warning are the same problem on both screens, and a second copy is a
     * second place for that warning to be forgotten.
     *
     * @return array<string, string>
     */
    public static function componentOptions(string $term): array
    {
        return self::candidateOptions($term, CertificateSubject::Component);
    }

    public static function componentFromKey(string $term, string $key): ?TypeCertificateCandidate
    {
        return self::candidateFromKey($term, $key, CertificateSubject::Component);
    }

    /** @return array<string, string> */
    private static function candidateOptions(string $term, CertificateSubject $subject = CertificateSubject::Aircraft): array
    {
        if (trim($term) === '') {
            return [];
        }

        $result = app(AdoptTypeCertificate::class)->searchWithProblems($term, $subject);

        $options = [];

        foreach ($result['candidates'] as $candidate) {
            $options[self::key($candidate)] = $candidate->label();
        }

        /*
         * Nothing found AND something broken is the one combination that must
         * not pass silently.
         *
         * On its own, an empty list is a fair answer -- the type is not listed.
         * Together with a failed authority it is a lie: the list is empty
         * because nobody was asked. Reading the Blaues Buch needs poppler-utils
         * now, and on a system without it EVERY lookup would answer "kein
         * Treffer" and send people off to type Kennblätter by hand.
         *
         * Only when the list is empty, so an authority that is merely slow does
         * not nag somebody who already has their answer.
         */
        if ($options === [] && $result['problems'] !== []) {
            foreach ($result['problems'] as $authority => $reason) {
                Notification::make()
                    ->warning()
                    ->title(__('fleet.type.notification.authority_failed', ['authority' => $authority]))
                    ->body($reason)
                    ->persistent()
                    ->send();
            }
        }

        return $options;
    }

    private static function candidateFromKey(
        string $term,
        string $key,
        CertificateSubject $subject = CertificateSubject::Aircraft,
    ): ?TypeCertificateCandidate {
        foreach (app(AdoptTypeCertificate::class)->search($term, $subject) as $candidate) {
            if (self::key($candidate) === $key) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * A stable key for a candidate across two requests.
     *
     * The dialog searches once to build the list and again to resolve the choice
     * -- Filament posts a value, not an object. Keyed on authority and certificate
     * because those identify the hit; the page URL can differ between renders
     * when the library reorders its categories.
     */
    private static function key(TypeCertificateCandidate $candidate): string
    {
        return $candidate->authority.'|'.$candidate->certificate;
    }
}
