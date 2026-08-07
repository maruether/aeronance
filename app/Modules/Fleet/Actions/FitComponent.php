<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Actions;

use App\Models\User;
use App\Modules\Fleet\Enums\CounterKind;
use App\Modules\Fleet\Enums\UsageBasis;
use App\Modules\Fleet\Events\ComponentRemovedFromAircraft;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\Installation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Fitting a part to an aircraft, and carrying its life across.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The whole difficulty is the two engines.
 *
 * Engine A goes to the manufacturer, who overhauls it and RESETS THE TSO to
 * nil; the TSN carries on. Engine B goes to the SAME manufacturer for a repair
 * and the TSO is NOT reset. Identical journeys, different outcomes.
 *
 * So the reset is NEVER inferred. Not from a repair having happened, not from
 * where the part went, not from how long it was away. It is an explicit
 * statement made when the part is fitted, backed by the document that says an
 * overhaul was performed -- because that document is the only thing that
 * actually distinguishes engine A from engine B.
 *
 * Everything else carries forward by itself. Fitting a part whose serial number
 * has been here before picks up where its last installation left off, so a
 * component's history survives being taken off one aircraft and put on another
 * -- which is exactly what would otherwise be lost, quietly, and in the
 * direction that flatters the part.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class FitComponent
{
    /**
     * @param  array<string, mixed>  $attributes  part name, lot reference, document, ...
     * @param  array<string, float>|null  $carriedSinceNew  overrides the carry-forward
     * @param  array<string, float>|null  $carriedSinceOverhaul
     */
    public function handle(
        Aircraft $aircraft,
        string $partName,
        User $user,
        ?string $installedAt = null,
        array $attributes = [],
        ?array $carriedSinceNew = null,
        ?array $carriedSinceOverhaul = null,
        bool $overhauled = false,
        ?string $overhauledAt = null,
        ?string $overhaulReference = null,
    ): Installation {
        if (trim($partName) === '') {
            throw new InvalidArgumentException('A part needs a name to appear in a life record.');
        }

        if ($overhauled && trim((string) $overhaulReference) === '') {
            // Zeroing a component's time between overhauls is a claim about its
            // life. A claim with no document behind it is the kind an audit asks
            // about, and the answer "somebody ticked a box" is not one.
            throw new InvalidArgumentException(
                'Resetting the time since overhaul requires the document that says an '
                .'overhaul was carried out.'
            );
        }

        $when = $installedAt !== null ? Carbon::parse($installedAt) : now();
        $serial = $attributes['serial_number'] ?? null;

        $previous = $serial !== null ? $this->previousInstallation((string) $serial) : null;

        return DB::transaction(function () use (
            $aircraft, $partName, $user, $when, $attributes, $previous,
            $carriedSinceNew, $carriedSinceOverhaul, $overhauled, $overhauledAt, $overhaulReference
        ): Installation {
            $sinceNew = $carriedSinceNew ?? $this->accumulated($previous, UsageBasis::SinceNew);

            $sinceOverhaul = match (true) {
                $carriedSinceOverhaul !== null => $carriedSinceOverhaul,

                // The overhaul. Everything measured since the last one starts
                // again; everything measured since new does not.
                //
                // Explicit zeros, not an empty map: absence means "this part has
                // no overhaul concept, so TSO is TSN" and would fall straight
                // back to the figure being reset. The distinction between "no
                // answer" and "nil" is the whole of engine A.
                $overhauled => $this->zeroed(),

                default => $this->accumulated($previous, UsageBasis::SinceOverhaul),
            };

            return Installation::create(array_merge($attributes, [
                'aircraft_id' => $aircraft->id,
                'part_name' => trim($partName),
                'installed_at' => $when->toDateString(),
                'installed_by' => $user->id,

                // Copied, like every other name that ends up in a record.
                'installed_by_name' => $user->name,

                'counters_at_installation' => $aircraft->currentValues(),
                'carried_since_new' => $sinceNew,
                'carried_since_overhaul' => $sinceOverhaul,
                'overhauled_at' => $overhauled ? ($overhauledAt ?? $when->toDateString()) : null,
                'overhaul_reference' => $overhauled ? $overhaulReference : null,
            ]));
        });
    }

    /**
     * Taking a part off, freezing its counters where they stand.
     */
    public function remove(
        Installation $installation,
        User $user,
        string $reason,
        ?string $removedAt = null,
        bool $determinedServiceable = false,
    ): Installation {
        if (! $installation->isFitted()) {
            throw new InvalidArgumentException('That part has already been removed.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason for the removal is required.');
        }

        $installation->update([
            'removed_at' => $removedAt ?? now()->toDateString(),
            'removed_by' => $user->id,
            'counters_at_removal' => $installation->aircraft?->currentValues() ?? [],
            'removal_reason' => trim($reason),
        ]);

        $installation = $installation->fresh();

        /*
         * Announced, so the part can land on a shelf without anybody typing it
         * a second time. Whether it does depends on which modules are installed
         * and on the warehouse's own rules -- a replacement-interval part has no
         * way back, and the removal from the aircraft is right either way.
         */
        event(ComponentRemovedFromAircraft::from($installation, trim($reason), $determinedServiceable));

        return $installation;
    }

    /**
     * Nil on every counter -- what an overhaul does to the since-overhaul side.
     *
     * @return array<string, float>
     */
    private function zeroed(): array
    {
        $zeros = [];

        foreach (CounterKind::cases() as $kind) {
            $zeros[$kind->value] = 0.0;
        }

        return $zeros;
    }

    /**
     * The last time this serial number was fitted anywhere.
     *
     * Matched on the serial alone and across every aircraft, deliberately: a
     * component is the same component wherever it has been, and scoping this to
     * one aircraft would restart the life of every part that moved.
     */
    public function previousInstallation(string $serialNumber, ?int $excluding = null): ?Installation
    {
        return Installation::query()
            ->where('serial_number', $serialNumber)
            ->when($excluding !== null, fn ($q) => $q->whereKeyNot($excluding))
            ->orderByDesc('installed_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * What a previous installation had run up, by the time it came off.
     *
     * @return array<string, float>
     */
    private function accumulated(?Installation $previous, UsageBasis $basis): array
    {
        if ($previous === null) {
            return [];
        }

        $totals = [];

        foreach (CounterKind::cases() as $kind) {
            $used = $previous->usage($kind, $basis);

            if ($used !== null) {
                $totals[$kind->value] = $used;
            }
        }

        return $totals;
    }
}
