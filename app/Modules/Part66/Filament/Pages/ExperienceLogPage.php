<?php

declare(strict_types=1);

namespace App\Modules\Part66\Filament\Pages;

use App\Models\User;
use App\Modules\Part66\Permissions;
use App\Modules\Part66\Support\ExperienceEntry;
use App\Modules\Part66\Support\ExperienceLog;
use App\Modules\Part66\Support\RecencyReport;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * Somebody's experience log on screen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * NO PERMISSION IS NEEDED TO SEE YOUR OWN.
 *
 * Unusual for this project, and deliberate: it is a record of how somebody spent
 * their own Saturdays, and having to be granted access to that would be absurd.
 * What needs a permission is reading somebody ELSE'S -- an experience log is
 * personal data, and the workshop manager who has to confirm it is a different
 * case from the member who is curious.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ExperienceLogPage extends Page
{
    protected string $view = 'part66.filament.pages.log';

    protected static ?string $slug = 'logbuch';

    protected static ?int $navigationSort = 10;

    /** Whose log is shown. Own by default. */
    public ?int $personId = null;

    public ?string $from = null;

    public ?string $to = null;

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.part66');
    }

    public static function getNavigationLabel(): string
    {
        return __('part66.log.title');
    }

    public function getTitle(): string|Htmlable
    {
        return $this->person()->is(auth()->user())
            ? __('part66.log.mine')
            : sprintf('%s — %s', __('part66.log.title'), $this->person()->name);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('part66.log.subheading');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedBookOpen;
    }

    /**
     * Everybody. The gate is on WHOSE log, not on whether logs exist.
     */
    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function mount(): void
    {
        $this->personId = auth()->id();
    }

    /**
     * The person whose log this is.
     *
     * Falls back to the viewer rather than refusing, so a tampered query
     * parameter shows your own log instead of an error -- and never somebody
     * else's.
     */
    public function person(): User
    {
        if ($this->personId === null || $this->personId === auth()->id()) {
            return auth()->user();
        }

        if (! (auth()->user()?->can(Permissions::LOGS_VIEW_ALL) ?? false)) {
            return auth()->user();
        }

        return User::find($this->personId) ?? auth()->user();
    }

    public function mayViewOthers(): bool
    {
        return auth()->user()?->can(Permissions::LOGS_VIEW_ALL) ?? false;
    }

    /** @return array<int, string> */
    public function peopleOptions(): array
    {
        if (! $this->mayViewOthers()) {
            return [];
        }

        return User::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return Collection<int, ExperienceEntry> */
    public function entries(): Collection
    {
        return app(ExperienceLog::class)->for($this->person(), $this->from, $this->to);
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $log = app(ExperienceLog::class);
        $entries = $this->entries();
        $span = $log->span($entries);

        return [
            'entries' => $entries->count(),
            'hours' => round($entries->sum(fn ($e): int => $e->minutes) / 60, 2),
            'by_activity' => $log->hoursByActivity($entries),
            'by_model' => $log->hoursByModel($entries),
            'by_participation' => $log->hoursByParticipation($entries),
            'certifications' => $log->certificationCountBy($this->person(), $this->from, $this->to),
            'releases' => $log->releasesBy($this->person(), $this->from, $this->to)->count(),
            'reviews' => $log->reviewsBy($this->person(), $this->from, $this->to)->count(),
            'from' => $span['from'],
            'to' => $span['to'],
        ];
    }

    /** @return array<string, mixed> */
    public function recency(): array
    {
        return app(RecencyReport::class)->for($this->person());
    }

    /** @return list<string> */
    public function recencyNotes(): array
    {
        $service = app(RecencyReport::class);

        return $service->observations($service->for($this->person()));
    }

    public function printUrl(): string
    {
        return route('part66.log', array_filter([
            'person' => $this->person()->id,
            'from' => $this->from,
            'to' => $this->to,
        ]));
    }
}
