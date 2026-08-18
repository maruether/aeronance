<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\Weighings\Pages;

use App\Modules\Fleet\Actions\PrepareWeighing;
use App\Modules\Fleet\Enums\SheetVariant;
use App\Modules\Fleet\Enums\Undercarriage;
use App\Modules\Fleet\Filament\Resources\Weighings\Schemas\WeighingForm;
use App\Modules\Fleet\Filament\Resources\Weighings\WeighingResource;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Support\SheetSetup;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

final class ListWeighings extends ListRecords
{
    protected static string $resource = WeighingResource::class;

    /**
     * Ob es ein Muster gibt, das die Angabe noch nicht kennt.
     *
     * Die Frage entscheidet über einen Haken, der sonst entweder ins Leere
     * liefe (kein Muster) oder etwas verspräche, was er nicht tut (Muster kennt
     * beides schon -- überschrieben wird dort nichts).
     */
    private static function typeCanLearn(mixed $aircraftId): bool
    {
        $aircraft = Aircraft::find($aircraftId);

        if ($aircraft === null) {
            return false;
        }

        $setup = SheetSetup::for($aircraft);

        return $setup->hasType && ! $setup->storedOnType;
    }

    protected function getHeaderActions(): array
    {
        return [
            /*
             * Starting from the last sheet rather than from nothing.
             *
             * The manual values come across because they describe the type and
             * retyping them every four years is four chances to transpose a
             * digit. The measurements do not, and the datum least of all -- a
             * prefilled field one is supposed to check is a field nobody checks.
             */
            Action::make('prepare')
                ->label(__('fleet.weighing.new_from_last'))
                ->icon('heroicon-o-document-duplicate')
                ->schema([
                    /*
                     * ─────────────────────────────────────────────────────────
                     * ERST DAS FLUGZEUG, DANN DAS BLATT.
                     *
                     * Feldtest: „wenn ich für die D-EICC eine wägung anlege
                     * bekomme ich als eingabemaske die massenübersicht
                     * segelflugzeug. Bei der Auswahl des Flugzeuges gehört die
                     * abfrage nach typ und fahrwerkskonfiguration rein."
                     *
                     * Dieser Dialog fragte nur nach dem Flugzeug und legte ohne
                     * Vorgängerwägung stumm ein Segelflugblatt an. Jetzt
                     * beantwortet die Wahl des Flugzeugs die beiden Fragen mit
                     * -- und zeigt die Antwort, statt sie zu verschweigen.
                     * ─────────────────────────────────────────────────────────
                     */
                    Select::make('aircraft_id')
                        ->label(__('fleet.aircraft.singular'))
                        ->options(fn (): array => Aircraft::orderBy('registration')
                            ->pluck('registration', 'id')->all())
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set, mixed $state): void {
                            $aircraft = Aircraft::find($state);

                            if ($aircraft === null) {
                                return;
                            }

                            $setup = SheetSetup::for($aircraft);

                            $set('sheet_variant', $setup->variant->value);
                            $set('undercarriage', $setup->undercarriage->value);
                            $set('remember_on_type', $setup->hasType && ! $setup->storedOnType);
                        }),

                    // Dieselben zwei Felder wie im Anlegeformular, aus einer
                    // Quelle -- sichtbar erst, wenn ein Flugzeug gewählt ist,
                    // weil sie vorher nur raten könnten.
                    ...WeighingForm::sheetFields(fn (Get $get): bool => filled($get('aircraft_id'))),

                    /*
                     * „Noch besser wäre wenn Diese Daten direkt im Muster
                     * hinterlegt werden könnten." Hier ist die Stelle, an der
                     * jemand die Angabe ohnehin trifft -- ein Haken, und das
                     * Muster weiss es beim nächsten Exemplar von selbst.
                     *
                     * Sichtbar nur, wenn es etwas zu hinterlegen GIBT: ohne
                     * Muster gibt es keinen Ort dafür, und ein Muster, das
                     * beides schon weiss, wird nicht überschrieben.
                     */
                    Checkbox::make('remember_on_type')
                        ->label(__('fleet.weighing.remember_on_type'))
                        ->helperText(__('fleet.weighing.help.remember_on_type'))
                        ->visible(fn (Get $get): bool => self::typeCanLearn($get('aircraft_id'))),
                ])
                ->action(function (array $data): void {
                    $aircraft = Aircraft::find($data['aircraft_id'] ?? null);

                    if ($aircraft === null) {
                        return;
                    }

                    $action = app(PrepareWeighing::class);
                    $previous = $action->lastSignedOff($aircraft);

                    $weighing = $action->from(
                        aircraft: $aircraft,
                        user: auth()->user(),
                        variant: SheetVariant::tryFrom((string) ($data['sheet_variant'] ?? '')),
                        undercarriage: Undercarriage::tryFrom((string) ($data['undercarriage'] ?? '')),
                    );

                    $body = $previous === null
                        ? __('fleet.weighing.no_previous')
                        : __('fleet.weighing.carried_over', [
                            'date' => $previous->weighed_at->format('d.m.Y'),
                        ]);

                    /*
                     * Das Muster lernt dazu -- und es steht in der Meldung.
                     * Eine stille Änderung am Muster wäre genau die Sorte
                     * Nebenwirkung, die später niemand mehr erklären kann.
                     */
                    if (($data['remember_on_type'] ?? false) === true && $weighing->sheet_variant !== null
                        && $weighing->undercarriage !== null
                        && ($aircraft->aircraftType?->rememberWeighingSetup(
                            $weighing->sheet_variant,
                            $weighing->undercarriage,
                        ) ?? false)
                    ) {
                        $body .= ' '.__('fleet.weighing.remembered', [
                            'type' => (string) $aircraft->aircraftType?->designation,
                        ]);
                    }

                    Notification::make()
                        ->success()
                        ->title(__('fleet.weighing.prepared'))
                        ->body($body)
                        ->send();

                    $this->redirect(WeighingResource::getUrl('edit', ['record' => $weighing]));
                }),
        ];
    }
}
