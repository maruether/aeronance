<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\Aircraft\Schemas;

use App\Modules\Fleet\Airworthiness\AirworthinessCheck;
use App\Modules\Fleet\Airworthiness\OpenItem;
use App\Modules\Fleet\Enums\CounterKind;
use App\Modules\Fleet\Enums\UsageBasis;
use App\Modules\Fleet\Filament\Resources\AircraftTypes\AircraftTypeResource;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AircraftDocument;
use App\Modules\Fleet\Models\ExternalWorkOrder;
use App\Modules\Fleet\Models\Installation;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The aircraft's life record, on one page.
 *
 * TSN and TSO are shown side by side even though, as the brief put it, the
 * distinction is "für die Wartung in der Regel irrelevant, für manche
 * papiervorgänge aber wichtig". Which is exactly why they belong on the screen
 * one reads when filling in a paper: the moment they matter is the moment
 * somebody is looking something up.
 */
final class AircraftInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('fleet.aircraft.singular'))
                ->schema([
                    TextEntry::make('registration')->label(__('fleet.aircraft.field.registration')),
                    TextEntry::make('model')->label(__('fleet.aircraft.field.model')),

                    /*
                     * The type and its Kennblatt, where one is assigned. Shown
                     * next to the model rather than instead of it: the two can
                     * legitimately read differently, and hiding that would make a
                     * mismatch invisible.
                     */
                    TextEntry::make('aircraftType.designation')
                        ->label(__('fleet.type.singular'))
                        ->state(fn ($record): ?string => $record->aircraftType?->label())
                        ->url(fn ($record): ?string => $record->aircraft_type_id !== null
                            ? AircraftTypeResource::getUrl('index')
                            : null)
                        ->placeholder(__('fleet.aircraft.no_type'))
                        ->badge()
                        ->color(fn ($record): string => $record->aircraftType?->isDocumented() ?? false
                            ? 'success'
                            : 'warning'),
                    TextEntry::make('manufacturer')->label(__('fleet.aircraft.field.manufacturer'))->placeholder('—'),
                    TextEntry::make('serial_number')->label(__('fleet.aircraft.field.serial_number'))->placeholder('—'),
                    TextEntry::make('year_built')->label(__('fleet.aircraft.field.year_built'))->placeholder('—'),
                    TextEntry::make('holder.name')->label(__('fleet.aircraft.field.holder'))->placeholder('—'),
                ])
                ->columns(3),

            Section::make(__('fleet.reading.plural'))
                ->schema([
                    TextEntry::make('counters')
                        ->hiddenLabel()
                        ->state(fn (Aircraft $record): string => collect($record->counters())
                            ->map(fn (CounterKind $kind): string => sprintf(
                                '%s: %s %s',
                                $kind->label(),
                                number_format(
                                    $record->currentValue($kind),
                                    $kind->isWhole() ? 0 : 2,
                                    ',',
                                    '.',
                                ),
                                $kind->unit(),
                            ))
                            ->implode(' · ')),
                ]),

            /*
             * "Hier ist noch was offen" -- at the top, because it is what
             * somebody opens this page to find out.
             *
             * Never a verdict. An empty list means nothing was found, not that
             * the aircraft is fit, and the note says so rather than leaving the
             * green tick to imply otherwise.
             */
            Section::make(__('fleet.airworthiness.title'))
                ->description(__('fleet.airworthiness.not_a_verdict'))
                ->schema([
                    TextEntry::make('open_items')
                        ->hiddenLabel()
                        ->state(fn (Aircraft $record): string => self::openItems($record))
                        ->badge()
                        ->color(fn (Aircraft $record): string => app(AirworthinessCheck::class)
                            ->hasOpenItems($record) ? 'danger' : 'success'),
                ]),

            Section::make(__('fleet.review.plural'))
                ->schema([
                    TextEntry::make('review')
                        ->hiddenLabel()
                        ->state(fn (Aircraft $record): string => $record->currentReview() === null
                            ? __('fleet.due.no_review')
                            : sprintf(
                                '%s — %s %s',
                                $record->currentReview()->certificate_reference ?? '—',
                                __('fleet.review.field.valid_until'),
                                $record->currentReview()->valid_until->format('d.m.Y'),
                            ))
                        ->badge()
                        ->color(fn (Aircraft $record): string => match (true) {
                            $record->currentReview() === null => 'danger',
                            ! $record->currentReview()->isValid() => 'danger',
                            $record->currentReview()->daysRemaining() <= 60 => 'warning',
                            default => 'success',
                        }),
                ]),

            /*
             * Papers and external jobs, which until now could be entered and
             * then never seen again -- an action with no matching display is a
             * write-only field, and nobody trusts a record they cannot read
             * back.
             */
            Section::make(__('fleet.document.plural'))
                ->schema([
                    RepeatableEntry::make('documents')
                        ->hiddenLabel()
                        ->state(fn (Aircraft $record): array => $record->documents->all())
                        ->schema([
                            TextEntry::make('type')
                                ->label(__('fleet.document.singular'))
                                ->state(fn (AircraftDocument $d): string => $d->type->label()),

                            TextEntry::make('title')
                                ->label(__('fleet.document.field.title')),

                            TextEntry::make('reference')
                                ->label(__('fleet.document.field.reference'))
                                ->placeholder('—'),

                            // "Does not expire" is a statement, not a blank.
                            TextEntry::make('valid_until')
                                ->label(__('fleet.document.field.valid_until'))
                                ->state(fn (AircraftDocument $d): string => $d->expires()
                                    ? $d->valid_until->format('d.m.Y')
                                    : __('fleet.document.no_expiry'))
                                ->badge()
                                ->color(fn (AircraftDocument $d): string => match (true) {
                                    ! $d->expires() => 'gray',
                                    ! $d->isValid() => 'danger',
                                    $d->daysRemaining() <= 60 => 'warning',
                                    default => 'success',
                                }),
                        ])
                        ->columns(4),
                ])
                ->visible(fn (Aircraft $record): bool => $record->documents->isNotEmpty()),

            Section::make(__('fleet.external.plural'))
                ->schema([
                    RepeatableEntry::make('externalWorkOrders')
                        ->hiddenLabel()
                        ->state(fn (Aircraft $record): array => $record->externalWorkOrders->all())
                        ->schema([
                            TextEntry::make('shop_name')
                                ->label(__('fleet.external.field.shop_name'))
                                ->state(fn (ExternalWorkOrder $o): string => $o->shop_approval !== null
                                    ? sprintf('%s (%s)', $o->shop_name, $o->shop_approval)
                                    : $o->shop_name),

                            TextEntry::make('scope')
                                ->label(__('fleet.external.field.scope'))
                                ->limit(60),

                            TextEntry::make('sent_at')
                                ->label(__('fleet.external.field.sent_at'))
                                ->state(fn (ExternalWorkOrder $o): string => $o->sent_at->format('d.m.Y')),

                            TextEntry::make('state')
                                ->label(__('fleet.external.singular'))
                                ->state(fn (ExternalWorkOrder $o): string => $o->state->label())
                                ->badge()
                                ->color(fn (ExternalWorkOrder $o): string => match (true) {
                                    $o->isAwaitingRelease() => 'danger',
                                    $o->isReleased() => 'success',
                                    $o->isOverdue() => 'warning',
                                    default => 'info',
                                }),

                            // Who signed, which is the question the record
                            // exists to answer.
                            TextEntry::make('released_by')
                                ->label(__('fleet.external.release'))
                                ->placeholder('—')
                                ->state(fn (ExternalWorkOrder $o): ?string => $o->released_by === null
                                    ? null
                                    : sprintf(
                                        '%s — %s',
                                        $o->released_by->label(),
                                        $o->released_by_name ?? '?',
                                    )),
                        ])
                        ->columns(5),
                ])
                ->visible(fn (Aircraft $record): bool => $record->externalWorkOrders->isNotEmpty()),

            Section::make(__('fleet.installation.plural'))
                ->description(__('fleet.installation.help.scope'))
                ->schema([
                    RepeatableEntry::make('fittedComponents')
                        ->hiddenLabel()
                        ->state(fn (Aircraft $record): array => $record->fittedComponents()->all())
                        ->schema([
                            TextEntry::make('part_name')
                                ->label(__('fleet.installation.field.part_name'))
                                ->state(fn (Installation $r): string => $r->label()),

                            TextEntry::make('document')
                                ->label(__('fleet.installation.field.document'))
                                ->placeholder('—')
                                // A transcribed line says so here rather than
                                // looking identical to one we witnessed. Both
                                // are legitimate; only one is our own evidence.
                                ->state(fn (Installation $r): ?string => $r->wasTranscribed()
                                    ? trim(($r->document_reference ?? '').' ('.__('fleet.installation.transcribed').')')
                                    : $r->document_reference)
                                ->tooltip(fn (Installation $r): ?string => $r->wasTranscribed()
                                    ? __('fleet.installation.transcribed_from').': '.$r->transcribed_from
                                    : null),

                            TextEntry::make('installed_at')
                                ->label(__('fleet.installation.field.installed_at'))
                                ->state(fn (Installation $r): string => $r->installed_at->format('d.m.Y')),

                            // Both, side by side. The moment they differ is the
                            // moment somebody is filling in a form.
                            TextEntry::make('times')
                                ->label('TSN / TSO')
                                ->state(fn (Installation $r): string => self::times($r)),

                            TextEntry::make('due_in')
                                ->label(__('fleet.due.in'))
                                ->state(fn (Installation $r): string => self::dueIn($r))
                                ->badge()
                                ->color(fn (Installation $r): string => self::colourOf($r)),

                            // Both, side by side, because they answer different
                            // questions. "In 20 Starts" is what is left; "bei
                            // 1480" is what the instrument in the hangar has to
                            // read. Somebody standing at the aircraft wants the
                            // second one and should not have to add up to get it.
                            TextEntry::make('due_at')
                                ->label(__('fleet.due.at'))
                                ->state(fn (Installation $r): string => self::dueAt($r)),
                        ])
                        ->columns(6),
                ]),
        ]);
    }

    /**
     * Everything worth looking at before this aircraft flies.
     */
    private static function openItems(Aircraft $aircraft): string
    {
        $items = app(AirworthinessCheck::class)->openItemsFor($aircraft);

        if ($items === []) {
            return __('fleet.airworthiness.nothing_found');
        }

        return collect($items)
            ->map(fn (OpenItem $item): string => sprintf(
                '%s: %s%s',
                $item->what,
                $item->detail,
                $item->blocking ? '' : ' ('.__('fleet.airworthiness.warning').')',
            ))
            ->implode(' · ');
    }

    /**
     * The two figures, or a dash where the aircraft keeps no counter to answer
     * with -- which is honest, and better than a zero that reads as "new".
     */
    private static function times(Installation $installation): string
    {
        $kind = $installation->aircraft?->keeps(CounterKind::EngineHours)
            ? CounterKind::EngineHours
            : CounterKind::FlightHours;

        $sinceNew = $installation->usage($kind, UsageBasis::SinceNew);
        $sinceOverhaul = $installation->usage($kind, UsageBasis::SinceOverhaul);

        if ($sinceNew === null) {
            return '—';
        }

        return sprintf(
            '%s / %s h',
            number_format($sinceNew, 1, ',', '.'),
            $sinceOverhaul === null ? '—' : number_format($sinceOverhaul, 1, ',', '.'),
        );
    }

    /**
     * What is left -- "in 20 Starts", "in 3 Monaten".
     */
    private static function dueIn(Installation $installation): string
    {
        $due = $installation->nextDue();

        if ($due === null) {
            return '—';
        }

        $limit = $due['limit'];

        if ($limit->kind->isCalendar()) {
            $days = $limit->remainingDays();

            return $days === null ? $limit->describe() : sprintf('%d %s', $days, __('fleet.due.days'));
        }

        $remaining = $limit->remaining();

        return $remaining === null
            ? $limit->describe()
            : sprintf(
                '%s %s',
                rtrim(rtrim(number_format($remaining, 2, ',', '.'), '0'), ','),
                $limit->kind->label(),
            );
    }

    /**
     * Where it falls due -- the date, or the aircraft counter reading.
     */
    private static function dueAt(Installation $installation): string
    {
        $due = $installation->nextDue();

        if ($due === null) {
            return '—';
        }

        $limit = $due['limit'];

        if ($limit->kind->isCalendar()) {
            return $limit->dueDate()?->format('d.m.Y') ?? '—';
        }

        $at = $limit->dueAtAircraftValue();

        return $at === null
            ? '—'
            : sprintf(
                '%s %s',
                rtrim(rtrim(number_format($at, 2, ',', '.'), '0'), ','),
                $limit->kind->label(),
            );
    }

    /**
     * Four states, so "four days over" does not look like "a year over".
     */
    private static function colourOf(Installation $installation): string
    {
        $due = $installation->nextDue();

        return $due === null ? 'gray' : $due['limit']->status()->colour();
    }
}
