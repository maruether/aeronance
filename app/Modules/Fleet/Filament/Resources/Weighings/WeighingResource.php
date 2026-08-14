<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\Weighings;

use App\Modules\Fleet\Actions\SignOffWeighing;
use App\Modules\Fleet\Enums\WeighingKind;
use App\Modules\Fleet\Filament\Resources\Weighings\Pages\CreateWeighing;
use App\Modules\Fleet\Filament\Resources\Weighings\Pages\EditWeighing;
use App\Modules\Fleet\Filament\Resources\Weighings\Pages\ListWeighings;
use App\Modules\Fleet\Filament\Resources\Weighings\Schemas\WeighingForm;
use App\Modules\Fleet\Models\Weighing;
use App\Modules\Fleet\Permissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

/**
 * Weighing reports.
 *
 * Kept as a resource of its own rather than an action on the aircraft, unlike
 * the review and the pilot-owner listing: a weighing is a document somebody
 * sits down and fills in over a quarter of an hour, not something ticked off in
 * passing, and it wants a page with room on it.
 */
final class WeighingResource extends Resource
{
    protected static ?string $model = Weighing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 15;

    public static function form(Schema $schema): Schema
    {
        return WeighingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('weighed_at', 'desc')
            ->columns([
                TextColumn::make('aircraft.registration')
                    ->label(__('fleet.aircraft.singular'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('weighed_at')
                    ->label(__('fleet.weighing.field.weighed_at'))
                    ->date('d.m.Y')
                    ->sortable(),

                TextColumn::make('kind')
                    ->label(__('fleet.weighing.singular'))
                    ->badge()
                    ->formatStateUsing(fn (WeighingKind $state): string => $state->label()),

                TextColumn::make('empty_mass_kg')
                    ->label(__('fleet.weighing.field.empty_mass'))
                    ->alignEnd()
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? '—'
                        : number_format((float) $state, 2, ',', '.').' kg'),

                TextColumn::make('empty_cg_mm')
                    ->label(__('fleet.weighing.field.empty_cg'))
                    ->alignEnd()
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? '—'
                        : number_format((float) $state, 1, ',', '.').' mm'),

                TextColumn::make('signed_off_at')
                    ->label(__('fleet.weighing.signed_off'))
                    ->badge()
                    ->state(fn (Weighing $r): string => $r->isSignedOff()
                        ? $r->signed_off_at->format('d.m.Y')
                        : __('fleet.weighing.draft'))
                    ->color(fn (Weighing $r): string => $r->isSignedOff() ? 'success' : 'gray')
                    ->description(fn (Weighing $r): ?string => $r->signed_off_by_name),

                TextColumn::make('valid_until')
                    ->label(__('fleet.weighing.field.valid_until'))
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (Weighing $r): string => $r->isValid() ? 'success' : 'danger'),
            ])
            ->recordActions([
                EditAction::make(),

                // Sichtbar nur, solange nichts unterschrieben ist -- canDelete
                // entscheidet, das Modell hält dagegen, falls doch jemand
                // anderes fragt.
                DeleteAction::make(),

                /*
                 * "Speichern und drucken" -- the act that closes the sheet.
                 *
                 * Calculation and signature happen in one transaction, so no
                 * row can change between the arithmetic and the name under it.
                 * Confirmed, because it cannot be taken back: a correction is a
                 * new weighing, which is what happens on paper too.
                 */
                Action::make('signOff')
                    ->label(__('fleet.weighing.sign_off'))
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('fleet.weighing.sign_off_warning'))
                    ->visible(fn (Weighing $r): bool => ! $r->isSignedOff()
                        && (auth()->user()?->can(Permissions::REVIEWS_RECORD) ?? false))
                    ->action(function (Weighing $record): void {
                        try {
                            $result = app(SignOffWeighing::class)->handle($record, auth()->user());
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('fleet.weighing.signed_off_now'))
                            ->body($result->isAcceptable()
                                ? __('fleet.weighing.in_range')
                                : implode(' ', $result->findings))
                            ->persistent()
                            ->send();
                    })
                    ->after(fn (Weighing $record) => redirect()->away(
                        route('fleet.weighing', ['weighing' => $record]),
                    )),

                Action::make('print')
                    ->label(__('fleet.print.label'))
                    ->icon('heroicon-o-printer')
                    ->url(fn (Weighing $record): string => route('fleet.weighing', ['weighing' => $record]))
                    ->openUrlInNewTab(),
            ]);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.fleet');
    }

    public static function getNavigationLabel(): string
    {
        return __('fleet.weighing.plural');
    }

    public static function getModelLabel(): string
    {
        return __('fleet.weighing.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fleet.weighing.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::FLEET_VIEW) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(Permissions::REVIEWS_RECORD) ?? false;
    }

    /**
     * A signed-off sheet is not editable, by anyone, ever.
     *
     * Enforced in the model as well -- this only stops the button appearing, and
     * a rule that lives in a button is not a rule.
     *
     * @param  Weighing  $record
     */
    public static function canEdit($record): bool
    {
        return ! $record->isSignedOff()
            && (auth()->user()?->can(Permissions::REVIEWS_RECORD) ?? false);
    }

    /**
     * Eine NICHT abgezeichnete Wägung darf weg.
     *
     * Feldtest: "nicht abgeschlossene wägeberichte müssen löschbar sein. Eine
     * fälschlicherweise angelegte wägung müllt sonst die liste voll." Genau
     * dafür ist Platz: Das Modell schützt seit jeher nur die UNTERSCHRIEBENE
     * Wägung (deleting-Wache), verboten hat es hier bloß die Oberfläche --
     * pauschal, und damit auch den Irrtum von vor zwei Minuten.
     *
     * Weiches Löschen (SoftDeletes): Die Zeile verschwindet aus der Liste,
     * nicht aus der Datenbank. Ein abgezeichnetes Blatt bleibt unantastbar.
     *
     * @param  Weighing  $record
     */
    public static function canDelete($record): bool
    {
        return ! $record->isSignedOff()
            && (auth()->user()?->can(Permissions::REVIEWS_RECORD) ?? false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWeighings::route('/'),
            'create' => CreateWeighing::route('/neu'),
            'edit' => EditWeighing::route('/{record}/bearbeiten'),
        ];
    }
}
