<?php

declare(strict_types=1);

namespace App\Core\Identity;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Eine beim Provider gefundene Gruppe -- gespeichert, damit die Auswahl in der
 * Oberflaeche keinen Netzzugriff braucht.
 *
 * Bewusst OHNE Aktivitaetsprotokoll: Das hier ist kein Beschluss, sondern ein
 * Abbild dessen, was draussen steht. Protokolliert gehoert die ZUORDNUNG
 * (RoleMapping) -- die vergibt Rechte. Ein Protokolleintrag pro gefundener
 * Funktion pro Abruf waere Rauschen in genau dem Journal, das im Ernstfall
 * lesbar sein muss.
 */
final class ExternalGroup extends Model
{
    protected $fillable = [
        'provider',
        'value',
        'label',
        'member_count',
        'first_seen_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'member_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** Was angezeigt wird -- Anzeigename, sonst der Vergleichswert. */
    public function displayName(): string
    {
        return ($this->label !== null && $this->label !== '') ? $this->label : $this->value;
    }

    public const STATUS_CURRENT = 'aktuell';

    public const STATUS_GONE = 'fehlte';

    public const STATUS_UNCONFIRMED = 'unbestaetigt';

    /**
     * Wie diese Gruppe zum Provider steht.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * DREI ZUSTAENDE, WEIL ZWEI LUEGEN WUERDEN.
     *
     * „Unbestaetigt" ist der Fall, den man leicht uebersieht: Bei Vereinsflieger
     * entsteht die Funktionsliste AUS DEN MITGLIEDERN -- eine gerade angelegte
     * Funktion, die noch niemand traegt, ist ueber die API nicht sichtbar. Wer
     * sie von Hand eintraegt, hat deshalb nicht zwingend einen Tippfehler
     * gemacht; er ist nur schneller als der erste Traeger. Diesen Eintrag wie
     * eine verschwundene Gruppe zu behandeln waere falsch, ihn wie eine
     * bestaetigte zu behandeln aber auch.
     *
     * Verglichen wird gegen den JUENGSTEN Abruf dieses Providers, nicht gegen
     * ein Alter in Tagen: Ein Verein, der einmal im Jahr abgleicht, haette sonst
     * dauernd „verschwundene" Funktionen, die es laengst noch gibt.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function status(): string
    {
        if ($this->last_seen_at === null) {
            return self::STATUS_UNCONFIRMED;
        }

        $juengster = self::query()
            ->where('provider', $this->provider)
            ->max('last_seen_at');

        if ($juengster === null) {
            return self::STATUS_CURRENT;
        }

        return $this->last_seen_at->lt($juengster)
            ? self::STATUS_GONE
            : self::STATUS_CURRENT;
    }

    /** @param  Builder<self>  $query */
    public function scopeOfProvider(Builder $query, string $provider): void
    {
        $query->where('provider', $provider);
    }
}
