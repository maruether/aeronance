<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Actions;

use App\Models\User;
use App\Modules\Fleet\Enums\CounterKind;
use App\Modules\Fleet\Enums\InstallationOrigin;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\ComponentLimit;
use App\Modules\Fleet\Models\CounterReading;
use App\Modules\Fleet\Models\Installation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Taking an aircraft into the operation.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * NOT MIGRATION. the correction, and it changes what this is:
 *
 *   "selbst wenn ich ein nagelneues flugzeug kaufe sind da schon bauteile drin.
 *   das ist keine migration in dem sinne, das ist die anlage eines neuen
 *   datensatzes. gleiches Thema wenn der kunde zum 145 Betrieb kommt. Der vogel
 *   mag Seit 60 Jahren fliegen, ist aber für den Betrieb neu."
 *
 * A migration happens once, out of a previous system, and can be a script that
 * is thrown away. This happens every time an aircraft arrives -- new from the
 * factory with its components already in it, or sixty years old and new only to
 * this shop. It is a normal business event and it needs a real path.
 *
 * What makes that path safe is not refusing it. It is that every line it writes
 * says, permanently, that it was TRANSCRIBED from documents rather than
 * witnessed here -- and names the document it came from. An auditor asking "how
 * do you know this engine has 1800 hours" gets "we read it off the previous
 * operator's Betriebszeitenübersicht dated 12.03.2019", which is a real answer.
 * An unmarked line would get "it says so in our database", which is not.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class OnboardAircraft
{
    /**
     * Records the counters an aircraft arrives with.
     *
     * @param  array<string, float>  $readings  counter value keyed by kind
     */
    public function recordArrivalCounters(
        Aircraft $aircraft,
        array $readings,
        User $user,
        ?string $on = null,
    ): void {
        $when = $on !== null ? Carbon::parse($on) : now();

        foreach ($readings as $kind => $value) {
            $counter = CounterKind::tryFrom((string) $kind);

            if ($counter === null || ! $aircraft->keeps($counter)) {
                continue;
            }

            CounterReading::create([
                'aircraft_id' => $aircraft->id,
                'kind' => $counter,
                'value' => $value,
                'read_at' => $when->toDateString(),
                'user_id' => $user->id,
                'note' => __('fleet.onboarding.arrival_reading'),
            ]);
        }

        if ($aircraft->onboarded_at === null) {
            $aircraft->update(['onboarded_at' => $when->toDateString()]);
        }
    }

    /**
     * Writing down a component the aircraft arrived with.
     *
     * @param  array<string, mixed>  $attributes  part name, serial, document, ...
     * @param  array<string, float>  $sinceNew  what the papers say it has done
     * @param  array<string, float>|null  $sinceOverhaul  null where it equals TSN
     * @param  list<array<string, mixed>>  $limits  life limits from the papers
     */
    public function recordFittedComponent(
        Aircraft $aircraft,
        string $partName,
        string $transcribedFrom,
        User $user,
        array $attributes = [],
        array $sinceNew = [],
        ?array $sinceOverhaul = null,
        array $limits = [],
        ?string $installedAt = null,
    ): Installation {
        if (trim($partName) === '') {
            throw new InvalidArgumentException('A part needs a name to appear in a life record.');
        }

        /*
         * The one refusal here, and it is the whole safeguard.
         *
         * A transcribed line is only as good as the document behind it. Without
         * naming that document, this becomes a way to type any component into
         * any aircraft with nothing to check it against -- which is exactly what
         * refusing hand entry was meant to prevent, arriving through a door
         * marked "onboarding".
         */
        if (trim($transcribedFrom) === '') {
            throw new InvalidArgumentException(
                'A component written down at onboarding has to name the document it was '
                .'taken from. Without it there is nothing behind the entry.'
            );
        }

        $when = $installedAt !== null ? Carbon::parse($installedAt) : now();

        return DB::transaction(function () use (
            $aircraft, $partName, $transcribedFrom, $user, $attributes,
            $sinceNew, $sinceOverhaul, $limits, $when
        ): Installation {
            $installation = Installation::create(array_merge($attributes, [
                'aircraft_id' => $aircraft->id,
                'origin' => InstallationOrigin::Onboarding,
                'part_name' => trim($partName),

                'transcribed_from' => trim($transcribedFrom),
                'transcribed_at' => now()->toDateString(),
                'transcribed_by' => $user->id,
                'transcribed_by_name' => $user->name,

                /*
                 * The date the part went INTO THE AIRCRAFT, which is usually
                 * long before it came to us. Not today's date -- writing the
                 * transcription date here would restart every calendar limit on
                 * the day of onboarding and hand a fifteen-year-old release a
                 * fresh two years.
                 */
                'installed_at' => $when->toDateString(),

                /*
                 * The aircraft's counters as they stand NOW, not as they stood
                 * when the part was fitted. We were not there for that, and the
                 * previous operator's aircraft counter is not on our clock
                 * anyway -- what the part has already done travels in
                 * carried_since_*, where it belongs.
                 */
                'counters_at_installation' => $aircraft->currentValues(),

                'carried_since_new' => $sinceNew,
                'carried_since_overhaul' => $sinceOverhaul,
            ]));

            foreach ($limits as $limit) {
                ComponentLimit::create(array_merge($limit, [
                    'installation_id' => $installation->id,
                ]));
            }

            return $installation->fresh();
        });
    }
}
