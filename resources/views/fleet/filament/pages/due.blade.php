<x-filament-panels::page>
    <div class="flex items-center gap-3">
        <label class="text-sm font-medium">{{ __('fleet.due.window') }}</label>
        <select wire:model.live="window" class="dark:text-white dark:[&>option]:bg-gray-900 dark:[&>option]:text-white fi-select-input rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
            <option value="30">30 {{ __('fleet.due.days') }}</option>
            <option value="60">60 {{ __('fleet.due.days') }}</option>
            <option value="90">90 {{ __('fleet.due.days') }}</option>
            <option value="180">180 {{ __('fleet.due.days') }}</option>
        </select>
    </div>

    @php($items = $this->items())

    @if ($items->isEmpty())
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('fleet.due.nothing') }}</p>
        </x-filament::section>
    @else
        <x-filament::section>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-2 pr-4">{{ __('fleet.aircraft.singular') }}</th>
                            <th class="py-2 pr-4">{{ __('fleet.installation.field.part_name') }}</th>
                            <th class="py-2 pr-4">{{ __('fleet.limits.singular') }}</th>
                            <th class="py-2 pr-4">{{ __('fleet.review.field.valid_until') }}</th>
                            <th class="py-2 pr-4 text-right">{{ __('fleet.due.days') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($items as $item)
                            <tr @class(['bg-danger-50/50 dark:bg-danger-500/10' => $item['overdue']])>
                                <td class="py-2 pr-4 font-medium">{{ $item['aircraft']->registration }}</td>
                                <td class="py-2 pr-4">{{ $item['what'] }}</td>
                                <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $item['detail'] }}</td>
                                <td class="py-2 pr-4">
                                    {{ $item['due_on'] ? \Illuminate\Support\Carbon::parse($item['due_on'])->format('d.m.Y') : '—' }}
                                </td>
                                <td class="py-2 pr-4 text-right whitespace-nowrap">
                                    @if ($item['overdue'])
                                        <span class="font-medium text-danger-600 dark:text-danger-400">
                                            {{ __('fleet.due.overdue') }}
                                        </span>
                                    @elseif ($item['remaining'] !== null)
                                        {{ rtrim(rtrim(number_format($item['remaining'], 1, ',', '.'), '0'), ',') }}
                                        {{ $item['unit'] }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-slot name="footerActions">
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('fleet.due.help.counted') }}</p>
            </x-slot>
        </x-filament::section>
    @endif
</x-filament-panels::page>
