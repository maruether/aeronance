<x-mail::message>
# {{ __('billing.mail.heading', ['kind' => $invoice->kind->label(), 'number' => $invoice->number]) }}

{{ __('billing.mail.greeting') }}

{{ __('billing.mail.body', [
    'kind' => $invoice->kind->label(),
    'number' => $invoice->number,
    'date' => $invoice->issued_at?->format('d.m.Y'),
    'amount' => \App\Modules\Billing\Support\Money::format((string) $invoice->gross_total),
]) }}

@if (! $invoice->isCreditNote() && $invoice->due_at !== null)
{{ __('billing.mail.due', ['date' => $invoice->due_at->format('d.m.Y')]) }}
@endif

{{ __('billing.mail.closing') }}<br>
{{ $organisation }}
</x-mail::message>
