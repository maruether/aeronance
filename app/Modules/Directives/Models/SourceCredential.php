<?php

declare(strict_types=1);

namespace App\Modules\Directives\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A stored login for a gated manufacturer list.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The username and password are ENCRYPTED at rest. That is the whole reason this
 * is a model and not a config value: encrypted casts mean the ciphertext is what
 * sits in the table and in every backup, and the plaintext exists only for the
 * moment a request actually uses it.
 *
 * The audit trail here is deliberately shallow. activitylog records THAT the
 * credentials changed and by whom -- which an auditor may ask -- but never the
 * values, because logging a secret to make a note of it changing is how secrets
 * leak. dontLogIfAttributesChangedOnly is not enough on its own; the values are
 * kept out of the log entirely.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SourceCredential extends Model
{
    use LogsActivity;

    protected $table = 'directive_credentials';

    protected $fillable = [
        'profile',
        'username',
        'password',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            // encrypted, not hashed: a password sent to a manufacturer has to be
            // recoverable, unlike one that only ever gets compared.
            'username' => 'encrypted',
            'password' => 'encrypted',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            /*
             * The fact and the profile, NEVER the secret.
             *
             * logOnly names only the two harmless columns, so the encrypted ones
             * are not even offered to the logger -- logging a secret in order to
             * note that it changed is how secrets escape.
             *
             * And deliberately NOT logOnlyDirty(): a password change touches
             * nothing but the encrypted column, so "only dirty" found no logged
             * attribute dirty and wrote no entry at all. A credential change that
             * leaves no trace is exactly the one an auditor asks about. Every
             * write is recorded; what changed stays unsaid.
             */
            ->logOnly(['profile', 'updated_by'])
            ->useLogName('directive_credentials');
    }
}
