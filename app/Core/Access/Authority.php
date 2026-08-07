<?php

declare(strict_types=1);

namespace App\Core\Access;

use App\Core\Models\Qualification;
use App\Core\Models\QualificationLimitation;
use App\Models\User;

/**
 * The two-stage check from decision E8.
 *
 * Most actions need only a permission: may this person operate this function?
 * A handful carry airworthiness consequences and need a second answer as well:
 * may this person ANSWER for it?
 *
 *     $authority->permits($user, 'stock.issue')                  // one stage
 *     $authority->certifies($user, 'stock.scrap')                // two stages
 *     $authority->certifiesFor($user, 'releases.issue', 'D-KABC') // ...and for this aircraft
 *     $authority->certifiesFor($user, 'releases.issue', 'D-KABC', $subject) // ...on THIS
 *
 * The fourth argument is the licence limitations, added when qualifications
 * learned that they can be restricted ("ausgenommen Zellen in Metallbauweise").
 * It is optional because not every act has a subject to compare against; where
 * one is passed, a licence excluding it stops counting. See WorkSubject.
 *
 * Keeping this in one place means the rule "declaring a part unserviceable is
 * reserved for Part-66 personnel" is stated once rather than scattered across
 * policies, and the list of acts that require a qualification stays readable.
 */
