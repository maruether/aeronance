<?php

declare(strict_types=1);

namespace App\Modules\Inspection\Filament\Resources\IncomingInspections;

use App\Modules\Inspection\Filament\Resources\IncomingInspections\Pages\ListIncomingInspections;
use App\Modules\Inspection\Filament\Resources\IncomingInspections\Tables\IncomingInspectionsTable;
use App\Modules\Inspection\Models\IncomingInspection;
use App\Modules\Inspection\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Eingangsprüfungen — die Liste dessen, was angekommen ist.
 *
 * Die Zahl in der Navigation zählt die OFFENEN, und hier ist das anders als bei
 * den Bestellungen: Offene Eingangsprüfungen sind kein Normalzustand, sondern
 * Ware, die im Karton steht und nicht verwendet werden kann. Eine Zahl, die
 * dasteht, bis jemand hingeht, ist genau richtig.
 */
final class IncomingInspectionResource extends Resource
{
    protected static ?string $model = IncomingInspection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 16;

    protected static ?string $slug = 'eingangspruefungen';

    public static function table(Table $table): Table
    {
        return IncomingInspectionsTable::configure($table);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.inspection');
    }

    public static function getNavigationLabel(): string
    {
        return __('inspection.plural');
    }

    public static function getModelLabel(): string
    {
        return __('inspection.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('inspection.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $offen = IncomingInspection::query()->open()->count();

        return $offen > 0 ? (string) $offen : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::INSPECTION_VIEW) ?? false;
    }

    /**
     * Prüfungen entstehen beim Wareneingang, nicht von Hand.
     *
     * Eine Prüfung ohne Lieferung dahinter wäre ein Nachweis über nichts.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Es gibt kein Bearbeiten-Formular.
     *
     * Geprüft wird über die Aktion in der Liste, und die geht durch
     * CompleteIncomingInspection -- mit allem, was dort an Regeln hängt. Ein
     * zweiter Weg an derselben Zeile vorbei wäre genau der Weg, den jemand
     * nimmt, wenn der erste unbequem wird.
     *
     * @param  IncomingInspection  $record
     */
    public static function canEdit($record): bool
    {
        return false;
    }

    /**
     * Eine Eingangsprüfung wird nicht gelöscht.
     *
     * Sie ist der Nachweis, dass jemand hingesehen hat — und ein Nachweis, den
     * man wegräumen kann, ist keiner.
     *
     * @param  IncomingInspection  $record
     */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIncomingInspections::route('/'),
        ];
    }
}
