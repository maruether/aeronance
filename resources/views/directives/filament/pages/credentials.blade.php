<x-filament-panels::page>
    {{--
        The password field is intentionally EMPTY on every render, for every
        profile, whether or not one is stored. Filling it -- even masked -- puts
        the secret into the HTML, and from there into the browser cache, a screen
        share and any screenshot. "Gespeichert" plus an empty box says everything
        the person needs and hands out nothing.
    --}}
    @forelse ($this->rows() as $profile => $row)
        <x-filament::section>
            <x-slot name="heading">{{ $profile }}</x-slot>

            <x-slot name="description">
                {{ __('directives.credentials.used_by', ['sources' => implode(', ', $row['labels'])]) }}
            </x-slot>

            @if ($row['from_env'])
                {{-- Set outside the application. Showing editable fields here
                     would invite somebody to maintain a value that is never
                     read. --}}
                <div class="rounded-lg bg-warning-50 p-4 text-sm dark:bg-warning-500/10">
                    <p class="font-medium">{{ __('directives.credentials.from_env_title') }}</p>
                    <p class="mt-1 text-gray-600 dark:text-gray-400">
                        {{ __('directives.credentials.from_env_body') }}
                    </p>
                </div>
            @else
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="user-{{ $profile }}">
                            {{ __('directives.credentials.username') }}
                        </label>
                        <input id="user-{{ $profile }}" type="text" autocomplete="off"
                               wire:model="usernames.{{ $profile }}"
                               class="fi-input block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium" for="pass-{{ $profile }}">
                            {{ __('directives.credentials.password') }}
                        </label>
                        <input id="pass-{{ $profile }}" type="password" autocomplete="new-password"
                               wire:model="passwords.{{ $profile }}"
                               placeholder="{{ $row['set'] ? __('directives.credentials.keep_hint') : '' }}"
                               class="fi-input block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                        <p class="mt-1 text-xs text-gray-500">
                            {{ $row['set']
                                ? __('directives.credentials.stored_hint')
                                : __('directives.credentials.not_stored_hint') }}
                        </p>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-3">
                    <x-filament::button wire:click="save('{{ $profile }}')" icon="heroicon-o-check">
                        {{ __('directives.credentials.save') }}
                    </x-filament::button>

                    @if ($row['set'])
                        <x-filament::button wire:click="test('{{ $profile }}')"
                                            icon="heroicon-o-arrow-path" color="gray">
                            {{ __('directives.credentials.test') }}
                        </x-filament::button>

                        <x-filament::button wire:click="forget('{{ $profile }}')"
                                            icon="heroicon-o-trash" color="danger" outlined>
                            {{ __('directives.credentials.forget') }}
                        </x-filament::button>
                    @endif
                </div>
            @endif
        </x-filament::section>
    @empty
        <x-filament::section>
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('directives.credentials.none') }}
            </p>
        </x-filament::section>
    @endforelse
</x-filament-panels::page>
