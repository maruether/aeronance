{{--
    Ein Hinweis mit Dringlichkeit, aber ohne Alarm.

    Warnfarbe statt Rot: Eine überfällige Lieferung ist ein Ärgernis, kein
    Notfall — und wer Rot für Ärgernisse benutzt, dem glaubt niemand mehr,
    wenn wirklich etwas rot ist.
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h3 class="text-base font-semibold text-warning-600 dark:text-warning-400">
                    {{ trans_choice('warehouse.order.widget.title', $orders->count(), ['anzahl' => $orders->count()]) }}
                </h3>

                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('warehouse.order.widget.hint') }}
                </p>

                <ul class="mt-3 space-y-1 text-sm">
                    @foreach ($orders as $order)
                        <li class="text-gray-700 dark:text-gray-300">
                            <span class="font-medium">{{ $order->label() }}</span>
                            — {{ $order->supplier?->name ?? '—' }},
                            {{ __('warehouse.order.mail.days_late', [
                                'tage' => (int) $order->expected_at?->diffInDays(now()),
                            ]) }}
                        </li>
                    @endforeach
                </ul>
            </div>

            <x-filament::button
                tag="a"
                href="{{ url('/verwaltung/bestellungen') }}"
                color="warning"
                icon="heroicon-o-truck"
            >
                {{ __('warehouse.order.widget.open') }}
            </x-filament::button>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
