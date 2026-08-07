<x-filament-panels::page>
    @if ($this->expiredLots()->isNotEmpty())
        {{-- Expired stock is the commonest reason to throw anything away and the
             easiest to overlook: it looks exactly like the rest of the shelf. --}}
        <x-filament::section :heading="__('warehouse.disposal.section.expired')">
            <x-slot name="description">{{ __('warehouse.disposal.help.expired') }}</x-slot>

            <ul class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($this->expiredLots() as $lot)
                    <li class="flex items-center justify-between gap-4 py-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">
                                {{ $lot->partType?->name }}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $lot->label() }} —
                                {{ rtrim(rtrim(number_format($lot->remainingQuantity(), 3, ',', '.'), '0'), ',') }}
                                {{ $lot->partType?->unit_of_measure }},
                                {{ __('warehouse.disposal.expired_on', ['date' => $lot->expires_at->format('d.m.Y')]) }}
                            </p>
                        </div>

                        <x-filament::button
                            size="sm"
                            color="gray"
                            wire:click="pick({{ $lot->id }})"
                        >
                            {{ __('warehouse.disposal.pick') }}
                        </x-filament::button>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    <form wire:submit="submit">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::actions :actions="$this->getFormActions()" />
        </div>
    </form>
</x-filament-panels::page>
