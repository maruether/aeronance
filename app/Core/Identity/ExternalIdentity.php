<?php

declare(strict_types=1);

namespace App\Core\Identity;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Die Verbindung zwischen einem Konto hier und einem Subjekt dort.
 *
 * Gehalten wird die Kennung des Providers, NICHT die E-Mail: Die ändert sich,
 * und ein Abgleich, der daran hängt, legt beim nächsten Lauf ein zweites Konto
 * für denselben Menschen an.
 */
final class ExternalIdentity extends Model
{
    protected $fillable = ['user_id', 'provider', 'subject', 'username', 'last_seen_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
