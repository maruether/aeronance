<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eine Zeile je Art und Jahr: der letzte vergebene Zähler.
 *
 * Nur InvoiceNumbers schreibt hierher, und nur unter Sperre.
 */
final class InvoiceNumberRange extends Model
{
    protected $fillable = ['kind', 'year', 'last_number'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'last_number' => 'integer',
        ];
    }
}
