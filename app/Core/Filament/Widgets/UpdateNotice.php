<?php

declare(strict_types=1);

namespace App\Core\Filament\Widgets;

use App\Core\Access\CorePermissions;
use App\Core\Updates\ReleaseCheck;
use App\Core\Version;
use Filament\Widgets\Widget;

/**
 * „Es gibt eine neuere Fassung."
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE HÄLFTE, DIE GEFEHLT HAT. Die Prüfung lief seit heute Abend jede Nacht und
 * schrieb ihr Ergebnis in den Zwischenspeicher — gesehen hat es nie jemand.
 * Ein Update-Hinweis, den kein Mensch zu Gesicht bekommt, ist keiner, und die
 * Konsole schaut in einem Verein niemand an.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ER FRAGT NICHT NACH, ER LIEST NUR (siehe ReleaseCheck::known()).
 *
 * Würde er selbst bei GitHub anfragen, müsste die erste Seitenanzeige nach
 * einem Neustart darauf warten — und wenn GitHub gerade nicht antwortet, bis
 * zur Zeitüberschreitung. Eine Werkstattverwaltung, die langsam startet, weil
 * sie nach Updates schaut, hat die Verhältnisse verkehrt. Gefüllt wird der
 * Zwischenspeicher vom nächtlichen Lauf.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * NUR FÜR DIE, DIE ETWAS DAMIT ANFANGEN KÖNNEN.
 *
 * Ein Update spielt ein, wer an den Server kommt — das ist dieselbe Person, die
 * Einstellungen verwaltet. Einem Mechaniker eine Meldung hinzustellen, gegen
 * die er nichts tun kann, ist Lärm: Er sieht sie jeden Tag, kann sie nicht
 * abstellen und lernt, Meldungen zu übersehen. Genau das darf in einem
 * Werkzeug nicht passieren, in dem andere Meldungen wichtig sind.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class UpdateNotice extends Widget
{
    protected string $view = 'core.filament.widgets.update-notice';

    /** Ganz oben — sonst steht die Meldung unter dem, was jemand eigentlich sucht. */
    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user?->can(CorePermissions::SETTINGS_MANAGE)) {
            return false;
        }

        return app(ReleaseCheck::class)->updateKnown();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $check = app(ReleaseCheck::class);

        return [
            'installed' => Version::label(),
            'latest' => $check->known(),
            'url' => $check->releasesUrl(),
        ];
    }
}
