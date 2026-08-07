<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody named in an aircraft's maintenance programme for pilot-owner work.
 *
 * This is the table the core has been waiting for. Authority already knows how
 * to ask "may this person certify FOR THIS AIRCRAFT", and until now nothing
 * could answer, because the obvious answer -- ownership -- is the wrong one.
 *
 * Vorgabe: "ich darf auch an Privatflugzeugen nach Pilot-Owner freigeben, solange
 * ich im AMP aufgeführt bin." So the authority follows the NAMING IN THE
 * PROGRAMME, not the title deed. A holder who is not named has none; somebody
 * named on an aircraft they do not own has it.
 *
 * The name is copied at the moment of listing, like every other certificate
 * content in this system (E7): the programme named a person, and that naming
 * has to stay readable after the account is renamed or pseudonymised.
 */
final class PilotOwnerAuthorisation extends Model
{
    protected $fillable = [
        'aircraft_id',
        'user_id',
        'listed_name',
        'listed_at',
        'valid_until',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'listed_at' => 'date',
            'valid_until' => 'date',
        ];
    }

    /** @return BelongsTo<Aircraft, $this> */
    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isValid(): bool
    {
        return $this->valid_until === null
            || $this->valid_until->toDateString() >= now()->toDateString();
    }

    /** @param  Builder<PilotOwnerAuthorisation>  $query */
    public function scopeValid(Builder $query): void
    {
        $query->where(function (Builder $q): void {
            $q->whereNull('valid_until')
                ->orWhereDate('valid_until', '>=', now()->toDateString());
        });
    }
}
