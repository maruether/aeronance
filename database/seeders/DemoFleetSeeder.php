<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Fleet\Actions\PrepareWeighing;
use App\Modules\Fleet\Enums\AirframeConstruction;
use App\Modules\Fleet\Enums\CounterKind;
use App\Modules\Fleet\Enums\Propulsion;
use App\Modules\Fleet\Enums\SheetVariant;
use App\Modules\Fleet\Enums\Undercarriage;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AircraftType;
use App\Modules\Fleet\Models\AirworthinessReview;
use App\Modules\Fleet\Models\CounterReading;
use App\Modules\Fleet\Models\Holder;
use App\Modules\Fleet\Models\PilotOwnerAuthorisation;
use App\Modules\Fleet\Models\WeighingEntry;
use Illuminate\Database\Seeder;

/**
 * Die Flotte der Demo: drei Luftfahrzeuge, absichtlich verschieden.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DREI, WEIL ES DREI WÄGEBLÄTTER GIBT -- Segelflugzeug, Motorsegler, Flugzeug.
 * Mit nur einem Segelflugzeug bliebe die halbe Wägerechnung unsichtbar.
 *
 * Die Muster tragen ihre wirklichen Bezeichnungen und KEINE Kennblattnummer:
 * Eine erfundene Nummer sähe echt aus, und irgendwer schriebe sie ab. Die
 * Lücke führt zugleich die Kennblattsuche vor, mit der man sie füllt.
 *
 * Ein Fahrwerk und eine Blattart stehen dagegen sehr wohl am Muster -- genau
 * dafür sind die Felder da, und in der Demo soll man sehen, was sie bewirken.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class DemoFleetSeeder extends Seeder
{
    /** @param  array<string, User>  $konten */
    public function run(array $konten = []): void
    {
        $halter = Holder::create([
            'name' => 'Luftsportverein Musterhausen e.V.',
            'type' => Holder::TYPE_CLUB,
            'contact' => 'vorstand@musterhausen.example',
            'note' => 'Beispielhalter der Demo.',
        ]);

        $segelflugzeug = $this->glider($halter);
        $motorsegler = $this->motorglider($halter);
        $flugzeug = $this->aeroplane($halter);

        $this->counters($segelflugzeug, [CounterKind::FlightHours->value => 3187.4, CounterKind::Landings->value => 4210]);
        $this->counters($motorsegler, [
            CounterKind::FlightHours->value => 1642.2,
            CounterKind::Landings->value => 2038,
            CounterKind::EngineHours->value => 1489.6,
        ]);
        $this->counters($flugzeug, [
            CounterKind::FlightHours->value => 812.9,
            CounterKind::Landings->value => 1105,
            CounterKind::EngineHours->value => 812.9,
        ]);

        /*
         * Zwei gültige Nachprüfungen und eine, die in drei Wochen abläuft:
         * Ohne die dritte zeigt die Demo nur den Ruhezustand -- und die
         * Warnungen sind das, was dieses Programm den ganzen Tag tut.
         */
        $this->review($segelflugzeug, now()->subMonths(9), now()->addMonths(3), 'ARC-DEMO-0031');
        $this->review($motorsegler, now()->subMonths(11), now()->addWeeks(3), 'ARC-DEMO-0044');
        $this->review($flugzeug, now()->subMonths(2), now()->addMonths(10), 'ARC-DEMO-0052');

        /*
         * Pilot-Owner auf ZWEI der drei Luftfahrzeuge -- Vorgabe. Der Halter
         * darf am Segelflugzeug und am Motorsegler die einfachen Arbeiten
         * machen und sie freigeben, am Flugzeug nicht. Genau daran sieht man,
         * dass die Berechtigung am Luftfahrzeug hängt und nicht an der Person.
         */
        if (isset($konten['halter'])) {
            foreach ([$segelflugzeug, $motorsegler] as $lfz) {
                PilotOwnerAuthorisation::create([
                    'aircraft_id' => $lfz->id,
                    'user_id' => $konten['halter']->id,
                    'listed_name' => $konten['halter']->name,
                    'listed_at' => now()->subYear()->toDateString(),
                    'valid_until' => now()->addYear()->toDateString(),
                    'note' => 'Im Instandhaltungsprogramm eingetragen (Beispieldaten).',
                ]);
            }
        }

        $this->weighings($segelflugzeug, $motorsegler, $konten);
    }

    private function glider(Holder $halter): Aircraft
    {
        $muster = AircraftType::create([
            'designation' => 'ASK 21',
            'manufacturer' => 'Alexander Schleicher',
            'type_support' => 'Alexander Schleicher GmbH & Co.',
            'sheet_variant' => SheetVariant::Glider,
            'undercarriage' => Undercarriage::TailwheelOneMain,
            'note' => 'Beispielmuster der Demo. Kennblattnummer bewusst offen — '
                .'sie lässt sich über „Kennblatt suchen" übernehmen.',
        ]);

        return Aircraft::create([
            'registration' => 'D-1234',
            'model' => 'ASK 21',
            'manufacturer' => 'Alexander Schleicher',
            'serial_number' => '21099',
            'year_built' => 1998,
            'aircraft_type_id' => $muster->id,
            'holder_id' => $halter->id,
            'propulsion' => Propulsion::Unpowered,
            'airframe_constructions' => [AirframeConstruction::Composite],
            'in_service_since' => now()->subYears(26)->toDateString(),
            'is_active' => true,
        ]);
    }

    private function motorglider(Holder $halter): Aircraft
    {
        $muster = AircraftType::create([
            'designation' => 'Grob G 109 B',
            'manufacturer' => 'Grob Aircraft',
            'sheet_variant' => SheetVariant::Motorglider,
            'undercarriage' => Undercarriage::TailwheelTwoMains,
            'note' => 'Beispielmuster der Demo.',
        ]);

        return Aircraft::create([
            'registration' => 'D-KABC',
            'model' => 'G 109 B',
            'manufacturer' => 'Grob Aircraft',
            'serial_number' => '6421',
            'year_built' => 1985,
            'aircraft_type_id' => $muster->id,
            'holder_id' => $halter->id,
            'propulsion' => Propulsion::Piston,
            'airframe_constructions' => [AirframeConstruction::Composite],
            'in_service_since' => now()->subYears(39)->toDateString(),
            'is_active' => true,
        ]);
    }

    private function aeroplane(Holder $halter): Aircraft
    {
        $muster = AircraftType::create([
            'designation' => 'Aquila AT01',
            'manufacturer' => 'Aquila Aviation',
            'sheet_variant' => SheetVariant::Aeroplane,
            'undercarriage' => Undercarriage::Tricycle,
            'note' => 'Beispielmuster der Demo.',
        ]);

        return Aircraft::create([
            'registration' => 'D-EICC',
            'model' => 'AT01',
            'manufacturer' => 'Aquila Aviation',
            'serial_number' => 'AT01-241',
            'year_built' => 2012,
            'aircraft_type_id' => $muster->id,
            'holder_id' => $halter->id,
            'propulsion' => Propulsion::Piston,
            'airframe_constructions' => [AirframeConstruction::Composite],
            'in_service_since' => now()->subYears(12)->toDateString(),
            'is_active' => true,
        ]);
    }

    /** @param  array<string, float|int>  $werte */
    private function counters(Aircraft $lfz, array $werte): void
    {
        foreach ($werte as $art => $wert) {
            CounterReading::create([
                'aircraft_id' => $lfz->id,
                'kind' => $art,
                'value' => $wert,
                'read_at' => now()->subDays(3)->toDateString(),
                'note' => 'Beispielstand der Demo.',
            ]);
        }
    }

    private function review(Aircraft $lfz, $von, $bis, string $nummer): void
    {
        AirworthinessReview::create([
            'aircraft_id' => $lfz->id,
            'certificate_reference' => $nummer,
            'issued_at' => $von->toDateString(),
            'valid_until' => $bis->toDateString(),
            'issued_by_name' => 'Karl Kluge',
            'issued_by_approval' => 'DE.MG.DEMO',
            'note' => 'Beispielbescheinigung der Demo.',
        ]);
    }

    /**
     * Zwei Wägungen: eine abgezeichnete am Segelflugzeug, eine offene am
     * Motorsegler.
     *
     * Die abgezeichnete zeigt das fertige Blatt samt Ausdruck, die offene die
     * Eingabe -- und weil sie verschiedene Blattarten sind, sieht man beide
     * Rechenwege.
     *
     * @param  array<string, User>  $konten
     */
    private function weighings(Aircraft $segelflugzeug, Aircraft $motorsegler, array $konten): void
    {
        $wer = $konten['freigabeberechtigter'] ?? null;

        $blatt = app(PrepareWeighing::class)->from(
            aircraft: $segelflugzeug,
            user: $wer,
            variant: SheetVariant::Glider,
            undercarriage: Undercarriage::TailwheelOneMain,
        );

        $blatt->update([
            'weighed_at' => now()->subMonths(9)->toDateString(),
            'place' => 'Musterhausen',
            'datum_reference' => 'Flügelvorderkante Wurzelrippe',
            'reference_line' => 'Rumpfrohr waagerecht (Wasserwaage)',
            'front_support_arm_mm' => 150,
            'support_distance_mm' => 4180,
            'max_mass_kg' => 600,
            'max_non_lifting_kg' => 415,
            'cg_range_from_mm' => 240,
            'cg_range_to_mm' => 400,
            'cg_range_at_mass_kg' => 360,
            'cockpit_load_min_kg' => 70,
            'cockpit_load_max_kg' => 110,
            'signed_by_name' => 'Karl Kluge',
            'signed_by_approval' => 'DE.66.DEMO.0815',
            'equipment_list_dated' => now()->subMonths(9)->toDateString(),
        ]);

        $auflagen = $blatt->fresh(['entries'])->entriesOf(WeighingEntry::SECTION_SUPPORT);

        if ($auflagen->count() >= 2) {
            $auflagen[0]->update(['gross_kg' => 268.4, 'tare_kg' => 2.4, 'arm_mm' => 150]);
            $auflagen[1]->update(['gross_kg' => 92.1, 'tare_kg' => 1.1, 'arm_mm' => 4330]);
        }

        $blatt->load('entries')->recalculate();
        $blatt->update(['signed_off_at' => now()->subMonths(9)]);

        // Der Motorsegler bekommt ein OFFENES Blatt -- die Eingabemaske hat in
        // einer Demo mehr zu zeigen als ein eingefrorenes Dokument.
        app(PrepareWeighing::class)->from(
            aircraft: $motorsegler,
            user: $wer,
            variant: SheetVariant::Motorglider,
            undercarriage: Undercarriage::TailwheelTwoMains,
        );
    }
}
