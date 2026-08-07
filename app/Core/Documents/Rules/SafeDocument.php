<?php

declare(strict_types=1);

namespace App\Core\Documents\Rules;

use App\Core\Documents\DocumentIntake;
use App\Core\Documents\Exceptions\DocumentRejected;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Puts the intake checks in front of the form, not after it.
 *
 * Without this the verification would only happen when the file is handed to the
 * media library -- after the lot has been created, halfway through a booking,
 * as an exception rather than a field error. The person would be told something
 * went wrong without being told which file or why.
 */
final class SafeDocument implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $path = $this->pathOf($value);

        if ($path === null) {
            return;
        }

        try {
            app(DocumentIntake::class)->accept($path, $this->nameOf($value));
        } catch (DocumentRejected $e) {
            $fail($e->getMessage());
        }
    }

    private function pathOf(mixed $value): ?string
    {
        if ($value instanceof TemporaryUploadedFile || $value instanceof UploadedFile) {
            $path = $value->getRealPath();

            return is_string($path) && $path !== '' ? $path : null;
        }

        return null;
    }

    private function nameOf(mixed $value): ?string
    {
        if ($value instanceof TemporaryUploadedFile) {
            return $value->getClientOriginalName();
        }

        if ($value instanceof UploadedFile) {
            return $value->getClientOriginalName();
        }

        return null;
    }
}
