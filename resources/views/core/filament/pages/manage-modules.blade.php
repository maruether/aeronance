<x-filament-panels::page>
    @php($rows = $this->getModuleRows())

    @if (count($rows) === 0)
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('modules.page.none') }}
            </div>
        </x-filament::section>
    @endif

    <div class="grid gap-4">
        @foreach ($rows as $row)
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-3">
                        <span>{{ $row['title'] }}</span>

                        @if ($row['enabled'])
                            <x-filament::badge color="success">
                                {{ __('modules.state.enabled') }}
                            </x-filament::badge>
                        @else
                            <x-filament::badge color="gray">
                                {{ __('modules.state.disabled') }}
                            </x-filament::badge>
                        @endif

                        <span class="text-xs font-normal text-gray-400">
                            {{ $row['version'] }}
                        </span>
                    </div>
                </x-slot>

                @if ($row['description'])
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        {{ $row['description'] }}
                    </p>
                @endif

                @if (count($row['requires']) > 0)
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('modules.needs', ['modules' => implode(', ', $row['requires'])]) }}
                    </p>
                @endif

                {{-- Why something has to come along, before it happens --}}
                @if (! $row['enabled'] && count($row['alsoAffects']) > 0)
                    <div class="mt-3 rounded-lg bg-info-50 p-3 text-sm text-info-700 dark:bg-info-500/10 dark:text-info-300">
                        {{ __('modules.will_also_enable', ['modules' => implode(', ', $row['alsoAffects'])]) }}
                    </div>
                @endif

                {{-- Why it cannot be done --}}
                @if (count($row['blockedBy']) > 0)
                    <div class="mt-3 rounded-lg bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-500/10 dark:text-danger-300">
                        @foreach ($row['blockedBy'] as $reason)
                            <p>{{ $reason }}</p>
                        @endforeach
                    </div>
                @endif

                {{-- Allowed, but worth knowing --}}
                @if (count($row['warnings']) > 0)
                    <div class="mt-3 rounded-lg bg-warning-50 p-3 text-sm text-warning-700 dark:bg-warning-500/10 dark:text-warning-300">
                        @foreach ($row['warnings'] as $warning)
                            <p>{{ $warning }}</p>
                        @endforeach
                    </div>
                @endif

                <x-slot name="footerActions">
                    @if ($row['enabled'])
                        <x-filament::button
                            color="gray"
                            wire:click="disableModule('{{ $row['name'] }}')"
                            wire:confirm="{{ __('modules.confirm.disable') }}"
                            :disabled="! $row['canToggle']"
                        >
                            {{ __('modules.action.disable') }}
                        </x-filament::button>
                    @else
                        <x-filament::button
                            wire:click="enableModule('{{ $row['name'] }}')"
                            :disabled="! $row['canToggle']"
                        >
                            {{ __('modules.action.enable') }}
                        </x-filament::button>
                    @endif
                </x-slot>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
