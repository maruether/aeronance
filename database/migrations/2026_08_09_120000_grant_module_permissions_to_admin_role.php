<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * REPARATUR: Der Admin bekommt die Rechte, die ihm nie zugeteilt wurden.
 *
 * Gemessen auf test.aeronance.de mit 0.1.0: Alle Module aktiv, Oberflaeche
 * leer. Sieben von acht Modulen deklarierten ihre Rechte ohne Default-Rollen;
 * beim Aktivieren entstanden die Rechte, gehoerten aber niemandem -- auch der
 * admin-Rolle nicht. Seit dieser Fassung haengt PermissionDefinition den Admin
 * zentral an jede Deklaration; das heilt aber nur NEUE Rechte, denn AccessSetup
 * fasst bestehende absichtlich nie wieder an.
 *
 * Fuer die Rechte, die schon in der Datenbank liegen, laeuft die Reparatur
 * deshalb hier: einmalig, beim Update, als Migration -- der eine Kanal, der
 * jede Installation sicher erreicht.
 *
 * SYNC WAERE FALSCH: syncPermissions wuerfe weg, was ein Verein dem Admin
 * bewusst zusaetzlich gegeben haette. Es wird nur HINZUGEFUEGT, nie entfernt.
 * Dass dabei auch ein bewusst entzogenes Recht zurueckkommen kann, ist bei
 * genau einer produktiven 0.1.0-Installation, deren Admin die Module nie sehen
 * konnte, kein realer Fall -- und ab jetzt gilt ohnehin die zentrale Regel.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first();

        if ($admin === null) {
            // Frische Installation: Die Rolle entsteht erst im Setup -- und
            // dann mit den zentralen Vorgaben, die dieses Problem nicht haben.
            return;
        }

        $fehlend = Permission::query()
            ->where('guard_name', 'web')
            ->whereDoesntHave('roles', fn ($query) => $query->where('id', $admin->id))
            ->get();

        foreach ($fehlend as $recht) {
            $admin->givePermissionTo($recht);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Nicht umkehrbar, und das ist richtig so: Welche der Zuweisungen vor
        // der Reparatur fehlten, weiss hinterher niemand mehr -- ein down(),
        // das raten wuerde, naehme womoeglich gewollte Rechte weg.
    }
};
