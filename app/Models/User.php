<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Demo\DemoMode;
use App\Core\Models\Qualification;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Permission\Traits\HasRoles;

/**
 * A person who can log in.
 *
 * Carries two distinct kinds of authority, which must not be confused:
 *
 *  - Roles and permissions say what someone may DO in the system. Administered
 *    internally, granted and withdrawn by an administrator.
 *  - Qualifications say what someone may ANSWER FOR. They come from outside --
 *    a Part-66 licence, a pilot-owner entry in an aircraft's maintenance
 *    programme -- they expire, and the pilot-owner kind is valid for one
 *    aircraft rather than in general.
 *
 * Both must be satisfied for the acts that carry airworthiness consequences.
 * See decision E8 in docs/ANALYSE.md.
 */
#[Fillable(['name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasAvatar, HasMedia
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, InteractsWithMedia, Notifiable, SoftDeletes;

    // Aliased so the override below can still reach the original. The permission
    // check arrives through a trait, so parent:: has nothing to call.
    use HasRoles {
        checkPermissionTo as protected checkPermissionToViaPackage;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'locked_at' => 'datetime',

            /*
             * VERSCHLÜSSELT, nicht gehasht -- ein TOTP-Geheimnis muss lesbar
             * bleiben, um den nächsten Code zu berechnen. Wer es im Klartext
             * liest, erzeugt jeden Code, den der Benutzer erzeugt; ein
             * Datenbank-Abzug hebelte damit den zweiten Faktor für alle Konten
             * aus. Siehe die Migration.
             */
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    /** Das eigene Profilbild -- eine Datei, privat abgelegt. */
    public const AVATAR = 'avatar';

    public function registerMediaCollections(): void
    {
        /*
         * singleFile: Ein neues Bild ersetzt das alte. Auf der privaten
         * documents-Disk, nicht im Webroot -- ausgeliefert wird ueber die
         * auth-gepruefte Route (core.avatar), wie jedes Dokument.
         */
        $this->addMediaCollection(self::AVATAR)
            ->useDisk('documents')
            ->singleFile();
    }

    /**
     * Das Bild fuers Panel -- oder null, dann zeichnet der
     * InitialsAvatarProvider die Initialen.
     */
    public function getFilamentAvatarUrl(): ?string
    {
        $bild = $this->getFirstMedia(self::AVATAR);

        if ($bild === null) {
            return null;
        }

        // Die uuid als Versionszusatz: Nach einem neuen Upload aendert sich
        // die Adresse, und kein Browser-Cache zeigt das alte Gesicht.
        return route('core.avatar', ['user' => $this, 'v' => $bild->uuid]);
    }

    /**
     * Darf dieses Konto überhaupt etwas?
     *
     * ─────────────────────────────────────────────────────────────────────────
     * ZWEI GETRENNTE AUSSAGEN, UND BEIDE MÜSSEN JA SAGEN.
     *
     *   is_active  — was der Provider meldet. Der nächtliche Abgleich setzt es
     *                bei jedem Lauf neu.
     *   locked_at  — der Not-Aus dieses Betriebs. Den fasst kein Abgleich an.
     *
     * Die eine Frage sitzt an EINER Stelle, weil sie an drei Stellen gestellt
     * wird (Panel, Rechteprüfung, Gate::before) und die vierte irgendwann
     * dazukommt. Wer sie jedes Mal neu ausschreibt, vergisst sie einmal — und
     * ein vergessener Not-Aus ist keiner.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function hasAccess(): bool
    {
        return $this->is_active && ! $this->isLocked();
    }

    /**
     * Ist der Zugang gesperrt?
     */
    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * Wer die Sperre gesetzt hat — solange dieses Konto noch existiert.
     *
     * @return BelongsTo<User, $this>
     */
    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'locked_by_id');
    }

    /**
     * Zugang sofort sperren.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * „SOFORT" IST WÖRTLICH GEMEINT, und deshalb steht hier mehr als ein
     * `update()`: Die laufende Sitzung wird mitgelöscht.
     *
     * Ohne das bliebe jemand, der gerade angemeldet IST, es bis zum nächsten
     * Seitenaufruf — und da der Sitzungs-Cookie das Anmelden ersetzt, bis er
     * selbst geht. Für einen Not-Aus, den man in einer Minute braucht, ist
     * „beim nächsten Klick" die falsche Zusage.
     *
     * Nur beim Datenbank-Treiber: Bei einem anderen Sitzungsspeicher gibt es
     * die Tabelle nicht, und die Sperre selbst greift trotzdem beim nächsten
     * Aufruf.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function lockAccess(string $reason, ?self $by = null): void
    {
        $this->forceFill([
            'locked_at' => now(),
            'lock_reason' => $reason,
            'locked_by_id' => $by?->getKey(),
        ])->save();

        $this->endAllSessions();

        activity()
            ->performedOn($this)
            ->causedBy($by)
            ->withProperties(['reason' => $reason])
            ->log('access_locked');
    }

    /**
     * Sperre aufheben.
     *
     * Der Grund bleibt NICHT stehen: Eine aufgehobene Sperre ist vorbei, und
     * ein liegengebliebener Grund läse sich beim nächsten Blick wie eine
     * bestehende. Was passiert ist, steht im Audit-Log — dort gehört es hin,
     * denn dort kann es niemand überschreiben.
     */
    public function unlockAccess(?self $by = null): void
    {
        $reason = $this->lock_reason;

        $this->forceFill([
            'locked_at' => null,
            'lock_reason' => null,
            'locked_by_id' => null,
        ])->save();

        activity()
            ->performedOn($this)
            ->causedBy($by)
            ->withProperties(['previous_reason' => $reason])
            ->log('access_unlocked');
    }

    /**
     * Alle Sitzungen dieses Kontos beenden.
     */
    private function endAllSessions(): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $table = (string) config('session.table', 'sessions');

        if (! Schema::hasTable($table)) {
            return;
        }

        DB::table($table)->where('user_id', $this->getKey())->delete();
    }

    /**
     * Whether this account may reach the panel at all.
     *
     * Deactivated accounts are refused here rather than merely having their
     * navigation hidden -- a disabled member must not be able to reach a screen
     * by typing its address.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasAccess();
    }

    /**
     * A deactivated account holds no permissions at all.
     *
     * Overridden here rather than checked in each screen, because this is the
     * method the permission layer itself calls -- so every route to the question
     * passes through it, including the gate callback the package registers.
     *
     * An adversarial test is what made this necessary: the panel turned a
     * deactivated account away, but an individual page still answered yes to
     * its own permission question. The account could not log in, yet any code
     * path asking only "may they?" would have said yes -- and a permission that
     * survives deactivation is not a permission that has been withdrawn.
     */
    public function checkPermissionTo($permission, $guardName = null): bool
    {
        if (! $this->hasAccess()) {
            return false;
        }

        return $this->checkPermissionToViaPackage($permission, $guardName);
    }

    /** @return HasMany<Qualification, $this> */
    public function qualifications(): HasMany
    {
        return $this->hasMany(Qualification::class);
    }

    /**
     * The qualifications that are valid right now.
     *
     * "Held at some point" is not the same as "held today" -- a licence that
     * has lapsed does not cover a release, and the difference is exactly what a
     * later audit asks about.
     *
     * @return HasMany<Qualification, $this>
     */
    public function validQualifications(): HasMany
    {
        return $this->qualifications()->valid();
    }

    // ── Zwei-Faktor-Anmeldung ───────────────────────────────────────────────

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(?string $secret): void
    {
        $this->app_authentication_secret = $secret;
        $this->save();
    }

    /**
     * Was in der Authenticator-App neben dem Code steht.
     *
     * Die E-Mail und nicht der Name: ein Verein hat mehrere Müllers, und ein
     * Eintrag, den man nicht zuordnen kann, wird beim Aufräumen gelöscht.
     */
    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /** @return ?array<string> */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    /** @param  ?array<string>  $codes */
    public function saveAppAuthenticationRecoveryCodes(?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }

    /**
     * Die Demokonten sind unveränderlich.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Vorgabe: „alle mit Benutername=Rollenname und Passwort=demo dies ist
     * nicht änderbar."
     *
     * IM MODELL UND NICHT NUR IN DER MASKE, aus demselben Grund wie überall in
     * diesem System: Eine Regel, die im Formular steht, kennt der nächste
     * Bildschirm nicht -- und hier hängt mehr daran als sonst. Wer in einer
     * öffentlich erreichbaren Demo das Passwort von „admin" ändert, sperrt
     * jeden anderen Besucher aus, und niemand kann es zurückdrehen: Der Reset
     * kommt erst in der Nacht.
     *
     * Ausserhalb des Demomodus tut das hier nichts -- isProtectedAccount()
     * fragt zuerst, ob überhaupt eine Demo läuft.
     * ─────────────────────────────────────────────────────────────────────────
     */
    protected static function booted(): void
    {
        self::updating(function (self $user): void {
            if (! app(DemoMode::class)->isProtectedAccount($user->getOriginal('email'))) {
                return;
            }

            /*
             * Geschützt ist, was den Zugang ausmacht -- und der Name, weil er
             * auf der Anmeldeseite steht. Alles andere (Profilbild, Sprache)
             * darf ein Besucher gern verstellen; der nächste Reset räumt es weg.
             */
            foreach (['email', 'password', 'is_active', 'locked_at'] as $feld) {
                if ($user->isDirty($feld)) {
                    throw new RuntimeException(__('demo.account_locked'));
                }
            }
        });

        self::deleting(function (self $user): void {
            if (app(DemoMode::class)->isProtectedAccount($user->email)) {
                throw new RuntimeException(__('demo.account_locked'));
            }
        });
    }
}
