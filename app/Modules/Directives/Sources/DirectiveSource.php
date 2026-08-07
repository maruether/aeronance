<?php

declare(strict_types=1);

namespace App\Modules\Directives\Sources;

/**
 * Where a list of directives comes from.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE SUB-MODULE SEAM the requirement was for: "wo möglich per hersteller untermodul im
 * modul ein download. wo das nicht geht manuell und csv."
 *
 * So the module ships two sources that always work -- typing a line, and a CSV
 * paste -- and a registry where a manufacturer adapter can be added later
 * WITHOUT touching anything here. Each adapter's whole job is to turn whatever
 * that manufacturer publishes into DirectiveRow objects; everything downstream
 * (matching, assessing, the audit trail) is identical no matter where a row came
 * from.
 *
 * Deliberately NOT built yet: an actual manufacturer adapter. Every publisher
 * does it differently, so that is one parser per source, and writing the first
 * one before the manual path is proven would make the whole module hang on it.
 * The seam is here so the first adapter is an addition rather than a rewrite.
 * ─────────────────────────────────────────────────────────────────────────────
 */
interface DirectiveSource
{
    /** Stable identifier, stored on every row this source produces. */
    public function name(): string;

    /** Human-readable, for the import screen. */
    public function label(): string;

    /**
     * Whether this source can run unattended.
     *
     * A manufacturer download can be scheduled; a CSV paste cannot. Reported
     * rather than assumed, so a scheduler never has to know the difference.
     */
    public function isAutomatic(): bool;

    /**
     * The rows this source currently offers.
     *
     * @param  array<string, mixed>  $options  source-specific (a URL, a pasted body, a model)
     * @return list<DirectiveRow>
     */
    public function fetch(array $options = []): array;
}
