<?php

declare(strict_types=1);

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Ein vom Verein gesetzter Wert.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER WERT WIRD NIE PROTOKOLLIERT, und das ist der Grund, warum hier ueberhaupt
 * eine eigene Log-Einstellung steht.
 *
 * Das Audit-Log haelt fest, DASS eine Einstellung geaendert wurde und von wem
 * -- danach fragt ein Pruefer. Was sie vorher und nachher war, gehoert nicht
 * hinein: Unter diesen Schluesseln stehen das Backup-Passwort und der private
 * SFTP-Schluessel. Ein Geheimnis mitzuschreiben, um seine Aenderung zu
 * vermerken, ist der uebliche Weg, auf dem Geheimnisse entkommen -- dasselbe
 * steht schon an SourceCredential.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class Setting extends Model
{
    use LogsActivity;

    protected $fillable = ['key', 'value', 'is_secret'];

    protected function casts(): array
    {
        return [
            // Alles verschluesselt, nicht nur die Geheimnisse -- siehe Migration.
            'value' => 'encrypted',
            'is_secret' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['key'])
            ->logOnlyDirty()
            ->useLogName('core');
    }
}
