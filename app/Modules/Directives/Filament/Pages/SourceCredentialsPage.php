<?php

declare(strict_types=1);

namespace App\Modules\Directives\Filament\Pages;

use App\Modules\Directives\Permissions;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\SourceCredentials;
use App\Modules\Directives\Sources\SourceRegistry;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Throwable;

/**
 * Where a club types the login a gated manufacturer requires.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Schempp-Hirth publishes its list only to customers, and more manufacturers
 * will. Until now the only way in was the .env, which suits a developer and a
 * Docker secret and suits nobody on a club committee -- Vorgabe: "der schempp
 * treiber (bzw alle die einen login brauchen) benötigen später einen zugang
 * durch den nutzer."
 *
 * THE PASSWORD IS NEVER SHOWN BACK. Not masked, not prefilled -- the field is
 * empty on every visit, and leaving it empty keeps whatever is stored. A panel
 * that renders a password into HTML has put it in a browser cache, a screen
 * share and a screenshot, and the only defence against that is not to do it.
 *
 * Where the environment supplies a login it says so and the fields are locked:
 * editing a database value that the environment silently overrides is a way to
 * spend an afternoon.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SourceCredentialsPage extends Page
{
    protected string $view = 'directives.filament.pages.credentials';

    protected static ?string $slug = 'hersteller-zugaenge';

    protected static ?int $navigationSort = 30;

    /** @var array<string, string> profile => username, as typed */
    public array $usernames = [];

    /** @var array<string, string> profile => new password, never prefilled */
    public array $passwords = [];

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.fleet');
    }

    public static function getNavigationLabel(): string
    {
        return __('directives.credentials.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('directives.credentials.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('directives.credentials.subheading');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedKey;
    }

    /**
     * Setting a manufacturer login is administration of the module, not an
     * airworthiness judgement -- the same permission that imports a list.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permissions::DIRECTIVES_MANAGE) ?? false;
    }

    /**
     * The page is only worth showing when some source actually needs a login.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess() && self::gatedSources() !== [];
    }

    public function mount(): void
    {
        $credentials = app(SourceCredentials::class);

        foreach (array_keys(self::gatedSources()) as $profile) {
            // The username may be shown -- it is not the secret, and seeing it
            // is how somebody notices the wrong account is stored.
            $this->usernames[$profile] = (string) ($credentials->username($profile) ?? '');
            $this->passwords[$profile] = '';
        }
    }

    /**
     * One entry per auth profile, with the sources that use it.
     *
     * Keyed by PROFILE rather than by source: DG's five Lindner files would each
     * ask for the same login if Lindner ever gated theirs, and asking somebody to
     * type one password five times is how three of them end up wrong.
     *
     * A profile counts as OPTIONAL only when every source behind it says so --
     * one gated source in the group, and the login stops being an offer.
     *
     * @return array<string, array{labels: list<string>, optional: bool}>
     */
    public static function gatedSources(): array
    {
        $profiles = [];

        foreach (app(SourceRegistry::class)->all() as $source) {
            if (! $source instanceof ConfiguredSource) {
                continue;
            }

            $profile = $source->spec()->authProfile;

            if ($profile === null) {
                continue;
            }

            $profiles[$profile] ??= ['labels' => [], 'optional' => true];
            $profiles[$profile]['labels'][] = $source->label();
            $profiles[$profile]['optional'] = $profiles[$profile]['optional']
                && $source->spec()->loginOptional;
        }

        ksort($profiles);

        return $profiles;
    }

    /** @return array<string, array{labels: list<string>, optional: bool, from_env: bool, set: bool}> */
    public function rows(): array
    {
        $credentials = app(SourceCredentials::class);
        $rows = [];

        foreach (self::gatedSources() as $profile => $entry) {
            $rows[$profile] = [
                'labels' => $entry['labels'],
                'optional' => $entry['optional'],
                'from_env' => $credentials->isFromEnvironment($profile),
                'set' => $credentials->has($profile),
            ];
        }

        return $rows;
    }

    public function save(string $profile): void
    {
        abort_unless(self::canAccess(), 403);

        // Only a profile some spec actually asks for -- the profile name arrives
        // from the browser, and a request naming an arbitrary one must not write
        // a row for it.
        abort_unless(array_key_exists($profile, self::gatedSources()), 404);

        $credentials = app(SourceCredentials::class);

        if ($credentials->isFromEnvironment($profile)) {
            Notification::make()
                ->warning()
                ->title(__('directives.credentials.from_env_title'))
                ->body(__('directives.credentials.from_env_body'))
                ->send();

            return;
        }

        $username = trim($this->usernames[$profile] ?? '');
        $password = (string) ($this->passwords[$profile] ?? '');

        if ($username === '') {
            Notification::make()->danger()->title(__('directives.credentials.needs_user'))->send();

            return;
        }

        /*
         * An empty password means "keep the stored one" -- but where nothing is
         * stored, there is nothing to keep, and store() would return without
         * writing. Saying "gespeichert" to that would be a lie with a delay:
         * for an optional login the fetch silently stays anonymous, and the
         * club believes it reads as a subscriber. Review found exactly this.
         */
        if ($password === '' && ! $credentials->has($profile)) {
            Notification::make()->danger()->title(__('directives.credentials.needs_password'))->send();

            return;
        }

        $credentials->store($profile, $username, $password !== '' ? $password : null, auth()->id());

        // Cleared immediately: a typed password has no business surviving in the
        // component's state, where a later render would put it back on the wire.
        $this->passwords[$profile] = '';

        Notification::make()->success()->title(__('directives.credentials.saved'))->send();
    }

    public function forget(string $profile): void
    {
        abort_unless(self::canAccess(), 403);
        abort_unless(array_key_exists($profile, self::gatedSources()), 404);

        app(SourceCredentials::class)->forget($profile);

        $this->usernames[$profile] = '';
        $this->passwords[$profile] = '';

        Notification::make()->success()->title(__('directives.credentials.removed'))->send();
    }

    /**
     * Tries the login and says what happened.
     *
     * The reason this button exists: a wrong password otherwise surfaces days
     * later as "the weekly fetch found nothing", which reads like a manufacturer
     * with nothing new. Checking it while somebody is looking is worth one
     * request.
     */
    public function test(string $profile): void
    {
        abort_unless(self::canAccess(), 403);
        abort_unless(array_key_exists($profile, self::gatedSources()), 404);

        $source = null;

        foreach (app(SourceRegistry::class)->all() as $candidate) {
            if ($candidate instanceof ConfiguredSource && $candidate->spec()->authProfile === $profile) {
                $source = $candidate;

                break;
            }
        }

        if ($source === null) {
            return;
        }

        try {
            $rows = $source->fetch();

            Notification::make()
                ->success()
                ->title(__('directives.credentials.test_ok', ['count' => count($rows)]))
                ->send();
        } catch (Throwable $e) {
            // The driver's own message is the useful part -- it distinguishes a
            // refused login from a manufacturer's firewall blocking the request.
            Notification::make()
                ->danger()
                ->title(__('directives.credentials.test_failed'))
                ->body($e->getMessage())
                ->persistent()
                ->send();
        }
    }
}
