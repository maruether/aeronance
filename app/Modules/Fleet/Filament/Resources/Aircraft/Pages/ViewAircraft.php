<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\Aircraft\Pages;

use App\Models\User;
use App\Modules\Fleet\Actions\CommissionExternalWork;
use App\Modules\Fleet\Actions\FitComponent;
use App\Modules\Fleet\Actions\IssueAirworthinessReview;
use App\Modules\Fleet\Actions\ListInMaintenanceProgramme;
use App\Modules\Fleet\Actions\OnboardAircraft;
use App\Modules\Fleet\Actions\RecordMaintenance;
use App\Modules\Fleet\Enums\CounterKind;
use App\Modules\Fleet\Enums\DocumentType;
use App\Modules\Fleet\Enums\LimitKind;
use App\Modules\Fleet\Enums\ReleasedBy;
use App\Modules\Fleet\Enums\UsageBasis;
use App\Modules\Fleet\Filament\Resources\Aircraft\AircraftResource;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AircraftDocument;
use App\Modules\Fleet\Models\ComponentLimit;
use App\Modules\Fleet\Models\ExternalWorkOrder;
use App\Modules\Fleet\Models\Installation;
use App\Modules\Fleet\Models\PilotOwnerAuthorisation;
use App\Modules\Fleet\Permissions;
use App\Modules\Fleet\Support\ApprovedOrganisations;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * One aircraft, and the acts one performs on it.
 *
 * The actions sit here rather than on separate screens because they all answer
 * questions about THIS aircraft, and a screen one has to find the aircraft on a
 * second time is a screen people avoid.
 */
final class ViewAircraft extends ViewRecord
{
    protected static string $resource = AircraftResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ActionGroup::make([
                $this->recordReviewAction(),
                $this->addDocumentAction(),
                $this->manageLimitsAction(),
                $this->recordMaintenanceAction(),
                $this->listInProgrammeAction(),
                $this->removeListingAction(),
                $this->removeComponentAction(),
                $this->onboardComponentAction(),
            ])->label(__('fleet.actions'))->button(),

            // External work kept in its own group: commissioning, taking back
            // and releasing are three acts at three different times, and mixing
            // them with the everyday ones would bury the release -- the one
            // somebody has to remember.
            ActionGroup::make([
                $this->commissionExternalAction(),
                $this->receiveExternalAction(),
                $this->releaseExternalAction(),
            ])->label(__('fleet.external.singular'))->icon('heroicon-o-building-office'),

