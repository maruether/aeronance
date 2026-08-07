<?php

declare(strict_types=1);

namespace App\Modules\Directives\Sources;

/**
 * A line somebody typed.
 *
 * Exists as a source so that hand-entered rows carry a source like every other
 * row -- which is what lets a re-import from a manufacturer adapter update its
 * OWN rows and leave typed ones alone. Without this, "manual" would be the
 * absence of a source, and an importer would have to guess.
 *
 * It fetches nothing: the form is the source.
 */
final class ManualSource implements DirectiveSource
{
    public function name(): string
    {
        return 'manual';
    }

    public function label(): string
    {
        return __('directives.source.manual');
    }

    public function isAutomatic(): bool
    {
        return false;
    }

    /** @return list<DirectiveRow> */
    public function fetch(array $options = []): array
    {
        return [];
    }
}
