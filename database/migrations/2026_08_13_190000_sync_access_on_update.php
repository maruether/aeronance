<?php

declare(strict_types=1);

use App\Core\Access\AccessSetup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * NEUE RECHTE ERREICHTEN BESTEHENDE INSTALLATIONEN NICHT.
 *
 * Rechte entstehen in AccessSetup — und das lief bisher nur im Setup-Assistenten
 * und beim Umschalten eines Moduls. Ein Update dazwischen (migrate, Caches)
 * legte KEINE neuen Rechte an: Ein Recht, das eine Fassung neu einführt
 * (heute workorders.findings.report, davor schon die Rechte der
 * Eingangsprüfungs-Entkopplung), existierte auf einer aktualisierten
 * Installation schlicht nicht — und ein Recht, das es nicht gibt, hat auch
 * der Admin nicht.
 *
 * Deshalb läuft der Abgleich jetzt hier: als Migration, über den einen Kanal,
 * der jede Installation sicher erreicht. AccessSetup ist additiv und
 * wiederholbar — bestehende Zuweisungen fasst es nie an, der Admin bekommt
 * Neues zentral über PermissionDefinition. Zusätzlich ruft update.sh ab
 * dieser Fassung `aeronance:sync-access` nach dem Migrieren auf; diese
 * Migration deckt die Installationen, deren update.sh das noch nicht kennt.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Auf einer FRISCHEN Installation läuft das hier mit -- die
         * permissions-Tabelle entsteht früher in derselben Kette, der Guard
         * greift also nur bei exotisch halbem Schema. Das ist unschädlich:
         * AccessSetup legt Kern-Rollen und -Rechte an, die der
         * Setup-Assistent gleich darauf genauso anlegen würde, und Modul-
         * Rechte gibt es mangels aktivierter Module noch keine.
         */
        if (! Schema::hasTable('permissions')) {
            return;
        }

        /*
         * ERST den Rechte-Cache verwerfen, DANN abgleichen. Spatie prüft beim
         * Anlegen gegen seinen Cache, nicht gegen die Tabelle -- läuft diese
         * Migration nach einem migrate:fresh im selben Prozess (Tests!), ist
         * der Cache voll und die Tabelle leer, und das Anlegen bricht mit
         * "already exists" ab, obwohl nichts existiert.
         */
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        app(AccessSetup::class)->run();
    }

    public function down(): void
    {
        // Nichts zurückzunehmen: Es wurden nur fehlende Rechte angelegt, und
        // welche das waren, weiß hinterher niemand mehr.
    }
};
