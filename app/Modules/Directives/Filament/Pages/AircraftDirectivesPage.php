<?php

declare(strict_types=1);

namespace App\Modules\Directives\Filament\Pages;

use App\Modules\Directives\Enums\ComplianceState;
use App\Modules\Directives\Models\Directive;
use App\Modules\Directives\Models\DirectiveApplication;
use App\Modules\Directives\Permissions;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AircraftType;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * The list from the aircraft's side -- the view somebody actually works down.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The directive resource lists LINES and shows which aircraft they touch. This
 * page is the transpose, and it is the one an annual inspection needs: pick the
 * aircraft, work down every line that concerns it, answer each.
 *
 * Both views are the same two tables read from different ends. Neither is
 * redundant: an import wants the line-centric view ("which aircraft does this new
 * LTA hit?"), an inspection wants this one ("what is still open on D-KABC?").
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class AircraftDirectivesPage extends Page
{
    protected string $view = 'directives.filament.pages.aircraft';

    protected static ?string $slug = 'lta-uebersicht';

    protected static ?int $navigationSort = 20;

    public ?int $aircraftId = null;

    /** Whether to hide lines that are settled. */
    public bool $onlyOutstanding = false;

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.fleet');
    }

    public static function getNavigationLabel(): string
    {
        return __('directives.overview.title');
    }

    public function getTitle(): string|Htmlable
    {
        $aircraft = $this->aircraft();

        return $aircraft === null
            ? __('directives.overview.title')
            : sprintf('%s — %s', __('directives.overview.title'), $aircraft->registration);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('directives.overview.subheading');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedListBullet;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permissions::DIRECTIVES_VIEW) ?? false;
    }

    public function mount(): void
    {
        $this->aircraftId = Aircraft::where('is_active', true)->orderBy('registration')->value('id');
    }

    public function aircraft(): ?Aircraft
    {
        return $this->aircraftId !== null ? Aircraft::find($this->aircraftId) : null;
    }

    /**
     * The type behind the selected aircraft, where one is assigned.
     *
     * Read from the fleet, which this module already depends on. The direction
     * matters: who looks after a type is a property of the TYPE and is kept
     * there -- this page only asks, and the fleet never asks back.
     */
    public function aircraftType(): ?AircraftType
    {
        return $this->aircraft()?->aircraftType;
    }

    /**
     * Whether this aircraft's type has nobody looking after it any more.
     *
     * The one question the tally below cannot answer. Everything else on this
     * page counts lines; this says whether an empty count means "nothing new was
     * published" or "there is nobody left to publish anything".
     */
    public function isOrphaned(): bool
    {
        return $this->aircraftType()?->isOrphaned() ?? false;
    }

    /** @return array<int, string> */
    public function aircraftOptions(): array
    {
        return Aircraft::where('is_active', true)
            ->orderBy('registration')
            ->get()
            ->mapWithKeys(fn (Aircraft $a): array => [$a->id => $a->registration.' — '.$a->model])
            ->all();
    }

    /**
     * Every line that concerns this aircraft, with its answer.
     *
     * Built from the union of "directives that may apply" and "assessments that
     * exist", because both halves can hold something the other does not: a newly
     * imported LTA has no assessment yet, and an assessment can outlive
     * applicability when a component is removed. Dropping either would hide a
     * line somebody needs to see.
     *
     * @return Collection<int, array{directive: Directive, application: ?DirectiveApplication}>
     */
    public function lines(): Collection
    {
        $aircraft = $this->aircraft();

        if ($aircraft === null) {
            return collect();
        }

        $applications = DirectiveApplication::query()
            ->where('aircraft_id', $aircraft->id)
            ->with('directive')
            ->get()
            ->keyBy('directive_id');

        $candidates = Directive::query()
            ->current()
            ->orderByDesc('comply_before')
            ->orderByDesc('issued_at')
            ->get()
            ->filter(fn (Directive $d): bool => $d->mayApplyTo($aircraft)
                || $applications->has($d->id));

        return $candidates
            ->map(fn (Directive $d): array => [
                'directive' => $d,
                'application' => $applications->get($d->id),
            ])
            ->when($this->onlyOutstanding, fn (Collection $c): Collection => $c->filter(
                fn (array $line): bool => $line['application'] === null
                    || $line['application']->isOutstanding(),
            ))
            ->values();
    }

    /** @return array<string, int> */
    public function tally(): array
    {
        $lines = $this->lines();

        return [
            'total' => $lines->count(),
            'unassessed' => $lines->filter(fn (array $l): bool => $l['application'] === null
                || $l['application']->state === ComplianceState::Open)->count(),
            'outstanding' => $lines->filter(fn (array $l): bool => $l['application'] === null
                || $l['application']->isOutstanding())->count(),
            'blocking' => $lines->filter(fn (array $l): bool => $l['application'] === null
                || $l['application']->isBlocking())->count(),
        ];
    }

    public function mayAssess(): bool
    {
        return auth()->user()?->can(Permissions::DIRECTIVES_ASSESS) ?? false;
    }

    public function stateOf(?DirectiveApplication $application): ComplianceState
    {
        return $application?->state ?? ComplianceState::Open;
    }

    /**
     * Die Druckansicht -- oder null, solange kein Luftfahrzeug gewaehlt ist.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * VORHER STAND HIER route(...) OHNE PRUEFUNG, und das ist der Zustand beim
     * ERSTEN OEFFNEN: Es ist nichts gewaehlt, aircraftId ist null, und Laravel
     * wirft "Missing required parameter". Die Seite war damit unbenutzbar,
     * bevor man sie ueberhaupt bedienen konnte.
     *
     * Gefunden hat das der erste Test, der diese Seite je aufgerufen hat --
     * nicht ein Mensch, der davorsass.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function printUrl(): ?string
    {
        if ($this->aircraftId === null) {
            return null;
        }

        return route('directives.overview', ['aircraft' => $this->aircraftId]);
    }
}
