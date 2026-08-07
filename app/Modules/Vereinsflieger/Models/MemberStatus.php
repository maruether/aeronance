<?php

declare(strict_types=1);

namespace App\Modules\Vereinsflieger\Models;

use App\Modules\Vereinsflieger\Enums\MemberStatusHandling;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Ein Mitgliedsstatus aus Vereinsflieger und was daraus werden soll.
 *
 * Protokolliert, und zwar vollstaendig: Wer hier etwas umstellt, entscheidet
 * ueber den Zugang ganzer Gruppen auf einmal -- im Extremfall ueber 229 Konten
 * mit einem Klick. Das gehoert ins Journal.
 */
final class MemberStatus extends Model
{
    use LogsActivity;

    /**
     * Die einzigen beiden, die von Anfang an feststehen.
     *
     * Vorgabe: „bei memberstatus interessieren mich initial nur 1 und 2." Beide
     * liegen im systemseitigen Nummernbereich und heissen ueberall gleich;
     * alles Uebrige ist vereinsseitig und muss entschieden werden.
     */
    public const SYSTEM_DEFAULTS = [
        '1' => MemberStatusHandling::Active,
        '2' => MemberStatusHandling::Passive,
    ];

    protected $table = 'vereinsflieger_member_statuses';

    protected $fillable = [
        'msid',
        'label',
        'member_count',
        'handling',
        'first_seen_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'member_count' => 'integer',
            'handling' => MemberStatusHandling::class,
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['msid', 'label', 'handling'])
            ->logOnlyDirty()
            ->useLogName('vereinsflieger');
    }

    /**
     * Noch nicht entschieden.
     *
     * NICHT dasselbe wie „ignorieren", auch wenn beide zum selben Ergebnis
     * fuehren: Das eine ist eine Entscheidung, das andere ihr Fehlen.
     */
    public function isUndecided(): bool
    {
        return $this->handling === null;
    }

    public function displayName(): string
    {
        return ($this->label !== null && $this->label !== '')
            ? $this->label
            : __('vereinsflieger.status.unnamed', ['id' => $this->msid]);
    }

    /**
     * Die Behandlung eines Status -- oder null, wenn keine entschieden ist.
     */
    public static function handlingFor(string $msid): ?MemberStatusHandling
    {
        return self::query()->where('msid', $msid)->first()?->handling;
    }
}
