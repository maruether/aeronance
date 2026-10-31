<?php

declare(strict_types=1);

namespace App\Modules\Billing\Filament\Resources\PartPrices\Pages;

use App\Modules\Billing\Filament\Resources\PartPrices\PartPriceResource;
use App\Modules\Billing\Models\PartPrice;
use App\Modules\Billing\Permissions;
use App\Modules\Billing\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

final class ListPartPrices extends ListRecords
{
    protected static string $resource = PartPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            $this->importAction(),
        ];
    }

    /**
     * Eine Preisliste einfügen -- Text, keine Datei.
     *
     * Eine Werft hat ihre Liste schon, als Tabelle. Kopieren und einfügen ist
     * der kürzeste Weg, und er braucht weder Upload-Prüfung noch
     * Dateiformat-Rätsel: Je Zeile Teilenummer;Preis;Bezeichnung;Einheit.
     */
    private function importAction(): Action
    {
        return Action::make('import')
            ->label(__('billing.price.import.action'))
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->visible(fn (): bool => auth()->user()?->can(Permissions::MANAGE) ?? false)
            ->modalHeading(__('billing.price.import.heading'))
            ->modalDescription(__('billing.price.import.description'))
            ->schema([
                Textarea::make('rows')
                    ->label(__('billing.price.import.rows'))
                    ->rows(12)
                    ->required(),
                DatePicker::make('valid_from')
                    ->label(__('billing.price.import.valid_from'))
                    ->default(now()->toDateString())
                    ->required(),
            ])
            ->action(function (array $data): void {
                $taken = 0;
                $skipped = 0;

                foreach (preg_split('/\R/', (string) $data['rows']) ?: [] as $row) {
                    $cells = array_map('trim', str_getcsv($row, ';', '"', ''));

                    if (count($cells) < 2 || $cells[0] === '' || ! is_numeric(str_replace(',', '.', $cells[1]))) {
                        if (trim($row) !== '') {
                            $skipped++;
                        }

                        continue;
                    }

                    PartPrice::create([
                        'part_number' => $cells[0],
                        'net_price' => Money::of($cells[1]),
                        'description' => ($cells[2] ?? '') !== '' ? $cells[2] : null,
                        'unit' => ($cells[3] ?? '') !== '' ? $cells[3] : 'Stk',
                        'valid_from' => $data['valid_from'],
                    ]);
                    $taken++;
                }

                Notification::make()
                    ->success()
                    ->title(__('billing.price.import.done', ['count' => $taken]))
                    ->body($skipped > 0 ? __('billing.price.import.skipped', ['count' => $skipped]) : null)
                    ->send();
            });
    }
}
