<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Filament\Resources\WorkOrders\Schemas;

use App\Modules\TaskCards\Actions\IssuePartToCard;
use App\Modules\TaskCards\Models\ReleaseToService;
use App\Modules\TaskCards\Models\TaskCard;
use App\Modules\TaskCards\Models\TaskCardTime;
use App\Modules\TaskCards\Models\WorkOrder;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The visit and its cards.
 *
 * Both signatures are shown side by side, because the interesting state is the
 * one where only the first is there.
 */
final class WorkOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('taskcards.work_order.singular'))
                ->schema([
                    TextEntry::make('number')->label(__('taskcards.work_order.field.number')),
                    TextEntry::make('aircraft.registration')->label(__('fleet.aircraft.singular')),
                    TextEntry::make('title')->label(__('taskcards.work_order.field.title')),
                    TextEntry::make('opened_at')
                        ->label(__('taskcards.work_order.field.opened_at'))
                        ->date('d.m.Y'),
                    TextEntry::make('closed_at')
                        ->label(__('taskcards.work_order.field.closed_at'))
                        ->date('d.m.Y')
                        ->placeholder('—'),
                    TextEntry::make('description')
                        ->label(__('taskcards.work_order.field.description'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(3),

            /*
             * The certificate, at the top, with the superseded ones under it.
             *
             * Corrections are shown rather than hidden: the whole point of
             * saying "a correction is a new record" is that the old one stays
             * readable, and hiding it here would undo that in the only place
             * anybody looks.
             */
            Section::make(__('taskcards.release.singular'))
                ->schema([
                    TextEntry::make('release')
                        ->hiddenLabel()
                        ->state(fn (WorkOrder $record): string => self::releaseSummary($record))
                        ->badge()
                        ->color(fn (WorkOrder $record): string => $record->isReleased() ? 'success' : 'warning'),

                    TextEntry::make('statement')
                        ->label(__('taskcards.release.field.statement'))
                        ->state(fn (WorkOrder $record): ?string => $record->currentRelease()?->statement)
                        ->placeholder('—')
                        ->columnSpanFull(),

                    TextEntry::make('print')
                        ->label('')
                        ->state(fn (WorkOrder $record): string => __('taskcards.release.print'))
                        ->url(fn (WorkOrder $record): ?string => $record->currentRelease() !== null
                            ? route('taskcards.release', $record->currentRelease())
                            : null, shouldOpenInNewTab: true)
                        ->badge()
                        ->color('gray')
                        ->visible(fn (WorkOrder $record): bool => $record->currentRelease() !== null),

                    TextEntry::make('superseded')
                        ->label(__('taskcards.release.plural'))
                        ->state(fn (WorkOrder $record): string => self::supersededReleases($record))
                        ->columnSpanFull()
                        ->visible(fn (WorkOrder $record): bool => $record->releases()->count() > 1),
                ]),

            Section::make(__('taskcards.external.singular'))
                ->schema([
                    TextEntry::make('externalWorkOrder')
                        ->hiddenLabel()
                        ->state(function (WorkOrder $record): string {
                            $o = $record->externalWorkOrder;

                            return sprintf(
                                '%s — %s%s',
                                $o->label(),
                                $o->shop_name,
                                $o->isReleased() ? ' · '.__('taskcards.external.released') : '',
                            );
                        }),
                ])
                ->visible(fn (WorkOrder $record): bool => $record->externalWorkOrder !== null),

            Section::make(__('taskcards.card.plural'))
                ->description(__('taskcards.card.help.two_signatures'))
                ->schema([
                    RepeatableEntry::make('taskCards')
                        ->hiddenLabel()
                        ->state(fn (WorkOrder $record): array => $record->taskCards->all())
                        ->schema([
                            TextEntry::make('number')
                                ->label(__('taskcards.work_order.field.number')),

                            TextEntry::make('title')
                                ->label(__('taskcards.card.field.title')),

                            TextEntry::make('activity_kind')
                                ->label(__('taskcards.card.field.activity_kind'))
                                ->state(fn (TaskCard $c): string => $c->activity_kind->label()),

                            TextEntry::make('ata_chapter')
                                ->label('ATA')
                                ->placeholder('—'),

                            TextEntry::make('hours')
                                ->label(__('taskcards.time.plural'))
                                ->state(fn (TaskCard $c): string => sprintf(
                                    '%d:%02d h',
                                    intdiv($c->totalMinutes(), 60),
                                    $c->totalMinutes() % 60,
                                )),

                            // Both signatures, and the gap between them is the
                            // point: a card with only the first is one nobody
                            // has checked.
                            TextEntry::make('signatures')
                                ->label(__('taskcards.card.action.certify'))
                                ->state(fn (TaskCard $c): string => self::signatures($c))
                                ->badge()
                                ->color(fn (TaskCard $c): string => match (true) {
                                    $c->isCertified() => 'success',
                                    $c->awaitsCertification() => 'warning',
                                    default => 'gray',
                                }),

                            /*
                             * ─────────────────────────────────────────────────
                             * DIE UNABHAENGIGE KONTROLLE, sichtbar.
                             *
                             * Ohne diese Zeile koennte man sie durchfuehren und
                             * hinterher nicht nachsehen, wer wann was
                             * kontrolliert hat -- ein Nachweis, den man nicht
                             * lesen kann, ist keiner.
                             *
                             * Nur bei kritischen Karten: Bei allen anderen
                             * waere es eine leere Zeile, und leere Zeilen
                             * machen die wenigen vollen unsichtbar.
                             * ─────────────────────────────────────────────────
                             */
                            TextEntry::make('inspection')
                                ->label(__('taskcards.inspection.heading'))
                                ->visible(fn (TaskCard $c): bool => (bool) $c->critical)
                                ->state(fn (TaskCard $c): string => self::inspection($c))
                                ->badge()
                                ->color(fn (TaskCard $c): string => match (true) {
                                    $c->wasIndependentlyInspected() => 'success',
                                    $c->awaitsIndependentInspection() => 'danger',
                                    default => 'warning',
                                })
                                ->columnSpanFull(),

                            TextEntry::make('inspection_note')
                                ->label(__('taskcards.card.field.inspection_note'))
                                ->visible(fn (TaskCard $c): bool => $c->wasIndependentlyInspected())
                                ->columnSpanFull(),

                            /*
                             * What was asked for, and what happened.
                             *
                             * The card showed only its title until now, which
                             * made the record unreadable exactly where it
                             * matters: the instruction says what was wanted, the
                             * work_performed says what was done, and an auditor
                             * wants the second one.
                             */
                            TextEntry::make('instruction')
                                ->label(__('taskcards.card.field.instruction'))
                                ->placeholder('—')
                                ->columnSpanFull(),

                            // Der Abdruck der Wartungsunterlage -- nur wenn er
                            // erfasst wurde: eine leere Zeile auf jeder Karte
                            // machte die wenigen vollen unsichtbar.
                            TextEntry::make('manual_reference')
                                ->label(__('taskcards.card.field.manual_reference'))
                                ->visible(fn (TaskCard $c): bool => filled($c->manual_reference))
                                ->columnSpanFull(),

                            TextEntry::make('work_performed')
                                ->label(__('taskcards.card.field.work_performed'))
                                ->placeholder('—')
                                ->columnSpanFull(),

                            /*
                             * Hours per person, spelled out.
                             *
                             * This is the data the experience log is derived
                             * from -- the whole reason the project exists -- and
                             * it was visible only as a total, which answers
                             * nothing about who did what. The one number nobody
                             * can use.
                             */
                            TextEntry::make('times')
                                ->label(__('taskcards.time.plural'))
                                ->state(fn (TaskCard $c): string => self::times($c))
                                ->columnSpanFull(),

                            TextEntry::make('parts')
                                ->label(__('taskcards.parts.plural'))
                                ->state(fn (TaskCard $c): string => self::parts($c))
                                ->columnSpanFull()
                                ->visible(fn (): bool => app(IssuePartToCard::class)->isAvailable()),
                        ])
                        ->columns(6),
                ]),

            /*
             * ─────────────────────────────────────────────────────────────────
             * DER BEFUNDBERICHT — dasselbe Blatt, das gedruckt wird.
             *
             * Vorgabe: „Einem Vorgang sollte immer ein befundbericht zugeordnet
             * sein ... nach dem Schema ‚Laufende Nummer - Befund - Behebung -
             * Ausgeführt durch - Geprüft durch - freigegeben durch'."
             *
             * Er steht hier als Blatt und nicht als weitere Kartenliste: Wer
             * ihn ausdruckt, soll vorher gesehen haben, was auf dem Papier
             * landet. Anzeige und Ausdruck sind EINE Datei -- sie können damit
             * nicht auseinanderlaufen.
             *
             * Eingeklappt, weil die Karten darüber die tägliche Sicht sind und
             * der Bericht die zum Abschluss.
             * ─────────────────────────────────────────────────────────────────
             */
            Section::make(__('taskcards.finding_report.title'))
                ->description(__('taskcards.finding_report.description'))
                ->collapsible()
                ->collapsed()
                ->schema([
                    ViewEntry::make('finding_report')
                        ->hiddenLabel()
                        ->view('taskcards.report.screen')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * Who worked how long, and in what role.
     *
     * Spelled out rather than summed, because "executed" and "assisted" are
     * different logbook entries and a total cannot be split back apart.
     */
    private static function times(TaskCard $card): string
    {
        $times = $card->times;

        if ($times->isEmpty()) {
            return __('taskcards.time.none');
        }

        return $times
            ->map(fn (TaskCardTime $t): string => sprintf(
                '%s: %s (%s)',
                $t->person_name,
                $t->describe(),
                $t->participation->label(),
            ))
            ->implode(' · ');
    }

    /**
     * Parts that went to this card, read out of the warehouse ledger.
     */
    private static function parts(TaskCard $card): string
    {
        $issued = app(IssuePartToCard::class)->issuedTo($card);

        if ($issued->isEmpty()) {
            return __('taskcards.parts.none');
        }

        return $issued
            ->map(fn ($movement): string => sprintf(
                '%s × %s%s',
                rtrim(rtrim(number_format(abs((float) $movement->quantity), 3, ',', '.'), '0'), ','),
                $movement->partType?->name ?? '?',
                $movement->lot !== null ? ' ('.$movement->lot->label().')' : '',
            ))
            ->implode(' · ');
    }

    /**
     * Whether the aircraft may fly, and on whose signature.
     */
    private static function releaseSummary(WorkOrder $order): string
    {
        $release = $order->currentRelease();

        if ($release === null) {
            return $order->isReadyForRelease()
                ? __('taskcards.release.awaiting')
                : __('taskcards.release.not_yet');
        }

        return sprintf(
            '%s — %s, %s (%s)',
            $release->number,
            $release->released_at->format('d.m.Y'),
            $release->released_by_name,
            $release->qualification_reference ?? $release->qualification_type,
        );
    }

    /**
     * The certificates that were replaced, and why.
     */
    private static function supersededReleases(WorkOrder $order): string
    {
        return $order->releases
            ->filter(fn (ReleaseToService $r): bool => $r->isSuperseded())
            ->map(fn (ReleaseToService $r): string => sprintf(
                '%s (%s) — %s',
                $r->number,
                $r->released_at->format('d.m.Y'),
                $order->releases->firstWhere('supersedes_release_id', $r->id)?->correction_reason ?? '',
            ))
            ->implode(' · ');
    }

    /**
     * Der Stand der unabhängigen Kontrolle, in einem Satz.
     *
     * „Kontrolle ausstehend" ist dabei die wichtigere Hälfte: Sie steht bei
     * einer Karte, die fertig gemeldet, aber noch nicht freigabefähig ist — und
     * genau die übersieht man sonst.
     */
    private static function inspection(TaskCard $card): string
    {
        if ($card->wasIndependentlyInspected()) {
            return __('taskcards.inspection.done').' — '.($card->inspected_by_name ?? '—')
                .', '.($card->inspected_at?->format('d.m.Y H:i') ?? '');
        }

        return $card->awaitsIndependentInspection()
            ? __('taskcards.inspection.awaiting')
            : ($card->critical_reason ?? __('taskcards.card.field.critical'));
    }

    private static function signatures(TaskCard $card): string
    {
        if ($card->isCertified()) {
            /*
             * ─────────────────────────────────────────────────────────────────
             * WESSEN UNTERSCHRIFT DA STEHT -- und ob sie geprueft wurde.
             *
             * Seit die Freigabe eines vereinsfremden Pruefers die Karten
             * mitzeichnet, steht hier auch ein Name, hinter dem KEINE geprüfte
             * Lizenz liegt, sondern eine abgeschriebene Nummer. Die
             * Bescheinigung sagt das ausdruecklich; die Karte speicherte es
             * zwar, zeigte es aber nicht -- sie sah aus wie jede andere. Wer sie
             * in drei Jahren liest, saehe eine geprüfte Unterschrift, die keine
             * war.
             * ─────────────────────────────────────────────────────────────────
             */
            $unterschrift = $card->certified_by_name ?? '?';

            if ($card->qualification_type === ReleaseToService::CREDENTIAL_EXTERNAL) {
                $unterschrift .= ' ('.__('qualifications.type.external_licence').')';
            }

            return sprintf('%s → %s', $card->completed_by_name ?? '?', $unterschrift);
        }

        if ($card->awaitsCertification()) {
            return sprintf('%s → %s', $card->completed_by_name ?? '?', __('taskcards.awaiting_certification'));
        }

        return $card->state->label();
    }
}
