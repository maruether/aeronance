<x-mail::message>
# {{ __('warehouse.order.mail.heading') }}

{{ trans_choice('warehouse.order.mail.intro', $orders->count(), ['anzahl' => $orders->count()]) }}

<x-mail::table>
| {{ __('warehouse.order.field.number') }} | {{ __('warehouse.order.field.supplier') }} | {{ __('warehouse.order.field.expected') }} | {{ __('warehouse.order.mail.outstanding') }} |
|:--|:--|:--|:--|
@foreach ($orders as $order)
| {{ $order->label() }} | {{ $order->supplier?->name ?? '—' }} | {{ $order->expected_at?->format('d.m.Y') }} ({{ __('warehouse.order.mail.days_late', ['tage' => (int) $order->expected_at?->diffInDays(now())]) }}) | {{ $order->lines->sum(fn ($p) => $p->outstanding()) }} |
@endforeach
</x-mail::table>

{{ __('warehouse.order.mail.hint') }}

<x-mail::button :url="$url">
{{ __('warehouse.order.mail.button') }}
</x-mail::button>

{{ __('warehouse.order.mail.footer') }}
</x-mail::message>
