<?php

declare(strict_types=1);

namespace App\Core\Identity;

use App\Core\Access\CoreRoles;
use App\Core\Mail\SendInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Aus einem externen Subjekt wird ein Konto -- und aus Gruppen werden Rollen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER GANZE UNTERSCHIED ZWISCHEN PROVIDER UND KERN LIEGT HIER.
 *
 * Ein Connector meldet: „Das ist Subjekt 4711, heisst Meier, ist in den Gruppen
 * Werkstatt und Vorstand." Was daraus folgt, entscheidet diese Klasse -- für
 * alle Provider gleich, weil die Regeln nichts mit dem Protokoll zu tun haben.
 *
 * VIER REGELN, jede mit einem Schaden dahinter, den sie verhindert:
 *
 *  1. GEFUNDEN WIRD ÜBER DIE KENNUNG DES PROVIDERS, nicht über die E-Mail.
 *     E-Mail-Adressen ändern sich; ein Abgleich, der daran hängt, legt beim
 *     nächsten Lauf ein zweites Konto für denselben Menschen an -- und der hat
 *     dann seine Rollen nicht mehr.
 *
 *  2. ZURÜCKGENOMMEN WIRD NUR, WAS DER PROVIDER SELBST VERGAB. Ein Abgleich,
 *     der stumpf neu setzt, nimmt einem Mechaniker die lokal erteilte Rolle
 *     wieder weg -- lautlos, irgendwann nachts. Siehe ExternalRoleGrant.
 *
 *  3. AUSGETRETEN HEISST DEAKTIVIERT, NICHT GELÖSCHT. Ein gelöschtes Konto
 *     reisst Löcher in die Nachweiskette, und CLAUDE.md verbietet hartes
 *     Löschen ohnehin. Der Zugang ist weg, die Vergangenheit bleibt lesbar.
 *
 *  4. FREIGABEBERECHTIGUNG KOMMT NIE VON AUSSEN. Siehe
 *     CoreRoles::neverFromProvider() -- und §6.4 der Analyse.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class LinkExternalIdentity
{
    /**
     * @return array{user: User, created: bool, granted: list<string>, revoked: list<string>}
     */
    public function handle(string $provider, ExternalSubject $subject): array
    {
        return DB::transaction(function () use ($provider, $subject): array {
            $identity = ExternalIdentity::query()
                ->where('provider', $provider)
                ->where('subject', $subject->id)
                ->first();

            $user = $identity?->user;
            $created = false;

            if ($user === null) {
                $user = $this->adopt($subject);
                $created = $user->wasRecentlyCreated;
            }

            /*
             * Ein Konto, das beim Provider nicht mehr aktiv ist, verliert den
             * Zugang -- und die Rollen, die dieser Provider ihm gab. Es bleibt
             * aber stehen, siehe Regel 3.
             *
             * `locked_at` wird hier bewusst NICHT angefasst. Das ist der
             * Not-Aus dieses Betriebs, und er muss auch dann halten, wenn der
             * Provider die Person munter als aktiv meldet -- sonst waere die
             * Sperre eine, die um 2 Uhr morgens von selbst aufgeht. Siehe
             * User::hasAccess().
             */
            $user->is_active = $subject->active;

            if (filled($subject->name)) {
                $user->name = $subject->name;
            }

            if (filled($subject->email)) {
                $user->email = $subject->email;
            }

            $user->save();

            ExternalIdentity::updateOrCreate(
                ['provider' => $provider, 'subject' => $subject->id],
                [
                    'user_id' => $user->id,
                    'username' => $subject->username,
                    'last_seen_at' => now(),
                ],
            );

            [$granted, $revoked] = $this->applyRoles($provider, $subject, $user);

            /*
             * ─────────────────────────────────────────────────────────────────
             * EINLADUNG NUR BEI NEUEN KONTEN, und nur wenn eingeschaltet.
             *
             * Vorgabe: „Ich hätte gerne einen Haken 'Einladungen automatisch
             * versenden' sowie einen Button." Der Haken ist ab Werk AUS -- beim
             * ersten Mitgliederabgleich entstehen auf einen Schlag hunderte
             * Konten, und ob die alle sofort eine Mail bekommen, ist eine
             * Entscheidung und keine Voreinstellung.
             *
             * NUR BEI $created: Ein bestehendes Konto bekaeme sonst bei jedem
             * naechtlichen Lauf eine neue Einladung -- jede Nacht, an alle.
             * ─────────────────────────────────────────────────────────────────
             */
            if ($created && (bool) config('aeronance.mail.invite_automatically', false)) {
                app(SendInvitation::class)->handle($user);
            }

            return [
                'user' => $user->fresh() ?? $user,
                'created' => $created,
                'granted' => $granted,
                'revoked' => $revoked,
            ];
        });
    }

    /**
     * Ein vorhandenes Konto übernehmen oder ein neues anlegen.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * DIE E-MAIL DARF VERBINDEN, ABER NICHT WIEDERFINDEN.
     *
     * Beim ERSTEN Mal ist sie die einzige Brücke: Ein Verein, der lokal Konten
     * gepflegt hat und dann einen Provider anschaltet, soll nicht für jeden
     * Menschen ein zweites Konto bekommen. Ab dann trägt die Kennung des
     * Providers -- deshalb wird sie sofort festgeschrieben.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * NICHT JEDER HAT EINE, und das ist GEMESSEN und nicht befürchtet: In der
     * Referenzinstallation haben 368 von 394 Mitgliedern eine Mailadresse --
     * 26 haben keine. Keine unbrauchbare, keine doppelte, keine mit mehreren
     * Adressen in einem Feld; schlicht 26 leere.
     *
     * Diese 26 bekommen ein Konto mit einer Platzhalter-Adresse und können
     * deshalb NICHT eingeladen werden (siehe SendInvitation). Ihr Zugang muss
     * über einen Administrator laufen -- oder über eine Adresse, die jemand in
     * Vereinsflieger nachträgt.
     *
     * Der Platzhalter ist damit kein Notnagel für einen seltenen Fall, sondern
     * der Normalzustand für 7 % der Mitglieder. Er trägt `invalid.local`, weil
     * diese Endung reserviert ist und niemals zustellbar wird: Eine Mail dorthin
     * kann nicht versehentlich bei einem Fremden landen.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * ─────────────────────────────────────────────────────────────────────────
     * DAS KONTO BEKOMMT KEIN PASSWORT -- die Vorgabe: „wenn ein konto neu
     * angelegt wird hat es bitte gar kein passwort. dieses entsteht erst durch
     * einen aktiven passwort reset durch den user."
     *
     * Hier stand vorher Str::random(64), mit der Begründung, angemeldet werde
     * sich ohnehin über den Provider. Beides war falsch: Vereinsflieger ist
     * ein Informations- und kein Identitätsanbieter (siehe dort), es gibt also
     * keine Anmeldung über ihn -- und ein Zufallspasswort IST ein Passwort. Es
     * liegt als Hash in der Datenbank, wandert in jede Sicherung, und niemand
     * kann der Liste ansehen, welche Konten nie jemand benutzt hat.
     *
     * NULL sagt genau das: noch nie aktiviert. Der Weg hinein führt über die
     * Einladung, und die setzt das erste Passwort.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private function adopt(ExternalSubject $subject): User
    {
        if (filled($subject->email)) {
            $vorhanden = User::query()->where('email', $subject->email)->first();

            if ($vorhanden !== null) {
                return $vorhanden;
            }
        }

        return User::create([
            'name' => $subject->name !== '' ? $subject->name : $subject->username,
            'email' => $subject->email ?? $subject->username.'@'.'invalid.local',

            // Kein Passwort. Siehe Kopf -- es entsteht erst, wenn der Mensch
            // selbst eines vergibt.
            'password' => null,
            'is_active' => $subject->active,
        ]);
    }

    /**
     * Rollen aus den Zuordnungen -- und nur die eigenen zurücknehmen.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function applyRoles(string $provider, ExternalSubject $subject, User $user): array
    {
        $soll = $subject->active
            ? $this->mappedRoles($provider, $subject)
            : [];

        $ist = ExternalRoleGrant::query()
            ->where('user_id', $user->id)
            ->where('provider', $provider)
            ->pluck('role_id')
            ->all();

        $zuVergeben = array_diff($soll, $ist);
        $zuNehmen = array_diff($ist, $soll);

        $granted = [];
        $revoked = [];

        foreach ($zuVergeben as $roleId) {
            $role = Role::find($roleId);

            if ($role === null) {
                continue;
            }

            $user->assignRole($role);
            ExternalRoleGrant::create([
                'user_id' => $user->id,
                'role_id' => $roleId,
                'provider' => $provider,
            ]);
            $granted[] = $role->name;
        }

        foreach ($zuNehmen as $roleId) {
            $role = Role::find($roleId);

            if ($role !== null) {
                $user->removeRole($role);
                $revoked[] = $role->name;
            }

            ExternalRoleGrant::query()
                ->where('user_id', $user->id)
                ->where('provider', $provider)
                ->where('role_id', $roleId)
                ->delete();
        }

        return [$granted, $revoked];
    }

    /**
     * Welche Rollen dieses Subjekt laut Zuordnung bekommt.
     *
     * @return list<int>
     */
    private function mappedRoles(string $provider, ExternalSubject $subject): array
    {
        $mappings = RoleMapping::query()
            ->where('provider', $provider)
            ->where(function ($q) use ($subject): void {
                $q->where(function ($q) use ($subject): void {
                    $q->where('kind', RoleMapping::KIND_USER)->where('value', $subject->id);
                });

                if ($subject->groups !== []) {
                    $q->orWhere(function ($q) use ($subject): void {
                        $q->where('kind', RoleMapping::KIND_GROUP)->whereIn('value', $subject->groups);
                    });
                }
            })
            ->with('role')
            ->get();

        $ids = [];

        foreach ($mappings as $mapping) {
            $name = $mapping->role?->name;

            /*
             * Der zweite Riegel. Der erste sitzt beim Anlegen der Zuordnung --
             * aber eine Rolle kann nach dem Anlegen umbenannt worden sein, und
             * eine Zuordnung kann aus einer Sicherung zurueckkommen, die aelter
             * ist als diese Regel. Hier wird sie in jedem Fall wirksam.
             */
            if ($name !== null && in_array($name, CoreRoles::neverFromProvider(), true)) {
                continue;
            }

            $ids[] = (int) $mapping->role_id;
        }

        return array_values(array_unique($ids));
    }
}
