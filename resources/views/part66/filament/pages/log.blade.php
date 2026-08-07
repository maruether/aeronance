<x-filament-panels::page>
    <div class="flex flex-wrap items-end gap-4">
        @if ($this->mayViewOthers())
            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('part66.log.person') }}</label>
                <select wire:model.live="personId"
                        class="fi-select-input rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                    @foreach ($this->peopleOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <div>
            <label class="mb-1 block text-sm font-medium">{{ __('part66.log.from') }}</label>
            <input type="date" wire:model.live="from"
                   class="fi-input rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium">{{ __('part66.log.until') }}</label>
            <input type="date" wire:model.live="to"
                   class="fi-input rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
        </div>

        <x-filament::button tag="a" href="{{ $this->printUrl() }}" target="_blank" icon="heroicon-o-printer" color="gray">
            {{ __('part66.log.print') }}
        </x-filament::button>
    </div>

    @php($recency = $this->recency())

    {{-- Recency first, because it is the question somebody opens this page with. --}}
    <x-filament::section :heading="__('part66.recency.title')">
        <x-slot name="description">{{ __('part66.recency.help.no_verdict') }}</x-slot>

        <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-6">
            <div>
                <dt class="text-xs text-gray-500">{{ __('part66.recency.months') }}</dt>
                <dd class="text-lg font-semibold">{{ $recency['months'] }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">{{ __('part66.recency.days') }}</dt>
                <dd class="text-lg font-semibold">{{ $recency['days'] }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">{{ __('part66.recency.hours') }}</dt>
                <dd class="text-lg font-semibold">{{ number_format($recency['hours'], 1, ',', '.') }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">{{ __('part66.summary.certifications') }}</dt>
                <dd class="text-lg font-semibold">{{ $recency['certifications'] }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">{{ __('part66.summary.releases') }}</dt>
                <dd class="text-lg font-semibold">{{ $recency['releases'] }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">{{ __('part66.summary.reviews') }}</dt>
                <dd class="text-lg font-semibold">{{ $recency['reviews'] }}</dd>
            </div>
        </dl>

        @foreach ($this->recencyNotes() as $note)
            <p class="mt-3 text-sm text-warning-600 dark:text-warning-400">{{ $note }}</p>
        @endforeach
    </x-filament::section>

    @php($summary = $this->summary())

    <x-filament::section :heading="__('part66.summary.title')" collapsible collapsed>
        <div class="grid gap-6 sm:grid-cols-3">
            @foreach ([
                'part66.summary.by_activity' => ['by_activity', 'taskcards.activity.'],
                'part66.summary.by_model' => ['by_model', null],
                'part66.summary.by_participation' => ['by_participation', 'taskcards.participation.'],
            ] as $heading => [$key, $prefix])
                <div>
                    <h4 class="mb-2 text-xs font-semibold uppercase text-gray-500">{{ __($heading) }}</h4>
                    <dl class="space-y-1 text-sm">
                        @forelse ($summary[$key] as $label => $hours)
                            <div class="flex justify-between gap-4">
                                <dt>{{ $prefix ? __($prefix.$label) : $label }}</dt>
                                <dd class="font-medium">{{ number_format($hours, 1, ',', '.') }} h</dd>
                            </div>
                        @empty
                            <p class="text-gray-500">—</p>
                        @endforelse
                    </dl>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('part66.log.title')">
        <x-slot name="description">{{ __('part66.log.help.derived') }}</x-slot>

        @php($entries = $this->entries())

        @if ($entries->isEmpty())
            <p class="text-sm text-gray-500">{{ __('part66.log.nothing') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-500">
                        <tr>
                            <th class="py-2 pr-3">{{ __('part66.log.field.date') }}</th>
                            <th class="py-2 pr-3">{{ __('part66.log.field.registration') }}</th>
                            <th class="py-2 pr-3">{{ __('part66.log.field.model') }}</th>
                            <th class="py-2 pr-3">ATA</th>
                            <th class="py-2 pr-3">{{ __('part66.log.field.activity') }}</th>
                            <th class="py-2 pr-3 text-right">{{ __('part66.log.field.duration') }}</th>
                            <th class="py-2 pr-3">{{ __('part66.log.field.participation') }}</th>
                            <th class="py-2 pr-3">{{ __('part66.log.field.certified_by') }}</th>
                            <th class="py-2 pr-3">{{ __('part66.log.field.release') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($entries as $entry)
                            <tr @class(['opacity-60' => $entry->provisional])>
                                <td class="py-2 pr-3 whitespace-nowrap">{{ $entry->date->format('d.m.Y') }}</td>
                                <td class="py-2 pr-3 font-medium">{{ $entry->registration }}</td>
                                <td class="py-2 pr-3">{{ $entry->model ?? '—' }}</td>
                                <td class="py-2 pr-3">{{ $entry->ataChapter ?? '—' }}</td>
                                <td class="py-2 pr-3">{{ $entry->activity->label() }}</td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap">{{ $entry->duration() }}</td>
                                <td class="py-2 pr-3">{{ $entry->participation->label() }}</td>
                                <td class="py-2 pr-3">{{ $entry->certifiedByName ?? '—' }}</td>
                                <td class="py-2 pr-3 whitespace-nowrap">
                                    @if ($entry->provisional)
                                        <span class="text-warning-600 dark:text-warning-400">
                                            {{ __('part66.log.provisional') }}
                                        </span>
                                    @else
                                        {{ $entry->releaseNumber ?? '—' }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-slot name="footerActions">
                <p class="text-xs text-gray-500">{{ __('part66.log.help.provisional') }}</p>
            </x-slot>
        @endif
    </x-filament::section>
</x-filament-panels::page>
