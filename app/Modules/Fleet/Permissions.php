<?php

declare(strict_types=1);

namespace App\Modules\Fleet;

/**
 * What one may do with the fleet.
 *
 * Verbs, as everywhere. The split worth explaining is between recording a
 * counter and changing an aircraft: reading an instrument and writing the figure
 * down is a routine act at the hangar door, while adding an aircraft or naming
 * somebody in its maintenance programme is a master-data decision with
 * regulatory weight -- the same distinction the warehouse draws between booking
 * goods in and creating a part type.
 */
final class Permissions
{
    public const FLEET_VIEW = 'fleet.view';

    /** Aircraft, holders -- master data. */
    public const FLEET_MANAGE = 'fleet.manage';

    /** Write down what the instrument said. */
    public const COUNTERS_RECORD = 'fleet.counters.record';

    /** Fit and remove components. */
    public const COMPONENTS_MANAGE = 'fleet.components.manage';

    /**
     * Maintain the programme and the list of people named in it.
     *
     * Separate from FLEET_MANAGE because this list decides who may release work
     * under pilot-owner rules -- a permission that grants permissions, and those
     * are worth their own verb.
     */
    public const PROGRAMME_MANAGE = 'fleet.programme.manage';

    /** Record an airworthiness review. */
    public const REVIEWS_RECORD = 'fleet.reviews.record';

    /** Commission work to an outside organisation and book back what returns. */
    public const EXTERNAL_WORK_MANAGE = 'fleet.external_work.manage';

    /**
     * Sign off work somebody else performed.
     *
     * Its own verb, and qualification-bound (see Authority): accepting work one
     * did not watch is a judgement, not an administrative step. Separate from
     * commissioning it, because sending an aircraft to a shop and answering for
     * what comes back are different acts by potentially different people.
     */
    public const EXTERNAL_WORK_ACCEPT = 'fleet.external_work.accept';
}
