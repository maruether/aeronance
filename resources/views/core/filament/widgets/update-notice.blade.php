{{--
    Ein Hinweis, kein Alarm.

    Bewusst zurückhaltend gestaltet: Ein neues Release ist eine gute Nachricht
    und kein Fehler. Rot wäre hier gelogen — und wer Rot für Erfreuliches
    benutzt, dem glaubt niemand mehr, wenn wirklich etwas rot ist.
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                    {{ __('updates.widget.title', ['version' => $latest]) }}
                </h3>

                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('updates.widget.installed', ['version' => $installed]) }}
                </p>

                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('updates.how_to') }}
                </p>
            </div>

            @if ($url !== null)
                <x-filament::button
                    tag="a"
                    href="{{ $url }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    color="gray"
                    icon="heroicon-o-arrow-top-right-on-square"
                >
                    {{ __('updates.widget.notes') }}
                </x-filament::button>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