            // The two sheets a club hands over. Two views of the same rows --
            // the BWLV keeps them apart only because paper cannot do otherwise.
            ActionGroup::make([
                Action::make('printEquipment')
                    ->label(__('fleet.print.equipment_list'))
                    ->icon('heroicon-o-printer')
                    ->url(fn (): string => route('fleet.equipment-list', ['aircraft' => $this->record]))
                    ->openUrlInNewTab(),

                Action::make('printOperatingTimes')
                    ->label(__('fleet.print.operating_times'))
                    ->icon('heroicon-o-printer')
                    ->url(fn (): string => route('fleet.operating-times', ['aircraft' => $this->record]))
                    ->openUrlInNewTab(),
            ])->label(__('fleet.print.label'))->icon('heroicon-o-printer'),
        ];
    }

    /**
     * Recording an airworthiness review.
     */
    private function recordReviewAction(): Action
    {
        return Action::make('review')
            ->label(__('fleet.review.singular'))
            ->icon('heroicon-o-document-check')
            ->visible(fn (): bool => auth()->user()?->can(Permissions::REVIEWS_RECORD) ?? false)
            ->schema([
                TextInput::make('certificate_reference')
                    ->label(__('fleet.review.field.certificate_reference'))
                    ->maxLength(128),

                DatePicker::make('issued_at')
                    ->label(__('fleet.review.field.issued_at'))
                    ->default(now())
                    ->required()
                    ->live(),

                // Shown, not asked. The rule is known -- within 90 days of the
                // old expiry the old date carries, otherwise 364 days from
                // issue -- and anything known that a person types is something
                // a person can get wrong once a year in the direction of flying
                // longer. It says WHY, because a date that appears without
                // explanation invites somebody to correct it.
                Placeholder::make('valid_until_preview')
                    ->label(__('fleet.review.field.valid_until'))
                    ->content(function (callable $get): string {
                        $action = app(IssueAirworthinessReview::class);
                        $issued = Carbon::parse($get('issued_at') ?: now());

                        return sprintf(
                            '%s — %s',
                            $action->validUntil($this->record, $issued)->format('d.m.Y'),
                            $action->carriesOldDate($this->record, $issued)
                                ? __('fleet.review.help.carries')
                                : __('fleet.review.help.full_term'),
                        );
                    }),

                TextInput::make('issued_by_name')
                    ->label(__('fleet.review.field.issued_by_name'))
                    ->maxLength(160),

                TextInput::make('issued_by_approval')
                    ->label(__('fleet.review.field.issued_by_approval'))
                    ->maxLength(64)
                    ->placeholder('DE.CAO.0456'),
            ])
            ->action(function (array $data): void {
                $review = app(IssueAirworthinessReview::class)->handle(
                    $this->record,
                    (string) $data['issued_at'],
                    [
                        'certificate_reference' => $data['certificate_reference'] ?? null,
                        'issued_by_name' => $data['issued_by_name'] ?? null,
                        'issued_by_approval' => $data['issued_by_approval'] ?? null,
                    ],
                    auth()->user(),
                );

                Notification::make()
                    ->success()
                    ->title(__('fleet.review.notification.issued', [
                        'date' => $review->valid_until->format('d.m.Y'),
                    ]))
                    ->send();
            });
    }

    /**
     * Naming somebody in the maintenance programme.
     *
     * Its own permission, because this list decides who may release work under
     * pilot-owner rules -- a permission that grants permissions.
     */
    private function listInProgrammeAction(): Action
    {
        return Action::make('listInProgramme')
            ->label(__('fleet.pilot_owner.singular'))
            ->icon('heroicon-o-identification')
            ->visible(fn (): bool => auth()->user()?->can(Permissions::PROGRAMME_MANAGE) ?? false)
            ->modalDescription(__('fleet.pilot_owner.help.source'))
            ->schema([
                Select::make('user_id')
                    ->label(__('fleet.pilot_owner.field.person'))
                    ->options(fn (): array => User::where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),

                DatePicker::make('valid_until')
                    ->label(__('fleet.pilot_owner.field.valid_until'))
                    ->helperText(__('fleet.pilot_owner.help.open_ended')),

                Textarea::make('note')
                    ->label(__('fleet.aircraft.field.note'))
                    ->rows(2),
            ])
            ->action(function (array $data): void {
                $person = User::find($data['user_id']);

                if ($person === null) {
                    return;
                }

                app(ListInMaintenanceProgramme::class)->add(
                    $this->record,
                    $person,
                    $data['valid_until'] ?? null,
                    $data['note'] ?? null,
                );

                Notification::make()
                    ->success()
                    ->title(__('fleet.pilot_owner.notification.listed', ['name' => $person->name]))
                    ->body(__('fleet.pilot_owner.help.source'))
                    ->send();
            });
    }

    private function commissionExternalAction(): Action
    {
        return Action::make('commissionExternal')
            ->label(__('fleet.external.commission'))
            ->icon('heroicon-o-paper-airplane')
            ->visible(fn (): bool => auth()->user()?->can(Permissions::EXTERNAL_WORK_MANAGE) ?? false)
            ->schema([
                /*
                 * Nur sichtbar, wenn es ueberhaupt ein Verzeichnis gibt -- das
                 * haengt am Lagermodul, und die Flotte steht allein. Ohne
                 * Lager bleibt es beim Freitext wie bisher.
                 */
                Select::make('organisation_id')
                    ->label(__('fleet.external.field.organisation'))
                    ->options(fn (): array => ApprovedOrganisations::options())
                    ->searchable()
                    ->live()
                    ->helperText(__('fleet.external.help.organisation'))
                    ->visible(fn (): bool => ApprovedOrganisations::available()),

                TextInput::make('shop_name')
                    ->label(__('fleet.external.field.shop_name'))
                    ->required(fn (Get $get): bool => blank($get('organisation_id')))
                    ->visible(fn (Get $get): bool => blank($get('organisation_id')))
                    ->maxLength(160),

                TextInput::make('shop_approval')
                    ->label(__('fleet.external.field.shop_approval'))
                    ->maxLength(64)
                    ->visible(fn (Get $get): bool => blank($get('organisation_id')))
                    ->placeholder('DE.145.0123'),

                TextInput::make('order_reference')
                    ->label(__('fleet.external.field.order_reference'))
                    ->maxLength(128),

                DatePicker::make('sent_at')
                    ->label(__('fleet.external.field.sent_at'))
                    ->default(now())
                    ->required(),

                DatePicker::make('expected_back_at')
                    ->label(__('fleet.external.field.expected_back_at')),

                Textarea::make('scope')
                    ->label(__('fleet.external.field.scope'))
                    ->required()
                    ->rows(2)
                    ->columnSpanFull(),
            ])
            ->action(function (array $data): void {
                try {
                    /*
                     * BENANNT und nicht der Reihe nach. Ein neuer Parameter in
                     * der Mitte hat denselben Aufruf im Lager schon einmal
                     * still verschoben -- Namen halten das aus.
                     */
                    $order = app(CommissionExternalWork::class)->commission(
                        aircraft: $this->record,
                        shopName: (string) ($data['shop_name'] ?? ''),
                        scope: (string) $data['scope'],
                        user: auth()->user(),
                        shopApproval: $data['shop_approval'] ?? null,
                        organisationId: isset($data['organisation_id']) ? (int) $data['organisation_id'] : null,
                        orderReference: $data['order_reference'] ?? null,
                        sentAt: $data['sent_at'] ?? null,
                        expectedBackAt: $data['expected_back_at'] ?? null,
                    );
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('fleet.external.notification.commissioned', ['shop' => $order->shop_name]))
                    ->send();
            });
    }

    /**
     * The aircraft is back -- and deliberately not yet released.
     */
    private function receiveExternalAction(): Action
    {
        return Action::make('receiveExternal')
            ->label(__('fleet.external.receive'))
            ->icon('heroicon-o-arrow-down-tray')
            ->visible(fn (Aircraft $record): bool => $record->externalWorkOrders()->open()->exists()
                && (auth()->user()?->can(Permissions::EXTERNAL_WORK_MANAGE) ?? false))
            ->modalDescription(__('fleet.external.help.two_steps'))
            ->schema(fn (Aircraft $record): array => [
                Select::make('order_id')
                    ->label(__('fleet.external.singular'))
                    ->options($record->externalWorkOrders()
                        ->where('state', 'commissioned')
                        ->get()
                        ->mapWithKeys(fn (ExternalWorkOrder $o): array => [$o->id => $o->label()])
                        ->all())
                    ->required(),

                DatePicker::make('returned_at')
                    ->label(__('fleet.external.field.returned_at'))
                    ->default(now())
                    ->required(),

                TextInput::make('report_reference')
                    ->label(__('fleet.external.field.report_reference'))
                    ->maxLength(255),
            ])
            ->action(function (array $data): void {
                $order = ExternalWorkOrder::find($data['order_id'] ?? null);

                if ($order === null) {
                    return;
                }

                try {
                    app(CommissionExternalWork::class)->receive(
                        $order,
                        auth()->user(),
                        $data['report_reference'] ?? null,
                        $data['returned_at'] ?? null,
                    );
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()
                    ->warning()
                    ->title(__('fleet.external.notification.returned'))
                    ->body(__('fleet.external.help.two_steps'))
                    ->persistent()
                    ->send();
            });
    }

    /**
     * Signing it off -- theirs or ours, and the form says what the difference is.
     */
    private function releaseExternalAction(): Action
    {
        return Action::make('releaseExternal')
            ->label(__('fleet.external.release'))
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (Aircraft $record): bool => $record->externalWorkOrders()->awaitingRelease()->exists()
                && (auth()->user()?->can(Permissions::EXTERNAL_WORK_MANAGE) ?? false))
            ->modalDescription(__('fleet.external.help.who_releases'))
            ->schema(fn (Aircraft $record): array => [
                Select::make('order_id')
                    ->label(__('fleet.external.singular'))
                    ->options($record->externalWorkOrders()
                        ->awaitingRelease()
                        ->get()
                        ->mapWithKeys(fn (ExternalWorkOrder $o): array => [$o->id => $o->label()])
                        ->all())
                    ->required(),

                Select::make('released_by')
                    ->label(__('fleet.external.release'))
                    ->options(collect(ReleasedBy::cases())
                        ->mapWithKeys(fn (ReleasedBy $r): array => [$r->value => $r->label()])
                        ->all())
                    ->default(ReleasedBy::External->value)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live(),

                TextInput::make('release_reference')
                    ->label(__('fleet.external.field.release_reference'))
                    ->maxLength(128),

                TextInput::make('external_signatory')
                    ->label(__('fleet.external.field.signatory'))
                    ->required(fn (callable $get): bool => $get('released_by') === ReleasedBy::External->value)
                    ->visible(fn (callable $get): bool => $get('released_by') === ReleasedBy::External->value)
                    ->maxLength(160),

                TextInput::make('external_approval')
                    ->label(__('fleet.external.field.shop_approval'))
                    ->visible(fn (callable $get): bool => $get('released_by') === ReleasedBy::External->value)
                    ->maxLength(64),

                DatePicker::make('released_at')
                    ->label(__('fleet.external.field.returned_at'))
                    ->default(now())
                    ->required(),
            ])
            ->action(function (array $data): void {
                $order = ExternalWorkOrder::find($data['order_id'] ?? null);

                if ($order === null) {
                    return;
                }

                $by = ReleasedBy::from($data['released_by']);

                try {
                    app(CommissionExternalWork::class)->release(
                        $order,
                        $by,
                        auth()->user(),
                        $data['release_reference'] ?? null,
                        $data['external_signatory'] ?? null,
                        $data['external_approval'] ?? null,
                        $data['released_at'] ?? null,
                    );
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('fleet.external.notification.refused'))
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title($by === ReleasedBy::Internal
                        ? __('fleet.external.notification.released_internal')
                        : __('fleet.external.notification.released_external'))
                    ->send();
            });
    }

    /**
     * Writing down a component the aircraft arrived with.
     *
     * Not migration -- the correction, and it is why this exists at all: an
     * aircraft joining the operation always has components in it, whether it is
     * new from the factory or sixty years old and merely new to this shop. That
     * happens for ever, not once.
     *
     * What makes it safe is the source document, which is required. Without it
     * this would be a way to type any component into any aircraft, arriving
     * through a door marked "onboarding" -- exactly what refusing hand entry was
     * meant to prevent.
     */
    private function onboardComponentAction(): Action
    {
        return Action::make('onboardComponent')
            ->label(__('fleet.onboarding.component'))
            ->icon('heroicon-o-inbox-arrow-down')
            ->visible(fn (): bool => auth()->user()?->can(Permissions::COMPONENTS_MANAGE) ?? false)
            ->modalDescription(__('fleet.onboarding.help.what'))
            ->schema(fn (Aircraft $record): array => [
                TextInput::make('part_name')
                    ->label(__('fleet.installation.field.part_name'))
                    ->required()
                    ->maxLength(160),

                TextInput::make('serial_number')
                    ->label(__('fleet.installation.field.serial_number'))
                    ->maxLength(128),

                TextInput::make('transcribed_from')
                    ->label(__('fleet.onboarding.field.transcribed_from'))
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Betriebszeitenübersicht des Vorbetriebs vom 12.03.2019')
                    ->helperText(__('fleet.onboarding.help.transcribed_from'))
                    ->columnSpanFull(),

                DatePicker::make('installed_at')
                    ->label(__('fleet.onboarding.field.installed_at'))
                    ->required()
                    ->maxDate(now())
                    ->helperText(__('fleet.onboarding.help.installed_at')),

                TextInput::make('document_reference')
                    ->label(__('fleet.installation.field.document'))
                    ->maxLength(128),

                // The counter this aircraft is actually measured in, so the two
                // figures land where the limits will read them.
                TextInput::make('since_new')
                    ->label(__('fleet.onboarding.field.since_new'))
                    ->numeric()
                    ->suffix(fn (): string => $this->primaryCounter($record)->unit()),

                TextInput::make('since_overhaul')
                    ->label(__('fleet.onboarding.field.since_overhaul'))
                    ->numeric()
                    ->suffix(fn (): string => $this->primaryCounter($record)->unit())
                    ->helperText(__('fleet.overhaul.help.tsn_runs_on')),

                Checkbox::make('is_minimum_equipment')
                    ->label(__('fleet.equipment.minimum'))
                    ->helperText(__('fleet.equipment.help.minimum')),
            ])
            ->action(function (array $data): void {
                $counter = $this->primaryCounter($this->record)->value;

                try {
                    app(OnboardAircraft::class)->recordFittedComponent(
                        $this->record,
                        (string) $data['part_name'],
                        (string) $data['transcribed_from'],
                        auth()->user(),
                        attributes: [
                            'serial_number' => $data['serial_number'] ?? null,
                            'document_reference' => $data['document_reference'] ?? null,
                            'is_minimum_equipment' => (bool) ($data['is_minimum_equipment'] ?? false),
                        ],
                        sinceNew: filled($data['since_new'] ?? null)
                            ? [$counter => (float) $data['since_new']]
                            : [],
                        sinceOverhaul: filled($data['since_overhaul'] ?? null)
                            ? [$counter => (float) $data['since_overhaul']]
                            : null,
                        installedAt: $data['installed_at'] ?? null,
                    );
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('fleet.onboarding.recorded'))
                    ->body(__('fleet.onboarding.help.marked'))
                    ->persistent()
                    ->send();
            });
    }

    /**
     * The counter a component's life is most likely measured in here.
     */
    private function primaryCounter(Aircraft $aircraft): CounterKind
    {
        return $aircraft->keeps(CounterKind::EngineHours)
            ? CounterKind::EngineHours
            : CounterKind::FlightHours;
    }

    /**
     * Attaching a paper.
     *
     * The expiry is optional and stays optional: some aircraft owe a weighing
     * every four years, others only when something changes. Empty means it does
     * not expire, not that somebody forgot.
     */
    private function addDocumentAction(): Action
    {
        return Action::make('addDocument')
            ->label(__('fleet.document.singular'))
            ->icon('heroicon-o-document-text')
            ->visible(fn (): bool => auth()->user()?->can(Permissions::PROGRAMME_MANAGE) ?? false)
            ->schema([
                Select::make('type')
                    ->label(__('fleet.document.singular'))
                    ->options(collect(DocumentType::cases())
                        ->mapWithKeys(fn (DocumentType $t): array => [$t->value => $t->label()])
                        ->all())
                    ->default(DocumentType::Amp->value)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live()
                    ->helperText(fn (callable $get): ?string => $get('type') === DocumentType::Amp->value
                        ? __('fleet.document.help.amp')
                        : null),

                TextInput::make('title')
                    ->label(__('fleet.document.field.title'))
                    ->required()
                    ->maxLength(160),

                TextInput::make('reference')
                    ->label(__('fleet.document.field.reference'))
                    ->maxLength(128),

                DatePicker::make('issued_at')->label(__('fleet.document.field.issued_at')),

                DatePicker::make('valid_until')
                    ->label(__('fleet.document.field.valid_until'))
                    ->helperText(__('fleet.document.help.valid_until')),

                TextInput::make('issued_by')
                    ->label(__('fleet.document.field.issued_by'))
                    ->maxLength(160),

                /*
                 * Die Datei selbst. Feldtest: "Dokumente können nicht
                 * hochgeladen werden" -- der Dialog nahm nur Metadaten an.
                 * storeFiles(false), weil der Datensatz erst in action()
                 * entsteht: Die Datei kommt als Upload-Objekt mit und wird
                 * dort ueber die Medienablage angehaengt (private Disk).
                 */
                FileUpload::make('file')
                    ->label(__('fleet.document.field.file'))
                    ->storeFiles(false)
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                    ->maxSize((int) config('aeronance.documents.max_size_mb', 20) * 1024)
                    ->helperText(__('fleet.document.help.file')),
            ])
            ->action(function (array $data): void {
                $dokument = AircraftDocument::create(Arr::except($data, ['file']) + [
                    'aircraft_id' => $this->record->id,
                    'user_id' => auth()->id(),
                ]);

                $datei = $data['file'] ?? null;

                if ($datei instanceof TemporaryUploadedFile) {
                    // Erzeugter Name: ein hochgeladener Dateiname ist
                    // Fremdeingabe (Haertungs-Leitplanke). Die Endung kommt
                    // aus dem geprueften MIME-Typ, nicht vom Client.
                    $dokument->addMedia($datei->getRealPath())
                        ->usingFileName(Str::uuid().'.'.($datei->guessExtension() ?: 'pdf'))
                        ->toMediaCollection(AircraftDocument::FILE);
                }

                Notification::make()->success()->title(__('fleet.document.singular'))->send();
            });
    }

    /**
     * Entering a component's limits.
     *
     * The biggest hole in the module until now: the whole limit model existed,
     * tested, and nobody could reach it. A Tost tow release runs "2 Jahre oder
     * 500 Starts" -- two rows here, and whichever arrives first is what falls
     * due.
     */
    private function manageLimitsAction(): Action
    {
        return Action::make('addLimit')
            ->label(__('fleet.limits.add'))
            ->icon('heroicon-o-arrow-path-rounded-square')
            ->visible(fn (Aircraft $record): bool => $record->fittedComponents()->isNotEmpty()
                && (auth()->user()?->can(Permissions::COMPONENTS_MANAGE) ?? false))
            ->modalDescription(__('fleet.limits.help.multiple'))
            ->schema(fn (Aircraft $record): array => [
                Select::make('installation_id')
                    ->label(__('fleet.installation.singular'))
                    ->options($record->fittedComponents()
                        ->mapWithKeys(fn (Installation $i): array => [$i->id => $i->label()])
                        ->all())
                    ->searchable()
                    ->required(),

                Select::make('kind')
                    ->label(__('fleet.limits.singular'))
                    ->options(collect(LimitKind::cases())
                        ->mapWithKeys(fn (LimitKind $k): array => [$k->value => $k->label()])
                        ->all())
                    ->required()
                    ->live(),

                Select::make('basis')
                    ->label(__('fleet.basis.since_new').' / '.__('fleet.basis.since_overhaul'))
                    ->options(collect(UsageBasis::cases())
                        ->mapWithKeys(fn (UsageBasis $b): array => [$b->value => $b->label()])
                        ->all())
                    ->default(UsageBasis::SinceOverhaul->value)
                    ->selectablePlaceholder(false)
                    ->visible(fn (callable $get): bool => LimitKind::tryFrom((string) $get('kind'))?->isCalendar() === false),

                TextInput::make('value')
                    ->label(__('fleet.limits.singular'))
                    ->numeric()
                    ->required(fn (callable $get): bool => $get('kind') !== LimitKind::CalendarDate->value)
                    ->visible(fn (callable $get): bool => $get('kind') !== LimitKind::CalendarDate->value),

                DatePicker::make('due_on')
                    ->label(__('fleet.due.at'))
                    ->required(fn (callable $get): bool => $get('kind') === LimitKind::CalendarDate->value)
                    ->visible(fn (callable $get): bool => $get('kind') === LimitKind::CalendarDate->value),

                TextInput::make('tolerance_percent')
                    ->label(__('fleet.tolerance.label').' %')
                    ->numeric()
                    ->default(config('aeronance.fleet.default_tolerance_percent'))
                    ->helperText(__('fleet.tolerance.help.both')),

                TextInput::make('tolerance_absolute')
                    ->label(__('fleet.tolerance.label').' '.__('fleet.tolerance.absolute'))
                    ->numeric()
                    ->helperText(__('fleet.tolerance.help.none')),

                TextInput::make('source')
                    ->label(__('fleet.limits.source'))
                    ->maxLength(160)
                    ->placeholder('TBO Herstellerangabe, LTA 2024-0123, AMP'),
            ])
            ->action(function (array $data): void {
                ComponentLimit::create($data);

                Notification::make()
                    ->success()
                    ->title(__('fleet.limits.added'))
                    ->body(__('fleet.tolerance.help.anchor'))
                    ->send();
            });
    }

    /**
     * Ticking a limit's work off.
     *
     * Vorgabe: with no work order module, this is simply ticked. When that module
     * arrives the path becomes a proper one -- a pending item raises a work
     * card, and signing the card completes the item -- so this stays a thin
     * wrapper over the action rather than growing a workflow of its own.
     *
     * The asymmetric anchor rule lives in RecordMaintenance and is stated in the
     * confirmation, because "done late" and "done early" move the next due date
     * differently and nobody expects that from a tick box.
     */
    private function recordMaintenanceAction(): Action
    {
        return Action::make('recordMaintenance')
            ->label(__('fleet.limits.record_done'))
            ->icon('heroicon-o-check')
            ->visible(fn (Aircraft $record): bool => $this->openLimits($record) !== []
                && (auth()->user()?->can(Permissions::COMPONENTS_MANAGE) ?? false))
            ->modalDescription(__('fleet.tolerance.help.anchor'))
            ->schema(fn (Aircraft $record): array => [
                Select::make('limit_id')
                    ->label(__('fleet.limits.singular'))
                    ->options($this->openLimits($record))
                    ->searchable()
                    ->required(),

                DatePicker::make('done_at')
                    ->label(__('fleet.limits.done_at'))
                    ->default(now())
                    ->required(),
            ])
            ->action(function (array $data): void {
                $limit = ComponentLimit::find($data['limit_id'] ?? null);

                if ($limit === null) {
                    return;
                }

                try {
                    app(RecordMaintenance::class)->handle($limit, $data['done_at'] ?? null);
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('fleet.limits.recorded'))
                    ->send();
            });
    }

    /**
     * @return array<int, string>
     */
    private function openLimits(Aircraft $record): array
    {
        $options = [];

        foreach ($record->fittedComponents() as $installation) {
            foreach ($installation->limits as $limit) {
                $options[$limit->id] = sprintf('%s — %s', $installation->label(), $limit->describe());
            }
        }

        return $options;
    }

    /**
     * Taking somebody off the programme.
     *
     * The other half of naming them, and it was missing: one could add a person
     * and never remove them without going into the database.
     */
    private function removeListingAction(): Action
    {
        return Action::make('removeListing')
            ->label(__('fleet.pilot_owner.remove'))
            ->icon('heroicon-o-user-minus')
            ->color('warning')
            ->visible(fn (Aircraft $record): bool => $record->pilotOwnerAuthorisations()->exists()
                && (auth()->user()?->can(Permissions::PROGRAMME_MANAGE) ?? false))
            ->modalDescription(__('fleet.pilot_owner.help.ends_not_deletes'))
            ->schema(fn (Aircraft $record): array => [
                Select::make('user_id')
                    ->label(__('fleet.pilot_owner.field.person'))
                    ->options($record->pilotOwnerAuthorisations()
                        ->with('user')
                        ->get()
                        ->mapWithKeys(fn (PilotOwnerAuthorisation $a): array => [
                            $a->user_id => $a->listed_name,
                        ])
                        ->all())
                    ->required(),
            ])
            ->action(function (array $data): void {
                $person = User::find($data['user_id'] ?? null);

                if ($person === null) {
                    return;
                }

                app(ListInMaintenanceProgramme::class)->remove($this->record, $person);

                Notification::make()
                    ->success()
                    ->title(__('fleet.pilot_owner.removed', ['name' => $person->name]))
                    ->send();
            });
    }

    /**
     * Taking a part off.
     *
     * The serviceable tick is the same determination as everywhere else, and it
     * travels: if the warehouse is installed, the part lands on the shelf
     * through its own removal action with that determination attached.
     */
    private function removeComponentAction(): Action
    {
        return Action::make('removeComponent')
            ->label(__('fleet.installation.remove'))
            ->icon('heroicon-o-arrow-down-on-square')
            ->color('warning')
            ->visible(fn (Aircraft $record): bool => $record->fittedComponents()->isNotEmpty()
                && (auth()->user()?->can(Permissions::COMPONENTS_MANAGE) ?? false))
            ->schema(fn (Aircraft $record): array => [
                Select::make('installation_id')
                    ->label(__('fleet.installation.singular'))
                    ->options($record->fittedComponents()
                        ->mapWithKeys(fn (Installation $i): array => [$i->id => $i->label()])
                        ->all())
                    ->searchable()
                    ->required(),

                DatePicker::make('removed_at')
                    ->label(__('fleet.installation.field.removed_at'))
                    ->default(now())
                    ->required(),

                Textarea::make('reason')
                    ->label(__('fleet.installation.field.removal_reason'))
                    ->required()
                    ->rows(2),

                Checkbox::make('determined_serviceable')
                    ->label(__('warehouse.removal.field.serviceable'))
                    ->helperText(__('fleet.installation.help.serviceable')),
            ])
            ->action(function (array $data): void {
                $installation = Installation::find($data['installation_id'] ?? null);

                if ($installation === null) {
                    return;
                }

                try {
                    app(FitComponent::class)->remove(
                        $installation,
                        auth()->user(),
                        (string) $data['reason'],
                        $data['removed_at'] ?? null,
                        (bool) ($data['determined_serviceable'] ?? false),
                    );
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('fleet.installation.notification.refused'))
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('fleet.installation.notification.removed'))
                    ->body(__('fleet.installation.help.lands_in_store'))
                    ->send();
            });
    }
}
