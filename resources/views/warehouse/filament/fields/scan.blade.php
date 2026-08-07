{{--
    Das Scanfeld: Kamera und Tastatur nebeneinander, nicht Kamera mit Notausgang.

    ─────────────────────────────────────────────────────────────────────────────
    BEIDE WEGE SIND GLEICHWERTIG, und das ist keine Höflichkeit gegenüber alten
    Telefonen. Der Losaufkleber kommt aus einem Thermodrucker; sein Aufdruck
    verblasst unter UV und Wärme, und ein Etikett bekommt im Lager Öl ab. Die
    Losnummer steht im Klartext daneben, damit sie abgeschrieben werden kann --
    und `ResolveScanCode` nimmt sie genauso an wie einen gescannten Code.

    Wer das Tastaturfeld als „Notlösung" versteckt, hat die Hälfte der Fälle
    versteckt.
    ─────────────────────────────────────────────────────────────────────────────
--}}
@php
    $statePath = $getStatePath();
@endphp

@vite('resources/js/scanner.js')

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{ open: false }"
        {{--
            Der Treffer des Elements wird HIER aufgefangen und in den
            Formularzustand geschrieben. Von dort übernimmt `afterStateUpdated`
            auf dem Feld -- also PHP, nicht JavaScript: Was ein Code bedeutet,
            entscheidet der Server (siehe ResolveScanCode), und nicht der
            Browser eines Telefons.
        --}}
        @scan="open = false; $wire.set('{{ $statePath }}', $event.detail.code)"
        class="fi-fo-field-wrp-content"
    >
        <div class="flex items-start gap-2">
            <input
                type="text"
                inputmode="latin"
                autocomplete="off"
                wire:model.live.debounce.600ms="{{ $statePath }}"
                placeholder="{{ __('warehouse.scan.placeholder') }}"
                class="fi-input block w-full rounded-lg border-none bg-white px-3 py-1.5 text-base text-gray-950 shadow-sm ring-1 ring-gray-950/10 transition duration-75 placeholder:text-gray-400 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 sm:text-sm"
            >

            <button
                type="button"
                x-on:click="open = ! open"
                class="fi-btn fi-btn-size-md relative grid-flow-col items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold shadow-sm ring-1 outline-none transition duration-75 bg-white text-gray-950 ring-gray-950/10 hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:ring-white/20"
            >
                <span x-show="! open">{{ __('warehouse.scan.open') }}</span>
                <span x-show="open" x-cloak>{{ __('warehouse.scan.close') }}</span>
            </button>
        </div>

        {{--
            Die Kamera wird ERST BEIM AUFKLAPPEN in das Dokument gehängt, nicht
            nur ausgeblendet. Ein verstecktes Element hielte den Kamerastrom
            offen -- die Leuchte brennt, und niemand scannt. `x-if` entfernt es
            wirklich, und das Element schaltet in `disconnectedCallback` ab.
        --}}
        <template x-if="open">
            <aeronance-scanner
                class="mt-2 block overflow-hidden rounded-lg bg-black/90"
                data-stop-label="{{ __('warehouse.scan.stop') }}"
                data-scanning-label="{{ __('warehouse.scan.hint') }}"
                data-found-label="{{ __('warehouse.scan.found') }}"
                data-denied-label="{{ __('warehouse.scan.denied') }}"
                data-insecure-label="{{ __('warehouse.scan.insecure') }}"
            ></aeronance-scanner>
        </template>
    </div>
</x-dynamic-component>

@once
    @push('styles')
        <style>
            .ae-scanner { position: relative; }
            .ae-scanner__view { width: 100%; max-height: 60vh; display: block; object-fit: cover; }
            .ae-scanner__note {
                margin: 0; padding: .5rem .75rem; font-size: .8125rem;
                color: #fff; background: rgba(0, 0, 0, .55);
            }
            .ae-scanner__stop {
                position: absolute; top: .5rem; right: .5rem;
                padding: .25rem .625rem; font-size: .8125rem;
                border-radius: .5rem; border: 0; cursor: pointer;
                background: rgba(255, 255, 255, .9); color: #111;
            }
        </style>
    @endpush
@endonce
