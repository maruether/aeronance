<?php

declare(strict_types=1);

namespace App\Core\Identity;

use App\Core\Access\CoreRoles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Models\Role;

/**
 * Eine Zuordnung: externes Subjekt oder externe Gruppe -> interne Rolle.
 *
 * Protokolliert, und zwar vollständig: Wer eine Zuordnung anlegt, vergibt damit
 * Rechte an alle, die morgen in diese Gruppe geraten. Das ist eine der
 * folgenreichsten Eingaben im ganzen System.
 */
final class RoleMapping extends Model
{
    use LogsActivity;

    public const KIND_USER = 'user';

    public const KIND_GROUP = 'group';

    protected $fillable = ['provider', 'kind', 'value', 'role_id'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['provider', 'kind', 'value', 'role_id'])
            ->logOnlyDirty()
            ->useLogName('core');
    }

    /**
     * Der erste Riegel gegen eine Zuordnung, die es nicht geben darf.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Freigabeberechtigung kommt nie aus einer Gruppenmitgliedschaft -- siehe
     * CoreRoles::neverFromProvider() und §6.4 der Analyse. Abgewiesen wird schon
     * beim ANLEGEN, damit niemand eine Zuordnung anlegt, die stillschweigend
     * nichts tut: Ein Eintrag, der da steht und wirkungslos ist, ist eine
     * Zusage, die keiner haelt.
     *
     * Der zweite Riegel sitzt in LinkExternalIdentity und greift auch dann,
     * wenn eine Rolle spaeter umbenannt wurde oder eine Zuordnung aus einer
     * Sicherung zurueckkommt, die aelter ist als diese Regel.
     * ─────────────────────────────────────────────────────────────────────────
     */
    protected static function booted(): void
    {
        self::saving(function (self $mapping): void {
            $name = Role::find($mapping->role_id)?->name;

            if ($name !== null && in_array($name, CoreRoles::neverFromProvider(), true)) {
                throw new RuntimeException(sprintf(
                    'Die Rolle "%s" darf nicht aus einem Identity-Provider vergeben werden. '
                    .'Sie ist eine Qualifikationsaussage mit Lizenznachweis und Recency, '
                    .'keine Organisationsaussage -- sie wird hier vergeben, gegen Nachweis.',
                    $name,
                ));
            }
        });
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
