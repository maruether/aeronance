<?php

declare(strict_types=1);

namespace App\Modules\Vereinsflieger\Filament\Pages;

use App\Core\Access\CorePermissions;
use App\Modules\Vereinsflieger\Actions\RememberMemberStatuses;
use App\Modules\Vereinsflieger\Enums\MemberStatusHandling;
use App\Modules\Vereinsflieger\Models\MemberStatus;
use App\Modules\Vereinsflieger\VereinsfliegerProvider;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Was aus jedem Vereinsflieger-Mitgliedsstatus werden soll.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „bei memberstatus interessieren mich initial nur 1 und 2. alle
 * anderen soll das modul initial abrufen und den admin entscheiden lassen was
 * damit passiert."
 *
 * Diese Seite ist die Entscheidung. Sie zeigt jeden gefundenen Status mit der
 * Zahl der Menschen dahinter -- wer entscheidet, ob ein Status Konten bekommt,
 * soll sehen, um wie viele es geht, BEVOR er entscheidet. In der
 * Referenzinstallation haengen an einem einzigen Status 229 Menschen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WARUM DAS HIER UND NICHT BEI DEN ROLLENZUORDNUNGEN LIEGT:
 *
 * Die Zuordnung beantwortet „welche Rolle bekommt jemand". Diese Seite
 * beantwortet die Frage davor -- „gibt es diesen Menschen ueberhaupt". Das ist
 * kein Rechtemodell, sondern eine Eigenheit von Vereinsflieger: Ein anderer
 * Anbieter hat keine Mitgliedsstatus. Deshalb gehoert sie ins Modul.
 *
 * Das Recht ist trotzdem core.roles.manage. Wer hier entscheidet, entscheidet
 * ueber Zugang -- dieselbe Sorte Eingriff, also derselbe Schluessel. Ein
 * eigenes Recht dafuer waere ein zweiter Schalter fuer dieselbe Tuer.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class MemberStatusesPage extends Page
{
    protected string $view = 'vereinsflieger.filament.pages.member-statuses';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'vereinsflieger-mitgliedsstatus';

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.people');
    }

    public static function getNavigationLabel(): string
    {
        return __('vereinsflieger.status.plural');
    }

    public function getTitle(): string|Htmlable
    {
        return __('vereinsflieger.status.plural');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('vereinsflieger.status.subheading');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedIdentification;
    }

    /**
     * Ein Abzeichen mit der Zahl der offenen Entscheidungen.
     *
     * Ohne das laege die Frage still auf einer Seite, die niemand aufruft --
     * und offene Entscheidungen heissen hier: Diese Menschen bekommen kein
     * Konto, und keiner weiss davon.
     */
    public static function getNavigationBadge(): ?string
    {
        $offen = MemberStatus::query()->whereNull('handling')->count();

        return $offen > 0 ? (string) $offen : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(CorePermissions::ROLES_MANAGE) ?? false;
    }

    /** @return Collection<int, MemberStatus> */
    public function statuses(): Collection
    {
        return MemberStatus::query()
            // Offene zuerst: Sie sind der Grund, warum jemand hier ist.
            ->orderByRaw('handling IS NOT NULL')
            ->orderByDesc('member_count')
            ->get();
    }

    /** @return array<string, string> */
    public function handlingOptions(): array
    {
        $auswahl = [];

        foreach (MemberStatusHandling::cases() as $fall) {
            $auswahl[$fall->value] = $fall->label();
        }

        return $auswahl;
    }

    public function decide(int $id, string $handling): void
    {
        abort_unless(auth()->user()?->can(CorePermissions::ROLES_MANAGE) ?? false, 403);

        $fall = MemberStatusHandling::tryFrom($handling);

        if ($fall === null) {
            return;
        }

        $status = MemberStatus::findOrFail($id);
        $status->handling = $fall;
        $status->save();

        Notification::make()
            ->title(__('vereinsflieger.status.decided', [
                'status' => $status->displayName(),
                'handling' => $fall->label(),
            ]))
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('discover')
                ->label(__('vereinsflieger.status.discover'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('vereinsflieger.status.discover_confirm'))
                ->action(function (): void {
                    abort_unless(auth()->user()?->can(CorePermissions::ROLES_MANAGE) ?? false, 403);

                    try {
                        $ergebnis = app(RememberMemberStatuses::class)
                            ->handle((new VereinsfliegerProvider)->memberStatuses());
                    } catch (Throwable $e) {
                        // Die Begruendung des Dienstes wird durchgereicht --
                        // ein freundliches "hat nicht geklappt" hat in diesem
                        // Projekt schon zwei Anmeldungen gekostet.
                        Notification::make()
                            ->title(__('vereinsflieger.status.discover_failed'))
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('vereinsflieger.status.discover_done', $ergebnis))
                        ->success()
                        ->send();
                }),
        ];
    }
}
