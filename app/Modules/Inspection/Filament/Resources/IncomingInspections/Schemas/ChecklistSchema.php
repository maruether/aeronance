<?php

declare(strict_types=1);

namespace App\Modules\Inspection\Filament\Resources\IncomingInspections\Schemas;

use App\Modules\Inspection\Enums\CheckResult;
use App\Modules\Inspection\Enums\InspectionState;
use App\Modules\Inspection\Models\IncomingInspection;
use App\Modules\Inspection\Models\InspectionCheck;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;

/**
 * Die Checkliste als Formular — ein Durchgang, eine Unterschrift.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WARUM ALLES IN EINEM DIALOG UND NICHT „ANNEHMEN"/„ZURÜCKWEISEN" ALS ZWEI
 * KNÖPFE: Wer die Liste durchgeht, weiß erst am Ende, wie sie ausgeht. Zwei
 * Knöpfe hießen, die Entscheidung vor der Prüfung zu treffen — und dann füllt
 * man die Liste passend zur schon getroffenen Entscheidung aus. Genau das soll
 * eine Eingangsprüfung verhindern.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE FELDNAMEN SIND DER VERTRAG: `answers.<punkt>.result` und
 * `answers.<punkt>.note` ergeben beim Absenden exakt das Array, das
 * CompleteIncomingInspection erwartet. Kein Umbauen dazwischen, in dem sich ein
 * Fehler verstecken könnte.
 *
 * Ein manipulierter Feldname trägt sich dabei nicht ein: Die Aktion läuft über
 * die Prüfpunkte AUS DER DATENBANK und sucht die passende Antwort — was nicht
 * passt, bleibt unbeantwortet, und unbeantwortet heißt „nicht unterschreibbar".
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ChecklistSchema
{
    /** @return list<Section> */
    public static function for(IncomingInspection $inspection): array
    {
        $sections = [];

        foreach ($inspection->checks as $check) {
            $sections[] = self::section($check);
        }

        $sections[] = Section::make(__('inspection.decision.heading'))
            ->description(__('inspection.decision.hint'))
            ->schema([
                Radio::make('outcome')
                    ->label(__('inspection.field.state'))
                    ->options([
                        InspectionState::Accepted->value => __('inspection.action.accept'),
                        InspectionState::Rejected->value => __('inspection.action.reject'),
                    ])
                    ->descriptions([
                        InspectionState::Accepted->value => $inspection->stock_lot_id !== null
                            ? __('inspection.decision.accept_releases')
                            : __('inspection.decision.accept_records'),
                        InspectionState::Rejected->value => __('inspection.decision.reject_holds'),
                    ])
                    ->required(),

                Textarea::make('note')
                    ->label(__('inspection.field.decision_note'))
                    ->helperText(__('inspection.decision.note_hint'))
                    ->rows(3),
            ]);

        return $sections;
    }

    private static function section(InspectionCheck $check): Section
    {
        $item = $check->item;

        return Section::make($item->label())
            /*
             * Der Hinweis steht unter der Ueberschrift, nicht in einem Tooltip.
             * „Ist der Aussteller ueberhaupt berechtigt" ist die Frage, die am
             * haeufigsten uebersprungen wird -- und eine, die man erst
             * aufklappen muss, wird nicht gelesen.
             */
            ->description($item->hint())
            ->schema([
                Radio::make("answers.{$item->value}.result")
                    ->label(__('inspection.field.result'))
                    ->options(collect(CheckResult::cases())
                        ->mapWithKeys(fn (CheckResult $r): array => [$r->value => $r->label()])
                        ->all())
                    ->default($check->result?->value)
                    ->inline()
                    ->required(),

                /*
                 * Nicht als „required" markiert, obwohl sie bei allem ausser
                 * „in Ordnung" faellig ist: Die Regel haengt an der Antwort
                 * daneben und wird in CompleteIncomingInspection durchgesetzt
                 * -- also dort, wo sie auch greift, wenn jemand nicht ueber
                 * dieses Formular kommt.
                 */
                Textarea::make("answers.{$item->value}.note")
                    ->label(__('inspection.field.note'))
                    ->default($check->note)
                    ->rows(2),
            ]);
    }
}
