<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Muster-Laufzeit -- die Vorlage, nicht die Grenze selbst.
 *
 * Die Grenze eines EINBAUS ist ComponentLimit und haengt an der Installation.
 * Diese Zeile hier sagt nur, was ein Muster ueblicherweise mitbringt ("2 Jahre
 * oder 500 Starts" an der Tost-Kupplung); beim Einbau aus dem Lager wird sie
 * KOPIERT -- siehe RecordIssuedPartAsInstallation. Deshalb gibt es hier auch
 * keine Anker (last_done, due_on): Vorlagen haben keinen Verlauf.
 */
final class ComponentTypeLimit extends Model
{
    protected $fillable = [
        'component_type_id',
        'kind',
        'value',
        'tolerance_percent',
        'tolerance_absolute',
        'source',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'tolerance_percent' => 'decimal:2',
            'tolerance_absolute' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<ComponentType, $this> */
    public function componentType(): BelongsTo
    {
        return $this->belongsTo(ComponentType::class);
    }
}
