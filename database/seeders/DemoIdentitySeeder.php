<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Vereinsflieger\Enums\MemberStatusHandling;
use App\Modules\Vereinsflieger\Models\AircraftLink;
use App\Modules\Vereinsflieger\Models\Connection;
use App\Modules\Vereinsflieger\Models\MemberStatus;
use Illuminate\Database\Seeder;

/**
 * Zwei Vereinsflieger-Anbindungen — beide erfunden, beide ohne Zugangsdaten.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „die Anbindung VF hat 2 dummy einträge mit dummy flugzeugen und
 * mitgliedern. Eine der Anbindungen ist dabei mit mitgliedersync die andere nur
 * lfz zeiten."
 *
 * Das ist genau der Fall, für den es diese Tabelle gibt: Eine CAO betreut
 * Luftfahrzeuge mehrerer Vereine. Der eine Verein liefert auch die Mitglieder
 * (und damit die Konten), der andere nur die Betriebszeiten seiner Flugzeuge.
 *
 * KEINE ZUGANGSDATEN, und das ist keine Nachlässigkeit, sondern die Vorgabe:
 * „zugangsdaten zu vf und co werden nicht gespeichert". In der Demo stehen die
 * Felder deshalb leer, die Anbindungen sind ABGESCHALTET, und der nächtliche
 * Abgleich hat nichts zu holen. Man sieht, wie es aussieht -- ohne dass diese
 * Instanz je einen fremden Server anspricht.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class DemoIdentitySeeder extends Seeder
{
    /** @param  array<string, User>  $konten */
    public function run(array $konten = []): void
    {
        $mitVereinen = Connection::create([
            'name' => 'Luftsportverein Musterhausen e.V. (Beispiel)',
            // Ein Platzhalter, kein Zugang: Passwort, App-Key und Geheimnis
            // bleiben leer, und die Demo laesst sie sich auch nicht eintragen.
            'username' => 'demo',
            'password' => '',
            'app_key' => '',
            'cid' => '000000',
            'provides_identities' => true,
            'is_active' => false,
        ]);

        $nurZeiten = Connection::create([
            'name' => 'Fliegergruppe Beispieltal e.V. (Beispiel)',
            'username' => 'demo',
            'password' => '',
            'app_key' => '',
            'cid' => '000001',
            'provides_identities' => false,
            'is_active' => false,
        ]);

        /*
         * Die Kennzeichen, unter denen Vereinsflieger die Luftfahrzeuge führt.
         * Zwei an der ersten Anbindung, eines an der zweiten -- so sieht man,
         * dass die Zuordnung je Anbindung entsteht und nicht global.
         */
        $this->link($mitVereinen, 'D-1234');
        $this->link($mitVereinen, 'D-KABC');
        $this->link($nurZeiten, 'D-EICC');

        /*
         * Die Mitgliederarten des ersten Vereins, samt Entscheidung, was aus
         * ihnen wird. Das ist die eigentliche Arbeit an einer
         * Identity-Anbindung: Wer bekommt ein Konto, wer nicht.
         */
        $this->status('10', 'Aktive Mitglieder', 42, MemberStatusHandling::Active);
        $this->status('20', 'Passive Mitglieder', 18, MemberStatusHandling::Passive);
        $this->status('30', 'Jugend', 11, MemberStatusHandling::Active);
        $this->status('90', 'Ausgetreten', 7, MemberStatusHandling::Ignore);
    }

    private function link(Connection $anbindung, string $kennzeichen): void
    {
        $lfz = Aircraft::where('registration', $kennzeichen)->first();

        if ($lfz === null) {
            return;
        }

        AircraftLink::create([
            'connection_id' => $anbindung->id,
            'aircraft_id' => $lfz->id,
            'callsign' => $kennzeichen,
            'is_active' => true,
        ]);
    }

    private function status(string $msid, string $label, int $anzahl, MemberStatusHandling $handling): void
    {
        MemberStatus::create([
            'msid' => $msid,
            'label' => $label,
            'member_count' => $anzahl,
            'handling' => $handling,
            'first_seen_at' => now()->subYear(),
            'last_seen_at' => now()->subDay(),
        ]);
    }
}
