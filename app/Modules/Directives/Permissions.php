<?php

declare(strict_types=1);

namespace App\Modules\Directives;

/**
 * Who may do what with the list.
 *
 * The split follows the two very different acts: extending the list is
 * bookkeeping, while saying "this does not apply to us" or "we have not done
 * this" is a determination somebody answers for.
 */
final class Permissions
{
    /** Read the list and the assessments. */
    public const DIRECTIVES_VIEW = 'directives.view';

    /** Add lines, import a list, mark one superseded. Bookkeeping. */
    public const DIRECTIVES_MANAGE = 'directives.manage';

    /**
     * Assess a line for an aircraft.
     *
     * Complying, declaring not applicable, declaring not carried out -- all three
     * are statements about an aircraft's airworthiness, and all three need this.
     */
    public const DIRECTIVES_ASSESS = 'directives.assess';
}
