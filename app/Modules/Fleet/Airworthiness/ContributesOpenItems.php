<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Airworthiness;

use App\Modules\Fleet\Models\Aircraft;

/**
 * Something that can say why an aircraft is not ready.
 *
 * The extension point. The verdict draws on things owned by several modules --
 * the fleet knows its limits and papers, the task cards will know their open
 * findings, the releases will know whether a certificate was signed -- and none
 * of them may reach into the others.
 *
 * So each contributes what it knows and the fleet collects. Exactly the shape of
 * the permission registry in the core, and for the same reason: a module that is
 * not installed contributes nothing, and nothing breaks.
 */
interface ContributesOpenItems
{
    /** @return list<OpenItem> */
    public function openItemsFor(Aircraft $aircraft): array;
}
