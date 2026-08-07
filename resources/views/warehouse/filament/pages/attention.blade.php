<x-filament-panels::page>
    @php
        $expired  = \App\Modules\Warehouse\Filament\Pages\StockAttention::expiredLots();
        $expiring = \App\Modules\Warehouse\Filament\Pages\StockAttention::expiringLots();
        $short    = \App\Modules\Warehouse\Filament\Pages\StockAttention::belowMinimum();
        $blocked  = \App\Modules\Warehouse\Filament\Pages\StockAttention::blockedLots();
        $noDocs   = \App\Modules\Warehouse\Filament\Pages\StockAttention::missingDocuments();

        $number = fn (float $v): string => rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ',');
    @endphp

    @if ($expired->isEmpty() && $expiring->isEmpty() && $short->isEmpty()
         && $blocked->isEmpty() && $noDocs->isEmpty())
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('warehouse.attention.all_clear') }}
            </div>
        </x-filament::section>
    @endif

    {{-- Abgelaufen zuerst: liegt im Regal und sieht brauchbar aus, ist es aber nicht. --}}
    @if ($expired->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">
                <span class="text-danger-600">{{ __('warehouse.attention.expired') }}</span>
            </x-slot>
            <x-slot name="description">{{ __('warehouse.attention.expired_hint') }}</x-slot>

            <div class="divide-y divide-gray-100 dark:divide-white/5 text-sm">
                @foreach ($expired as $lot)
                    <div class="flex justify-between gap-4 py-2">
                        <div>
                            <div class="font-medium">{{ $lot->partType?->name }}</div>
                            <div class="text-gray-500">{{ $lot->label() }}</div>
                        </div>
                        <div class="text-right whitespace-nowrap">
                            <div class="text-danger-600 font-medium">
                                {{ $lot->expires_at->format('d.m.Y') }}
                            </div>
                            <div class="text-gray-500">
                                {{ $number($lot->remainingQuantity()) }} {{ $lot->partType?->unit_of_measure }}
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    @if ($short->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">{{ __('warehouse.attention.below_minimum') }}</x-slot>
            <x-slot name="description">{{ __('warehouse.attention.below_minimum_hint') }}</x-slot>

            <div class="divide-y divide-gray-100 dark:divide-white/5 text-sm">
                @foreach ($short as $part)
                    <div class="flex justify-between gap-4 py-2">
                        <div>
                            <div class="font-medium">{{ $part->name }}</div>
                            <div class="text-gray-500">
                                {{ $part->supplier?->name ?? __('warehouse.attention.no_supplier') }}
                                @if ($part->order_code) · {{ $part->order_code }} @endif
                            </div>
                        </div>
                        <div class="text-right whitespace-nowrap">
                            <div class="font-medium">
                                {{ $number($part->availableStock()) }} / {{ $part->minimum_stock }}
                                {{ $part->unit_of_measure }}
                            </div>
                            <div class="text-warning-600">
                                {{ __('warehouse.attention.short_by', [
                                    'n' => $number(max(0, $part->minimum_stock - $part->availableStock())),
                                ]) }}
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    @if ($expiring->isNotEmpty())
        <x-filament::section collapsible>
            <x-slot name="heading">{{ __('warehouse.attention.expiring') }}</x-slot>

            <div class="divide-y divide-gray-100 dark:divide-white/5 text-sm">
                @foreach ($expiring as $lot)
                    <div class="flex justify-between gap-4 py-2">
                        <div>
                            <div class="font-medium">{{ $lot->partType?->name }}</div>
                            <div class="text-gray-500">{{ $lot->label() }}</div>
                        </div>
                        <div class="text-right whitespace-nowrap">
                            {{ $lot->expires_at->format('d.m.Y') }}
                            <div class="text-gray-500">
                                {{ __('warehouse.attention.in_days', [
                                    'n' => (int) now()->startOfDay()->diffInDays($lot->expires_at),
                                ]) }}
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    @if ($blocked->isNotEmpty())
        <x-filament::section collapsible>
            <x-slot name="heading">{{ __('warehouse.attention.blocked') }}</x-slot>
            <x-slot name="description">{{ __('warehouse.attention.blocked_hint') }}</x-slot>

            <div class="divide-y divide-gray-100 dark:divide-white/5 text-sm">
                @foreach ($blocked as $lot)
                    @php $since = $lot->stateChanges->first(); @endphp
                    <div class="flex justify-between gap-4 py-2">
                        <div>
                            <div class="font-medium">{{ $lot->partType?->name }}</div>
                            <div class="text-gray-500">
                                {{ $lot->label() }}
                                @if ($since?->quarantine_tag) · {{ $since->quarantine_tag }} @endif
                            </div>
                            @if ($since?->reason)
                                <div class="text-gray-500 italic">{{ Str::limit($since->reason, 70) }}</div>
                            @endif
                        </div>
                        <div class="text-right whitespace-nowrap">
                            <div>{{ $lot->state->label() }}</div>
                            @if ($since)
                                <div class="text-gray-500">
                                    {{ __('warehouse.attention.since_days', [
                                        'n' => (int) $since->occurred_at->diffInDays(now()),
                                    ]) }}
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    @if ($noDocs->isNotEmpty())
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">{{ __('warehouse.attention.missing_documents') }}</x-slot>
            <x-slot name="description">{{ __('warehouse.attention.missing_documents_hint') }}</x-slot>

            <div class="divide-y divide-gray-100 dark:divide-white/5 text-sm">
                @foreach ($noDocs as $lot)
                    <div class="flex justify-between gap-4 py-2">
                        <div>
                            <div class="font-medium">{{ $lot->partType?->name }}</div>
                            <div class="text-gray-500">{{ $lot->label() }}</div>
                        </div>
                        <div class="text-right whitespace-nowrap text-gray-500">
                            {{ $lot->document_reference ?: __('warehouse.attention.no_reference') }}
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
