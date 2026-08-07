<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Pages;

use App\Modules\Fleet\Actions\CollectDueItems;
use App\Modules\Fleet\Permissions;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * What is due, and what is already past.
 *
 * The screen the whole module is for. Everything else here records; this one
 * answers.
 */
final class DuePage extends Page
{
    protected string $view = 'fleet.filament.pages.due';

    protected static ?string $slug = 'faelligkeiten';

    protected static ?int $navigationSort = 5;

    public int $window = 60;

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.fleet');
    }

    public static function getNavigationLabel(): string
    {
        return __('fleet.due.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('fleet.due.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('fleet.due.subheading');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedBellAlert;
    }

    /**
     * How much trouble there is, on the menu item.
     *
     * Only the overdue ones. A badge counting everything due within two months
     * is a badge that is never zero, and a badge that is never zero stops being
     * read.
     */
    public static function getNavigationBadge(): ?string
    {
        $overdue = app(CollectDueItems::class)->within(60)->where('overdue', true)->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permissions::FLEET_VIEW) ?? false;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function items(): Collection
    {
        return app(CollectDueItems::class)->within($this->window);
    }
}
