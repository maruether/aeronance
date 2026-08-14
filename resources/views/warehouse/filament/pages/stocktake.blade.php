<x-filament-panels::page>
    @php
        $n = fn (float $v): string => rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ',');
        $parts = $this->parts();
    @endphp

    <form wire:submit="submit">
        <x-filament::section>
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="text-sm font-medium">{{ __('warehouse.stocktake.field.location') }}</label>
                    <select wire:model.live="location"
                            class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm">
                        <option value="">{{ __('warehouse.stocktake.all_locations') }}</option>
                        @foreach ($this->locations() as $location)
                            <option value="{{ $location->id }}">{{ $location->name }}</option>
                        @endforeach
                    </select>

                    {{-- Das Regalschild scannen, statt den Ort zu suchen --
                         man steht ohnehin davor. --}}
                    <button type="button"
                            x-data
                            x-on:click="$dispatch('toggle-stocktake-scanner')"
                            class="mt-2 text-sm text-primary-600 hover:underline">
                        {{ __('warehouse.scan.location_open') }}
                    </button>
                </div>
                <div>
                    <label class="text-sm font-medium">{{ __('warehouse.stocktake.field.counted_at') }}</label>
                    {{-- max: gezählt werden kann nur, was schon passiert ist. Die Action
                         lehnt Zukunftsdaten ohnehin ab; das hier erspart den Umweg. --}}
                    <input type="date" wire:model="countedAt" max="{{ now()->toDateString() }}"
                           class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm">
                </div>
                <div class="flex items-end">
                    <a href="{{ route('warehouse.counting-list', ['location' => $this->location]) }}"
                       target="_blank"
                       class="text-sm text-primary-600 hover:underline">
                        {{ __('warehouse.stocktake.print_list') }} ↗
                    </a>
                </div>
            </div>

            @vite('resources/js/scanner.js')

            {{-- Erst beim Aufklappen eingehaengt, nicht nur ausgeblendet: ein
                 verstecktes Element hielte die Kamera offen. --}}
            <div x-data="{ open: false }"
                 x-on:toggle-stocktake-scanner.window="open = ! open"
                 x-on:scan="open = false; $wire.applyScan($event.detail.code)">
                <template x-if="open">
                    <aeronance-scanner
                        class="mt-3 block overflow-hidden rounded-lg bg-black/90"
                        data-stop-label="{{ __('warehouse.scan.stop') }}"
                        data-scanning-label="{{ __('warehouse.scan.location_hint') }}"
                        data-found-label="{{ __('warehouse.scan.found') }}"
                        data-denied-label="{{ __('warehouse.scan.denied') }}"
                        data-insecure-label="{{ __('warehouse.scan.insecure') }}"
                    ></aeronance-scanner>
                </template>
            </div>
        </x-filament::section>

        @php $currentLocation = null; @endphp

        @foreach ($parts as $part)
            @php
                $locationName = $part->storageCompartment?->location?->name ?? __('warehouse.counting.unassigned');
                $lots = $this->lotsOf($part);
            @endphp

            @if ($locationName !== $currentLocation)
                @php $currentLocation = $locationName; @endphp
                <h3 class="mt-6 mb-1 text-sm font-semibold uppercase tracking-wide text-gray-500">
                    {{ $locationName }}
                </h3>
            @endif

            <x-filament::section compact>
                <x-slot name="heading">
                    <span class="text-sm">{{ $part->name }}</span>
                </x-slot>
                <x-slot name="description">
                    {{ $part->storageCompartment?->name }}
                    @if ($part->ipc_part_number) · IPC {{ $part->ipc_part_number }} @endif
                </x-slot>

                @if ($part->isBulkStock())
                    {{-- Sammelbestand: eine Zahl, beide Richtungen erlaubt.
                         Kein Nachweis im Spiel, also nichts, was schiefgehen kann. --}}
                    <div class="flex items-center justify-between gap-4 text-sm">
                        <div class="text-gray-500">
                            {{ __('warehouse.inventory.available') }}:
                            <span class="font-medium text-gray-900 dark:text-gray-100">
                                {{ $n($part->currentStock()) }} {{ $part->unit_of_measure }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="text-gray-500">{{ __('warehouse.counting.counted') }}</label>
                            <input type="number" step="0.001" min="0"
                                   wire:model="bulkCounts.{{ $part->id }}"
                                   placeholder="{{ $n($part->currentStock()) }}"
                                   class="w-28 rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm text-right">
                            <span class="text-gray-500">{{ $part->unit_of_measure }}</span>
                        </div>
                    </div>
                @else
                    {{-- Losgeführt: je Los gezählt, und NUR nach unten korrigierbar.
                         Ein Überschuss gehört nicht zum Form 1 dieses Loses. --}}
                    <div class="divide-y divide-gray-100 dark:divide-white/5 text-sm">
                        @forelse ($lots as $lot)
                            <div class="flex items-center justify-between gap-4 py-2">
                                <div>
                                    <span class="font-medium">{{ $lot->label() }}</span>
                                    @if ($lot->document_reference)
                                        <span class="text-gray-500"> · {{ $lot->document_reference }}</span>
                                    @endif
                                    @if ($lot->expires_at)
                                        <span class="text-gray-500 {{ $lot->hasExpired() ? 'text-danger-600' : '' }}">
                                            · {{ $lot->expires_at->format('m/Y') }}
                                        </span>
                                    @endif
                                    @if ($lot->state->value !== 'serviceable')
                                        <x-filament::badge color="warning" size="xs">
                                            {{ $lot->state->label() }}
                                        </x-filament::badge>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2 whitespace-nowrap">
                                    <span class="text-gray-500">{{ $n($lot->remainingQuantity()) }} →</span>
                                    <input type="number" step="0.001" min="0"
                                           max="{{ $lot->remainingQuantity() }}"
                                           wire:model="lotCounts.{{ $lot->id }}"
                                           placeholder="{{ $n($lot->remainingQuantity()) }}"
                                           class="w-24 rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm text-right">
                                </div>
                            </div>
                        @empty
                            <div class="py-2 text-gray-500">{{ __('warehouse.stocktake.no_lots') }}</div>
                        @endforelse
                    </div>

                @endif
            </x-filament::section>
        @endforeach

        @if ($parts->isEmpty())
            <x-filament::section>
                <div class="text-sm text-gray-500">{{ __('warehouse.counting.empty') }}</div>
            </x-filament::section>
        @endif

        {{-- Gefunden wird selten, gezählt immer: Deshalb steht der Fund EINMAL
             hier unten statt an jeder Kachel -- mit Auswahl des Bauteiltyps. --}}
        <x-filament::section :heading="__('warehouse.stocktake.found_label')" class="mt-6">
            <x-slot name="description">{{ __('warehouse.stocktake.found_hint') }}</x-slot>

            <div class="space-y-3">
                @foreach ($this->foundRows as $i => $row)
                    <div class="grid gap-2 sm:grid-cols-12 sm:items-center">
                        <select wire:model="foundRows.{{ $i }}.part_type_id"
                                class="sm:col-span-5 rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm">
                            <option value="">{{ __('warehouse.stocktake.found_pick_part') }}</option>
                            @foreach ($this->foundCandidates() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>

                        <input type="number" step="0.001" min="0"
                               wire:model="foundRows.{{ $i }}.quantity"
                               placeholder="{{ __('warehouse.counting.counted') }}"
                               class="sm:col-span-2 rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm text-right">

                        <input type="text" wire:model="foundRows.{{ $i }}.note"
                               placeholder="{{ __('warehouse.stocktake.found_note_placeholder') }}"
                               class="sm:col-span-4 rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm">

                        <button type="button" wire:click="removeFoundRow({{ $i }})"
                                class="sm:col-span-1 text-sm text-gray-500 hover:text-danger-600">
                            {{ __('warehouse.stocktake.found_remove') }}
                        </button>
                    </div>
                @endforeach
            </div>

            <div class="mt-3">
                <x-filament::button type="button" size="sm" color="gray" wire:click="addFoundRow">
                    {{ __('warehouse.stocktake.found_add') }}
                </x-filament::button>
            </div>
        </x-filament::section>

        <div class="mt-6">
            <x-filament::button type="submit">
                {{ __('warehouse.stocktake.action') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
