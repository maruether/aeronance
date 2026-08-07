<?php

declare(strict_types=1);

namespace App\Core\Filament\Resources\Users\Tables;

use App\Core\Filament\Resources\Users\UserResource;
use App\Core\Mail\Postman;
use App\Core\Mail\SendInvitation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('users.field.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label(__('users.field.email'))
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('roles.name')
                    ->label(__('users.field.roles'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('roles.'.$state))
                    ->placeholder('—'),

                // Shown here because it is what decides whether someone may
                // certify anything -- and an expired licence is easy to miss.
                TextColumn::make('valid_qualifications')
                    ->label(__('users.field.qualifications'))
                    ->state(fn (User $record): string => (string) $record->validQualifications()->count())
                    ->badge()
                    ->color(fn (User $record): string => $record->validQualifications()->count() > 0
                        ? 'success'
                        : 'gray'),

                IconColumn::make('is_active')
                    ->label(__('users.field.is_active'))
                    ->boolean(),

                /*
                 * Eine Sperre muss man SEHEN, ohne danach zu suchen. Sonst
                 * sitzt jemand am Telefon und rätselt, warum ein aktives
                 * Mitglied nicht hineinkommt.
                 */
                TextColumn::make('locked_at')
                    ->label(__('users.field.locked'))
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(fn (User $record): string => __('users.lock.since', [
                        'date' => $record->locked_at?->format('d.m.Y') ?? '',
                    ]))
                    ->tooltip(fn (User $record): ?string => $record->lock_reason)
                    ->placeholder('—'),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('users.field.is_active'))
                    ->default(true),

                /*
                 * ─────────────────────────────────────────────────────────────
                 * WER NOCH NIE DRIN WAR, und das ist keine Spielerei:
                 *
                 * Ein Konto ohne Passwort hat noch nie jemand benutzt (siehe
                 * LinkExternalIdentity -- neue Konten bekommen keins). Nach dem
                 * ersten Mitgliederabgleich sind das ALLE, und wer den
                 * Ueberblick behalten will, wer noch fehlt, braucht genau diese
                 * Liste.
                 *
                 * Ohne sie muesste jemand 394 Zeilen durchsehen, um die 40 zu
                 * finden, die ihre Einladung nie eingeloest haben.
                 * ─────────────────────────────────────────────────────────────
                 */
                TernaryFilter::make('never_activated')
                    ->label(__('users.filter.never_activated'))
                    ->placeholder(__('users.filter.all'))
                    ->trueLabel(__('users.filter.never_activated_true'))
                    ->falseLabel(__('users.filter.never_activated_false'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNull('password'),
                        false: fn (Builder $query): Builder => $query->whereNotNull('password'),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                /*
                 * Und wer gar nicht eingeladen werden KANN. Gemessen: 26 von
                 * 394 Mitgliedern haben in Vereinsflieger keine Mailadresse --
                 * ihr Konto traegt einen Platzhalter. Diese Menschen kommen nur
                 * ueber einen Administrator hinein, und dafuer muss man sie
                 * finden koennen.
                 */
                TernaryFilter::make('no_address')
                    ->label(__('users.filter.no_address'))
                    ->placeholder(__('users.filter.all'))
                    ->trueLabel(__('users.filter.no_address_true'))
                    ->falseLabel(__('users.filter.no_address_false'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('email', 'like', '%@invalid.local'),
                        false: fn (Builder $query): Builder => $query->where('email', 'not like', '%@invalid.local'),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                TernaryFilter::make('locked')
                    ->label(__('users.filter.locked'))
                    ->placeholder(__('users.filter.all'))
                    ->trueLabel(__('users.filter.locked_true'))
                    ->falseLabel(__('users.filter.locked_false'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('locked_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('locked_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                SelectFilter::make('roles')
                    ->label(__('users.field.roles'))
                    ->relationship('roles', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record): string => __('roles.'.$record->name)),
            ])
            ->recordActions([
                EditAction::make(),

                self::lockAction(),
                self::unlockAction(),

                /*
                 * ─────────────────────────────────────────────────────────────
                 * DER KNOPF, DER EIN KONTO BENUTZBAR MACHT.
                 *
                 * Vorgabe: „eine anmeldung über den VF geht nicht" -- also
                 * braucht JEDES Konto ein eigenes Passwort, auch die aus dem
                 * Mitgliederabgleich. Ohne diesen Weg kaeme niemand hinein
                 * ausser ueber einen Administrator, der Passwoerter von Hand
                 * verteilt und weitergibt.
                 *
                 * Sichtbar nur, wenn Mail wirklich rausgeht: Ein Knopf, der
                 * still nichts tut, ist schlimmer als keiner.
                 * ─────────────────────────────────────────────────────────────
                 */
                Action::make('invite')
                    ->label(__('users.action.invite'))
                    ->icon('heroicon-o-envelope')
                    ->color('gray')
                    ->visible(fn (): bool => Postman::canSend())
                    ->requiresConfirmation()
                    ->modalDescription(__('users.action.invite_confirm'))
                    ->action(function (User $record): void {
                        $ergebnis = app(SendInvitation::class)->handle($record);

                        $erfolg = $ergebnis === SendInvitation::SENT;

                        Notification::make()
                            ->title(__('mail.invitation.'.$ergebnis, ['name' => $record->name]))
                            ->{$erfolg ? 'success' : 'danger'}()
                            ->send();
                    }),
            ]);
    }

    /**
     * Der Not-Aus.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * die Frage stand am Anfang: Ein Konto aus einem Provider lässt sich
     * hier nicht mehr abschalten, weil der nächtliche Abgleich „Aktiv" führt.
     * Für den geordneten Fall ist das richtig — wer austritt, verschwindet über
     * den Provider.
     *
     * FÜR DEN UNGEORDNETEN NICHT. Ein Streit, ein verlorenes Notebook, ein
     * Verdacht: Dann muss der Zugang in dieser Minute weg sein, ohne dass
     * jemand erst im Mitgliederverwaltungssystem etwas ändern darf oder kann.
     * Und er muss weg BLEIBEN — auch um 2 Uhr morgens, wenn der Abgleich meldet,
     * dass die Person selbstverständlich noch Mitglied ist.
     *
     * Deshalb sperrt das nicht `is_active`, sondern setzt eine eigene Sperre,
     * die kein Abgleich anfasst. Siehe User::hasAccess().
     * ─────────────────────────────────────────────────────────────────────────
     */
    private static function lockAction(): Action
    {
        return Action::make('lock')
            ->label(__('users.action.lock'))
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn (User $record): bool => ! $record->isLocked() && UserResource::canLock($record))
            ->modalHeading(fn (User $record): string => __('users.action.lock_heading', ['name' => $record->name]))
            ->modalDescription(__('users.action.lock_description'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('users.field.lock_reason'))
                    ->required()
                    ->rows(3)
                    ->maxLength(255)
                    ->helperText(__('users.help.lock_reason')),
            ])
            ->action(function (User $record, array $data): void {
                $record->lockAccess((string) $data['reason'], auth()->user());

                Notification::make()
                    ->danger()
                    ->title(__('users.notification.locked', ['name' => $record->name]))
                    ->body(__('users.notification.locked_body'))
                    ->persistent()
                    ->send();
            });
    }

    private static function unlockAction(): Action
    {
        return Action::make('unlock')
            ->label(__('users.action.unlock'))
            ->icon('heroicon-o-lock-open')
            ->color('warning')
            ->visible(fn (User $record): bool => $record->isLocked() && UserResource::canLock($record))
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => __('users.action.unlock_heading', ['name' => $record->name]))
            /*
             * Der Grund der Sperre steht in der Rückfrage. Wer sie aufhebt,
             * soll vorher lesen, warum sie da war -- und nicht bloß bestätigen,
             * dass er sie wegklickt.
             */
            ->modalDescription(fn (User $record): string => __('users.action.unlock_description', [
                'reason' => $record->lock_reason ?? '—',
            ]))
            ->action(function (User $record): void {
                $record->unlockAccess(auth()->user());

                Notification::make()
                    ->success()
                    ->title(__('users.notification.unlocked', ['name' => $record->name]))
                    ->send();
            });
    }
}
