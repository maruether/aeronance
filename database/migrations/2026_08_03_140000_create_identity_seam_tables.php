<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Naht für externe Identitäten -- im KERN, nicht im Provider-Modul.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe vom 2026-07-28 (E4): "VF ist ein Modul zur Anbindung, die eigentlichen
 * Rechte werden im System gebaut und dann werden die User (bei VF ggf. die
 * Funktionen) auf die systeminternen Rollen gematcht. Da gibt es ja auch Samba
 * oder sonst was."
 *
 * Daraus folgt der Schnitt: Ein Connector liefert nur die AUSSENSEITE --
 * Subjekte und Gruppen. Was daraus an Rechten wird, entscheidet der Kern. Ein
 * Fachmodul fragt nie einen Provider; es fragt spatie/laravel-permission, und
 * das ist die einzige Rechte-Wahrheit.
 *
 * DREI TABELLEN, und die dritte ist die, die man beim ersten Entwurf vergisst.
 *
 *   external_identities   Wer bei welchem Provider wer ist.
 *   role_mappings         Welches externe Subjekt oder welche externe Gruppe
 *                         welche interne Rolle bekommt.
 *   external_role_grants  WELCHE Rollen ein Provider tatsächlich vergeben hat.
 *
 * Ohne die dritte lässt sich beim nächsten Abgleich nicht sagen, welche Rolle
 * aus einer Zuordnung stammt und welche jemand von Hand vergeben hat. Ein
 * Abgleich, der einfach alles neu setzt, nimmt einem Mechaniker die lokal
 * erteilte Rolle wieder weg -- und zwar lautlos, irgendwann nachts.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_identities', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // "vereinsflieger", "ldap", "oidc". Der Name des Providermoduls.
            $table->string('provider', 32);

            /*
             * Die Kennung BEIM PROVIDER -- die VF-UID, der LDAP-objectGUID, der
             * OIDC-"sub". Bewusst nicht die E-Mail: die ändert sich, und ein
             * Abgleich, der daran hängt, legt beim nächsten Lauf ein zweites
             * Konto an.
             */
            $table->string('subject', 191);

            // Wie der Benutzer sich dort anmeldet -- nur zur Anzeige.
            $table->string('username', 191)->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // Ein Subjekt gehört zu genau einem Konto, und ein Konto hat je
            // Provider höchstens eine Identität.
            $table->unique(['provider', 'subject']);
            $table->unique(['user_id', 'provider']);
        });

        Schema::create('role_mappings', function (Blueprint $table): void {
            $table->id();

            $table->string('provider', 32);

            /*
             * ZWEI EBENEN, und beide sind nötig. Vorgabe: "die User (bei VF ggf.
             * die Funktionen)" -- zugeordnet wird entweder ein einzelnes
             * Subjekt oder eine externe Gruppe (VF-Funktion, AD-Gruppe,
             * OIDC-Claim). Fehlte eine davon, passte entweder VF oder LDAP
             * nicht ins Schema.
             */
            $table->string('kind', 16);

            // Die Kennung des Subjekts bzw. der Name der Gruppe.
            $table->string('value', 191);

            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['provider', 'kind', 'value', 'role_id'], 'role_mappings_unique');
        });

        Schema::create('external_role_grants', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('provider', 32);

            $table->timestamps();

            $table->unique(['user_id', 'role_id', 'provider'], 'external_role_grants_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_role_grants');
        Schema::dropIfExists('role_mappings');
        Schema::dropIfExists('external_identities');
    }
};
