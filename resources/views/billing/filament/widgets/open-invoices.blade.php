{{--
    Offene Rechnungen. Warnfarbe erst, wenn etwas überfällig ist -- eine offene
    Rechnung innerhalb des Zahlungsziels ist der Normalzustand, kein Hinweis.
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h3 @class([
                    'text-base font-semibold',
                    'text-warning-600 dark:text-warning-400' => $overdue > 0,
                    'text-gray-700 dark:text-gray-200' => $overdue === 0,
                ])>
                    {{ trans_choice('billing.widget.title', $count, ['anzahl' => $count]) }}
                    — {{ trans_choice('billing.widget.overdue', $overdue, ['anzahl' => $overdue]) }}
                </h3>

                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('billing.widget.outstanding', ['amount' => $outstanding]) }}
                </p>
            </div>

            <x-filament::button
                tag="a"
                href="{{ url('/verwaltung/rechnungen') }}"
                :color="$overdue > 0 ? 'warning' : 'gray'"
                icon="heroicon-o-document-text"
            >
                {{ __('billing.widget.open') }}
            </x-filament::button>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
