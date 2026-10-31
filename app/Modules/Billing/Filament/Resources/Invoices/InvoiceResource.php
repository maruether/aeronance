<?php

declare(strict_types=1);

namespace App\Modules\Billing\Filament\Resources\Invoices;

use App\Modules\Billing\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Modules\Billing\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Modules\Billing\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Modules\Billing\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Modules\Billing\Filament\Resources\Invoices\Schemas\InvoiceForm;
use App\Modules\Billing\Filament\Resources\Invoices\Schemas\InvoiceInfolist;
use App\Modules\Billing\Filament\Resources\Invoices\Tables\InvoicesTable;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Rechnungen und Gutschriften.
 *
 * Zwei Seiten für zwei Zustände: Der ENTWURF hat ein Formular (bearbeiten),
 * die GESTELLTE Rechnung eine Ansicht mit den Aktionen, die an einem Beleg
 * noch erlaubt sind -- drucken, versenden, Zahlung erfassen, stornieren.
 * canEdit sagt nein, sobald eine Nummer vergeben ist; das Modell sagt es
 * ein zweites Mal, falls jemand an der Oberfläche vorbeikommt.
 *
 * Die Zahl in der Navigation zählt ÜBERFÄLLIGE Rechnungen, nicht offene.
 */
final class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'rechnungen';

    protected static ?string $recordTitleAttribute = 'number';

    public static function form(Schema $schema): Schema
    {
        return InvoiceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return InvoiceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InvoicesTable::configure($table);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.billing');
    }

    public static function getNavigationLabel(): string
    {
        return __('billing.invoice.plural');
    }

    public static function getModelLabel(): string
    {
        return __('billing.invoice.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('billing.invoice.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $overdue = Invoice::query()->overdue()->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::VIEW) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(Permissions::MANAGE) ?? false;
    }

    /** @param  Invoice  $record */
    public static function canEdit($record): bool
    {
        return $record->isDraft() && self::canCreate();
    }

    /**
     * Nur Entwürfe. Eine gestellte Rechnung wird storniert, nicht gelöscht.
     *
     * @param  Invoice  $record
     */
    public static function canDelete($record): bool
    {
        return $record->isDraft() && self::canCreate();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'create' => CreateInvoice::route('/neu'),
            'view' => ViewInvoice::route('/{record}'),
            'edit' => EditInvoice::route('/{record}/bearbeiten'),
        ];
    }
}
