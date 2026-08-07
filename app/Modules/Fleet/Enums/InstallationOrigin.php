<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Enums;

/**
 * How this line came to be in the life record.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The distinction is WITNESSED versus TRANSCRIBED, and it is permanent.
 *
 * I first refused hand entry outright, on the grounds that a part with no path
 * through the store has no traceable provenance. the correction shows the
 * reasoning was right and the conclusion wrong: an aircraft joining the
 * operation always arrives with components already in it -- a brand new one from
 * the factory, a sixty-year-old glider whose customer is new to the shop. The
 * provenance exists. It is on the papers that came with the aircraft.
 *
 * So the question is not whether such a line may exist, but whether anyone can
 * tell it apart later. A part booked out of our own store was seen arriving,
 * with its certificate on our own shelf. A part written down at onboarding was
 * copied off somebody else's document by somebody who was not there when it was
 * fitted.
 *
 * Both are legitimate. Only one of them is our own evidence, and an auditor
 * asking "how do you know" deserves a different answer in each case.
 * ─────────────────────────────────────────────────────────────────────────────
 */
enum InstallationOrigin: string
{
    /** Issued from our own store, so we saw it arrive and hold its paperwork. */
    case Stock = 'stock';

    /**
     * Written down when the aircraft joined the operation.
     *
     * Not migration -- Vorgabe: "das ist die anlage eines neuen datensatzes ...
     * das nennt sich onboarding, nicht migration. Migration wäre es wenn ich
     * vorher ein anderes system gehabt hätte." It happens every time an aircraft
     * arrives, for ever, and it is a normal business event rather than a one-off
     * import.
     */
    case Onboarding = 'onboarding';

    /**
     * Fitted by another organisation during work we commissioned.
     *
     * A third position, and distinct from both the others. Not witnessed by us
     * like a stock issue -- the part came out of their store. Not historical
     * like an onboarding transcription -- it happened while the aircraft was
     * already our responsibility. The evidence is their work report, and whose
     * signature closes it is a separate question the order itself answers.
     */
    case External = 'external';

    public function label(): string
    {
        return __('fleet.installation.origin.'.$this->value);
    }

    /**
     * Whether the operation itself holds the evidence for this line.
     *
     * False for onboarding: the certificate is real, but it is somebody else's
     * record transcribed by us. Screens use this to say so rather than letting
     * the two look identical.
     */
    public function isOurOwnEvidence(): bool
    {
        return $this === self::Stock;
    }
}
