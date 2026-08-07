<?php

declare(strict_types=1);

namespace App\Core\Filament\Pages;

use App\Core\Access\CorePermissions;
use App\Core\Modules\CapabilityWarning;
use App\Core\Modules\ModuleManager;
use App\Core\Modules\ModuleRegistry;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Switching modules on and off.
 *
 * The point of this screen is not the switch -- it is the explanation. CLAUDE.md
 * asks that the operator be told WHY something has to come along or cannot be
 * combined, and the resolver already produces those reasons, so the page's job
 * is to show them before anything happens rather than to report an error after.
 */
final class ManageModules extends Page
{
    protected string $view = 'core.filament.pages.manage-modules';

    protected static ?int $navigationSort = 90;

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.system');
    }

    public static function getNavigationLabel(): string
    {
        return __('modules.page.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('modules.page.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('modules.page.subheading');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return 'heroicon-o-squares-2x2';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(CorePermissions::MODULES_MANAGE) ?? false;
    }

    /**
     * Everything the view needs, already resolved.
     *
     * @return list<array<string, mixed>>
     */
    public function getModuleRows(): array
    {
        $registry = app(ModuleRegistry::class);
        $manager = app(ModuleManager::class);

        $rows = [];

        foreach ($registry->all() as $name => $module) {
            $manifest = $module->manifest();
            $isEnabled = $manager->isEnabled($name);

            $decision = $isEnabled
                ? $manager->canDisable($name)
                : $manager->canEnable($name);

            $rows[] = [
                'name' => $name,
                'title' => $manifest->title,
                'description' => $manifest->description,
                'version' => $manifest->version,
                'enabled' => $isEnabled,
                'requires' => array_map(
                    static fn (string $r): string => $registry->manifest($r)->title,
                    $manifest->requires,
                ),
                'blockedBy' => $decision->blockedBy,
                'alsoAffects' => array_map(
                    static fn (string $r): string => $registry->manifest($r)->title,
                    $decision->alsoAffects,
                ),
                'warnings' => array_map(
                    static fn (CapabilityWarning $w): string => __('modules.warning.duplicate_capability', [
                        'capability' => __('modules.capability.'.$w->capability),
                        'modules' => implode(', ', $w->moduleTitles),
                    ]),
                    $decision->warnings,
                ),
                'canToggle' => $decision->allowed,
            ];
        }

        return $rows;
    }

    public function enableModule(string $name): void
    {
        $manager = app(ModuleManager::class);
        $decision = $manager->canEnable($name);

        if ($decision->isRefused()) {
            $this->refuse($decision->blockedBy);

            return;
        }

        try {
            $switched = $manager->enable($name);
        } catch (Throwable $e) {
            $this->refuse([$e->getMessage()]);

            return;
        }

        $this->rebuildPanelCaches();

        $notification = Notification::make()
            ->success()
            ->title(__('modules.notification.enabled'));

        $extra = array_values(array_diff($switched, [$name]));

        if ($extra !== []) {
            $notification->body(__('modules.notification.also_enabled', [
                'modules' => implode(', ', array_map(
                    static fn (string $m): string => app(ModuleRegistry::class)->manifest($m)->title,
                    $extra,
                )),
            ]));
        }

        $notification->send();

        foreach ($decision->warnings as $warning) {
            Notification::make()
                ->warning()
                ->title(__('modules.notification.warning_title'))
                ->body(__('modules.warning.duplicate_capability', [
                    'capability' => __('modules.capability.'.$warning->capability),
                    'modules' => implode(', ', $warning->moduleTitles),
                ]))
                ->persistent()
                ->send();
        }
    }

    public function disableModule(string $name): void
    {
        $manager = app(ModuleManager::class);
        $decision = $manager->canDisable($name);

        if ($decision->isRefused()) {
            $this->refuse($decision->blockedBy);

            return;
        }

        try {
            $manager->disable($name);
        } catch (Throwable $e) {
            $this->refuse([$e->getMessage()]);

            return;
        }

        $this->rebuildPanelCaches();

        Notification::make()
            ->success()
            ->title(__('modules.notification.disabled'))
            ->body(__('modules.notification.data_kept'))
            ->send();
    }

    /**
     * @param  list<string>  $reasons
     */
    private function refuse(array $reasons): void
    {
        Notification::make()
            ->danger()
            ->title(__('modules.notification.refused'))
            ->body(implode(' ', $reasons))
            ->persistent()
            ->send();
    }

    /**
     * The panel was built from the module set as it was a moment ago.
     *
     * Without this the newly enabled module's screens would only appear after
     * the next deployment -- and a disabled module's routes would linger, which
     * matters more.
     */
    private function rebuildPanelCaches(): void
    {
        try {
            Artisan::call('filament:clear-cached-components');
            Artisan::call('route:clear');
        } catch (Throwable) {
            // Caches that cannot be cleared are a nuisance, not a reason to
            // leave the module in a half-switched state.
        }
    }
}
