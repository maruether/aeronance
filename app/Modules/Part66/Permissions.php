<?php

declare(strict_types=1);

namespace App\Modules\Part66;

/**
 * Who may see whose log.
 *
 * The split is unusual for this project and deliberate: everybody may read their
 * OWN log without any permission at all -- it is their working history, and
 * needing to be granted access to it would be absurd. What needs a permission is
 * reading somebody else's.
 */
final class Permissions
{
    /**
     * See other people's logs.
     *
     * For a workshop manager who has to confirm somebody's experience, and for
     * nobody else. An experience log is personal data about how somebody spends
     * their Saturdays.
     */
    public const LOGS_VIEW_ALL = 'part66.logs.view_all';
}
