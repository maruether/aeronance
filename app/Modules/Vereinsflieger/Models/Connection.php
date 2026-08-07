<?php

declare(strict_types=1);

namespace App\Modules\Vereinsflieger\Models;

use App\Modules\Vereinsflieger\VereinsfliegerClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Eine Vereinsflieger-Anbindung.
 *
 * Eine CAO betreut Luftfahrzeuge mehrerer Vereine, und jeder Verein hat seinen
 * eigenen Vereinsflieger. Deshalb ist der Zugang ein DATENSATZ und keine
 * Einstellung.
 */
final class Connection extends Model
{
    use LogsActivity;

    protected $table = 'vereinsflieger_connections';

    protected $fillable = [
        'name',
        'username',
        'password',
        'app_key',
        'auth_secret',
        'password_is_hash',
        'cid',
        'provides_identities',
        'is_active',
    ];

    /**
     * Nie in einer Ausgabe, nie in einem Fehlerbericht.
     *
     * @var list<string>
     */
    protected $hidden = ['password', 'app_key', 'auth_secret'];

    protected function casts(): array
    {
        return [
            // Verschluesselt in der Datenbank. Rueckholbar, weil Vereinsflieger
            // den Klartext braucht -- die hashen selbst (F19).
            'password' => 'encrypted',
            'app_key' => 'encrypted',
            'auth_secret' => 'encrypted',

            'password_is_hash' => 'boolean',
            'provides_identities' => 'boolean',
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * Protokolliert -- aber NIE die Geheimnisse.
     *
     * Ein Aktivitaetsprotokoll, das Passwoerter mitschreibt, ist ein zweiter
     * Ort, an dem sie stehen, und zwar einer ohne Verschluesselung.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'username', 'cid', 'provides_identities', 'is_active'])
            ->logOnlyDirty()
            ->useLogName('vereinsflieger');
    }

    /**
     * Hoechstens eine Anbindung liefert Identitaeten.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Ein Mensch hat ein Konto. Kaeme er aus zwei Vereinsfliegern, gaebe es
     * zwei Wahrheiten darueber, wer er ist -- und die Zuordnung von Funktionen
     * auf Rollen wuesste nicht, welche gilt.
     *
     * Durchgesetzt wird es hier und nicht in der Datenbank: „hoechstens eine
     * Zeile mit true" laesst sich dort nicht ohne Verrenkungen ausdruecken. Und
     * zwar durch UMSCHALTEN statt durch Abweisen -- wer eine neue Anbindung zur
     * Identitaetsquelle macht, meint genau das, und eine Fehlermeldung
     * zwaenge ihn nur, vorher die andere abzuschalten.
     * ─────────────────────────────────────────────────────────────────────────
     */
    protected static function booted(): void
    {
        self::saved(function (self $connection): void {
            if (! $connection->provides_identities) {
                return;
            }

            self::query()
                ->where('id', '!=', $connection->id)
                ->where('provides_identities', true)
                ->update(['provides_identities' => false]);
        });
    }

    /** @return HasMany<AircraftLink, $this> */
    public function aircraftLinks(): HasMany
    {
        return $this->hasMany(AircraftLink::class, 'connection_id');
    }

    /**
     * Die Anbindung, aus der Menschen kommen -- oder keine.
     */
    public static function identitySource(): ?self
    {
        return self::query()
            ->where('provides_identities', true)
            ->where('is_active', true)
            ->first();
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Ein Client fuer genau diese Anbindung.
     *
     * Ein Objekt = eine Sitzung, wie im Client beschrieben. Wer zwei
     * Anbindungen abfragt, bekommt zwei Objekte und zwei Anmeldungen -- das
     * ist nicht sparsam zu machen, weil es zwei verschiedene Vereine sind.
     */
    public function client(): VereinsfliegerClient
    {
        return new VereinsfliegerClient(
            username: (string) $this->username,
            password: (string) $this->password,
            appKey: (string) $this->app_key,
            passwordIsHash: (bool) $this->password_is_hash,
            cid: (string) ($this->cid ?: '0'),
            authSecret: (string) ($this->auth_secret ?? ''),
        );
    }

    /**
     * Festhalten, wie der letzte Lauf ausging.
     *
     * Auf dem Bildschirm und nicht nur im Log: Eine Anbindung, die seit drei
     * Wochen scheitert, soll man sehen, ohne Logdateien zu lesen.
     */
    public function recordRun(?string $fehler = null): void
    {
        $this->forceFill([
            'last_run_at' => now(),
            'last_error' => $fehler,
        ])->saveQuietly();
    }
}
