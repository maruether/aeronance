<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Who holds an aircraft.
 *
 * An entity rather than a name field, because Part-ML pins the continuing
 * airworthiness duty on the holder: a privately held aircraft in the club's care
 * answers to its owner, not to the committee. The brief confirms both kinds are
 * present -- "Verein und privat".
 */
final class Holder extends Model
{
    use SoftDeletes;

    public const TYPE_CLUB = 'club';

    public const TYPE_PRIVATE = 'private';

    protected $attributes = ['type' => self::TYPE_PRIVATE];

    protected $fillable = ['name', 'type', 'user_id', 'contact', 'note'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Aircraft, $this> */
    public function aircraft(): HasMany
    {
        return $this->hasMany(Aircraft::class);
    }

    public function isClub(): bool
    {
        return $this->type === self::TYPE_CLUB;
    }

    public function label(): string
    {
        return $this->name;
    }
}