final readonly class Authority
{
    /**
     * Acts that need a qualification on top of the permission.
     *
     * These are exactly the snapshot cases from E7, which is not a coincidence:
     * an act that has to be frozen for the record is an act someone answers
     * for, and an act someone answers for needs the credential to be checked.
     *
     * @var array<string, list<string>> permission => acceptable qualification types
     */
    private const REQUIRES_QUALIFICATION = [
        // Declaring a part unserviceable or unsalvageable takes it out of
        // service for good; the reverse -- putting it back -- is the same kind
        // of judgement in the other direction.
        'stock.quarantine.certify' => [Qualification::TYPE_PART66],
        'stock.scrap' => [Qualification::TYPE_PART66],

        // A release may be signed under a Part-66 licence or, within its narrow
        // limits, as a pilot-owner.
        'releases.issue' => [Qualification::TYPE_PART66, Qualification::TYPE_PILOT_OWNER],

        // Accepting work another organisation performed. Not the same as doing
        // it: somebody here signs for work they did not watch, on the strength
        // of a report -- which is a judgement, and the first one an auditor asks
        // about. Named here for the same reason releases.issue is, ahead of its
        // module: the rule belongs in one place rather than scattered across
        // policies later.
        'fleet.external_work.accept' => [Qualification::TYPE_PART66, Qualification::TYPE_PILOT_OWNER],

        // Signing a card off. The second of the two signatures a card carries:
        // whoever did the work says it is finished, and somebody qualified says
        // it was done properly. Only the second is a judgement.
        'workorders.cards.certify' => [Qualification::TYPE_PART66, Qualification::TYPE_PILOT_OWNER],

        // Deciding a finding can wait. Noticing a crack needs no licence;
        // deciding it holds until the next inspection does.
        'workorders.findings.defer' => [Qualification::TYPE_PART66, Qualification::TYPE_PILOT_OWNER],

        // Assessing an LTA/TM line for an aircraft -- all three answers, and
        // "not applicable" most of all. Complying and declaring something not
        // carried out are visibly judgements; declaring a directive INAPPLICABLE
        // looks like the cautious option and is the dangerous one, because a
        // mandatory directive then leaves the list without anybody noticing.
        'directives.assess' => [Qualification::TYPE_PART66, Qualification::TYPE_PILOT_OWNER],
    ];

    /**
     * Stage one: does the permission allow it?
     */
    public function permits(User $user, string $permission): bool
    {
        return $user->is_active && $user->can($permission);
    }

    /**
     * Both stages, for acts that are not tied to a particular aircraft.
     */
    public function certifies(User $user, string $permission): bool
    {
        return $this->certifiesFor($user, $permission, null);
    }

    /**
     * Both stages, for an act concerning one aircraft.
     *
     * A pilot-owner authorisation counts only for the aircraft it was entered
     * against in the maintenance programme; a Part-66 licence is not tied to one.
     *
     * The optional subject is the third question, and it is optional because not
     * every act has one: a licence limitation ("ausgenommen Zellen in
     * Metallbauweise") can only be compared against work on an aircraft, and
     * declaring a part in a shelf unsalvageable is not that. Where a subject IS
     * passed, limitations are applied -- see heldQualification.
     */
    public function certifiesFor(
        User $user,
        string $permission,
        ?string $scope,
        ?WorkSubject $subject = null,
    ): bool {
        if (! $this->permits($user, $permission)) {
            return false;
        }

        $accepted = self::REQUIRES_QUALIFICATION[$permission] ?? null;

        if ($accepted === null) {
            return true;
        }

        return $this->heldQualification($user, $accepted, $scope, $subject) !== null;
    }

    /**
     * The qualification a person is relying on for this act, if any.
     *
     * Returned rather than merely tested, because whoever performs the act has
     * to write it into the record: type, number, category and validity at the
     * time. Without that it cannot later be established whether the act was
     * covered. See E7.
     */
    public function qualificationFor(
        User $user,
        string $permission,
        ?string $scope = null,
        ?WorkSubject $subject = null,
    ): ?Qualification {
        $accepted = self::REQUIRES_QUALIFICATION[$permission] ?? null;

        if ($accepted === null || ! $this->permits($user, $permission)) {
            return null;
        }

        return $this->heldQualification($user, $accepted, $scope, $subject);
    }

    /**
     * Why a licence that would otherwise have covered this act does not.
     *
     * For the message, and only for the message: qualificationFor() has already
     * said no by then, and "keine gültige Qualifikation" would be misleading
     * when the truth is "die Lizenz ist für Metallzellen eingeschränkt". A
     * refusal somebody cannot act on gets worked around rather than fixed.
     */
    public function limitationBlocking(
        User $user,
        string $permission,
        ?string $scope,
        WorkSubject $subject,
    ): ?QualificationLimitation {
        $accepted = self::REQUIRES_QUALIFICATION[$permission] ?? null;

        if ($accepted === null || ! $this->permits($user, $permission)) {
            return null;
        }

        foreach ($this->candidates($user, $accepted, $scope) as $qualification) {
            $blocking = $qualification->blockedBy($subject);

            if ($blocking !== null) {
                return $blocking;
            }
        }

        return null;
    }

    public function requiresQualification(string $permission): bool
    {
        return isset(self::REQUIRES_QUALIFICATION[$permission]);
    }

    /**
     * The qualification this person may rely on, limitations applied.
     *
     * @param  list<string>  $acceptedTypes
     */
    private function heldQualification(
        User $user,
        array $acceptedTypes,
        ?string $scope,
        ?WorkSubject $subject = null,
    ): ?Qualification {
        foreach ($this->candidates($user, $acceptedTypes, $scope) as $qualification) {
            /*
             * A limitation is an exclusion from the certifying privileges
             * (66.A.50) and it applies to the whole licence, not to one of its
             * categories. So a licence that excludes what this work touches
             * simply is not a licence for this work -- it drops out here, and
             * another one the person holds may still cover it.
             */
            if ($subject !== null && $qualification->blockedBy($subject) !== null) {
                continue;
            }

            return $qualification;
        }

        return null;
    }

    /**
     * Everything valid this person could rely on, best first.
     *
     * ORDER MATTERS because a person may hold more than one. An unrestricted
     * Part-66 licence comes before one carrying the MA.803(b) cap, and both come
     * before a pilot-owner authorisation -- from widest privilege to narrowest,
     * so that holding the narrow one never costs somebody the wide one.
     *
     * @param  list<string>  $acceptedTypes
     * @return list<Qualification>
     */
    private function candidates(User $user, array $acceptedTypes, ?string $scope): array
    {
        return $user->validQualifications()
            ->whereIn('type', $acceptedTypes)
            ->get()
            // A qualification without a scope applies generally; one with a
            // scope only to that aircraft.
            ->filter(fn (Qualification $q): bool => $q->scope === null || $q->scope === $scope)
            ->sortBy(fn (Qualification $q): int => match (true) {
                $q->type === Qualification::TYPE_PART66 && ! $q->isCappedToPilotOwnerScope() => 0,
                $q->type === Qualification::TYPE_PART66 => 1,
                default => 2,
            })
            ->values()
            ->all();
    }
}
