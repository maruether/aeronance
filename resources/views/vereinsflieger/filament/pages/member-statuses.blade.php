{{--
    Die Statusentscheidung.

    Bewusst eine schlichte Liste statt einer Filament-Tabelle: Es sind fuenf
    Zeilen, und jede traegt genau eine Frage. Eine Tabelle mit Filtern und
    Sortierung waere hier Werkzeug ohne Anlass.
--}}
<x-filament-panels::page>
    @php($statuses = $this->statuses())
    @php($optionen = $this->handlingOptions())

    @if ($statuses->isEmpty())
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('vereinsflieger.status.empty') }}
            </div>
        </x-filament::section>
    @else
        @php($offen = $statuses->filter(fn ($s) => $s->isUndecided()))

        @if ($offen->isNotEmpty())
            {{-- Zuerst der Satz, der sagt, was gerade PASSIERT -- nicht nur,
                 was zu tun waere. Menschen ohne Entscheidung bekommen kein
                 Konto, und das darf nicht stillschweigend gelten. --}}
            <x-filament::section
                :heading="__('vereinsflieger.status.open_heading', ['count' => $offen->count()])"
            >
                <div class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
                    <p>{{ __('vereinsflieger.status.open_help', ['people' => $offen->sum('member_count')]) }}</p>

                    {{-- Der Satz danach beugt dem naechsten Missverstaendnis vor:
                         Eine Entscheidung hier vergibt noch keine Rechte. --}}
                    <p>{{ __('vereinsflieger.status.mapping_hint') }}</p>
                </div>
            </x-filament::section>
        @endif

        <x-filament::section>
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($statuses as $status)
                    <div class="flex flex-wrap items-center justify-between gap-4 py-4">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-medium">{{ $status->displayName() }}</span>

                                <x-filament::badge size="sm" color="gray">
                                    {{ __('vereinsflieger.status.id', ['id' => $status->msid]) }}
                                </x-filament::badge>

                                @if ($status->isUndecided())
                                    <x-filament::badge size="sm" color="warning">
                                        {{ __('vereinsflieger.status.undecided') }}
                                    </x-filament::badge>
                                @endif
                            </div>

                            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                {{ trans_choice('vereinsflieger.status.people', (int) $status->member_count, ['count' => (int) $status->member_count]) }}

                                @if ($status->handling !== null)
                                    — {{ $status->handling->help() }}
                                @endif
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            @foreach ($optionen as $wert => $beschriftung)
                                <x-filament::button
                                    size="sm"
                                    :color="$status->handling?->value === $wert ? 'primary' : 'gray'"
                                    :outlined="$status->handling?->value !== $wert"
                                    wire:click="decide({{ $status->id }}, '{{ $wert }}')"
                                >
                                    {{ $beschriftung }}
                                </x-filament::button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
