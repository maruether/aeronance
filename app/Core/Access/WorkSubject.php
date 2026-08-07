<?php

declare(strict_types=1);

namespace App\Core\Access;

use App\Core\Enums\MaintenanceSubject;

/**
 * What an act is being performed ON, in the words a licence uses.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THIS EXISTS TO KEEP THE CORE OUT OF THE FLEET. A limitation is licence-wide
 * and reads "ausgenommen Zellen in Metallbauweise"; answering whether it bites
 * needs to know what the aircraft is made of, and aircraft live in a module that
 * need not be installed.
 *
 * So the core states the rule, the fleet states the fact, and the caller -- who
 * has both in hand -- brings them together by passing one of these. Nothing in
 * App\Core ever loads an Aircraft.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * NULL MEANS NOT RECORDED, and that is not the same as "touches nothing". An
 * airframe is always made of something; an empty list can only mean nobody wrote
 * it down. An empty PROPULSION list is different -- it is the ordinary state of
 * a glider and a perfectly good answer.
 */
final readonly class WorkSubject
{
    /**
     * @param  list<MaintenanceSubject>|null  $airframe  null = not recorded
     * @param  list<MaintenanceSubject>|null  $propulsion  null = not recorded, [] = unpowered
     */
    public function __construct(
        public ?array $airframe = null,
        public ?array $propulsion = null,
    ) {}

    /** Nothing is known about what this act touches. */
    public static function unrecorded(): self
    {
        return new self;
    }

    /**
     * Whether this work touches the given subject.
     *
     * Three answers, and the third is the point: true, false, or NULL for "the
     * data to decide it was never recorded". A limitation check that cannot tell
     * must not quietly answer no.
     */
    public function touches(MaintenanceSubject $subject): ?bool
    {
        return match ($subject->area()) {
            'airframe' => $this->airframe === null || $this->airframe === []
                ? null
                : in_array($subject, $this->airframe, true),

            'propulsion' => $this->propulsion === null
                ? null
                : in_array($subject, $this->propulsion, true),

            /*
             * Avionics is never decidable here. Whether a job touched the radio
             * is a property of the JOB, and no field in this system records it
             * -- the ATA chapter is optional free text, and gliding often keeps
             * none. An avionics limitation is therefore recorded on the licence,
             * shown, and frozen into the certificate, but it does not gate
             * anything. An honest gap beats an invented refusal.
             */
            default => null,
        };
    }

    /** Whether anything at all is known. */
    public function isRecorded(): bool
    {
        return ($this->airframe !== null && $this->airframe !== []) || $this->propulsion !== null;
    }
}
