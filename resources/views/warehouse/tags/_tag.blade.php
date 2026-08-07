@php
    $colour = config('aeronance.quarantine_tag.colours')[$tag->to_state->value] ?? '#5a5a5a';
    $withHead = ($layout['head_width'] ?? 0) > 0;
@endphp

<div class="tag" style="left: {{ $left }}mm; top: {{ $top }}mm;">
    @if ($withHead)
        <div class="head" style="background: {{ $colour }};">
            <div class="no">{{ $tag->quarantine_tag }}</div>
            <div class="state">{{ __('warehouse.tag.state.'.$tag->to_state->value) }}</div>
        </div>
    @endif

    <div class="body">
        @unless ($withHead)
            {{-- Aufklebe-Etikett auf farbigem Karton: die Farbe steckt im
                 Anhaenger, der Zustand steht trotzdem in Worten da, damit ein
                 falsch gegriffener Anhaenger nicht falsch gelesen wird. --}}
            <div style="font-weight:bold; font-size:8pt; margin-bottom:1mm;
                        border-bottom:.3mm solid {{ $colour }}; padding-bottom:.6mm;">
                {{ $tag->quarantine_tag }} &nbsp;·&nbsp;
                {{ __('warehouse.tag.state.'.$tag->to_state->value) }}
            </div>
        @endunless

        <dl>
            <dt>{{ __('warehouse.tag.part') }}</dt>
            <dd>{{ Str::limit($tag->lot?->partType?->name ?? '—', 30) }}</dd>

            <dt>{{ __('warehouse.tag.lot') }}</dt>
            <dd>{{ $tag->lot?->lot_number }}{{ $tag->lot?->serial_number ? ' · S/N '.$tag->lot->serial_number : '' }}</dd>

            <dt>{{ __('warehouse.tag.aircraft') }}</dt>
            <dd>{{ $tag->aircraft_reference ?: '—' }}{{ $tag->aircraft_type ? ' · '.$tag->aircraft_type : '' }}</dd>

            <dt>{{ __('warehouse.tag.date') }}</dt>
            <dd>{{ $tag->occurred_at?->timezone(config('aeronance.organisation.timezone'))->format('d.m.Y') }}</dd>
        </dl>

        {{-- Unterschrift von Hand: der Zettel haengt am Teil und wird dort
             quittiert. --}}
        <div class="sign">
            {{ Str::limit($tag->determined_by_name ?: $tag->user?->name ?: '', 24) }}
            &nbsp;·&nbsp; {{ __('warehouse.tag.signature') }}
        </div>
    </div>
</div>
