<x-filament-panels::page>
    <form wire:submit="submit">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::actions :actions="$this->getFormActions()" />
        </div>
    </form>

    @if ($this->myOutstanding()->isNotEmpty())
        {{-- Wer nur melden darf, sieht die Befundliste nicht -- die eigene
             Meldung muss trotzdem auffindbar bleiben, sonst meldet bald
             niemand mehr. --}}
        <x-filament::section :heading="__('taskcards.report.mine.heading')">
            <x-slot name="description">{{ __('taskcards.report.mine.description') }}</x-slot>

            <ul class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($this->myOutstanding() as $finding)
                    <li class="flex items-center justify-between gap-4 py-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">
                                {{ $finding->number }} — {{ $finding->title }}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $finding->aircraft?->registration }},
                                {{ $finding->found_on?->format('d.m.Y') }}
                            </p>
                        </div>

                        <x-filament::badge :color="$finding->state->value === 'scheduled' ? 'info' : 'warning'">
                            {{ $finding->state->label() }}
                        </x-filament::badge>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
