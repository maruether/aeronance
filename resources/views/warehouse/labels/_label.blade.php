@php
    use App\Modules\Warehouse\Support\QrSvg;
    use App\Modules\Warehouse\Support\ScanCode;

    $tz = config('aeronance.organisation.timezone');
    $part = $lot->partType;
@endphp

{{--
    Was hier steht, ist ueber die Lebensdauer des Loses UNVERAENDERLICH.

    Ausdruecklich NICHT auf dem Etikett:

      Menge     Ein Los wird abgebucht. Eine gedruckte Menge ist beim ersten
                Auslagern falsch -- und eine falsche Zahl auf einem Aufkleber
                ist schlimmer als keine, weil sie geglaubt wird.

      Lagerort  Lose werden umgelagert. Der Ort auf dem Etikett schickte
                jemanden ins falsche Fach.

    Beides steht im System und ist dort immer aktuell. Das Etikett ist ein
    Verweis, kein zweiter Datenbestand.
--}}
<div class="label" style="left: {{ $left }}mm; top: {{ $top }}mm;">
    <div class="text">
        <div class="lot">{{ $lot->lot_number }}</div>

        <div class="part">{{ Str::limit($part?->name ?? '—', 34) }}</div>

        @if (filled($part?->ipc_part_number))
            <div class="pn">{{ __('warehouse.label.part_number') }} {{ Str::limit($part->ipc_part_number, 26) }}</div>
        @endif

        <dl>
            @if (filled($lot->serial_number))
                <dt>{{ __('warehouse.label.serial') }}</dt>
                <dd>{{ Str::limit($lot->serial_number, 20) }}</dd>
            @endif

            @if (filled($lot->batch_number))
                <dt>{{ __('warehouse.label.batch') }}</dt>
                <dd>{{ Str::limit($lot->batch_number, 20) }}</dd>
            @endif

            {{-- Der Herkunftsnachweis. Die Losnummer STAMMT in der Regel aus dieser
                 Nummer (siehe LotNumber), sie steht aber trotzdem eigens da: Sie
                 kann gekuerzt oder mit einem Zaehler versehen worden sein, und wer
                 das Papier sucht, braucht die Nummer, die darauf steht. --}}
            @if (filled($lot->document_reference))
                <dt>{{ __('warehouse.label.document') }}</dt>
                <dd>{{ Str::limit($lot->document_reference, 20) }}</dd>
            @endif

            @if ($lot->received_at !== null)
                <dt>{{ __('warehouse.label.received') }}</dt>
                <dd>{{ $lot->received_at->timezone($tz)->format('d.m.Y') }}</dd>
            @endif
        </dl>

        @if ($lot->expires_at !== null)
            <div class="expiry">
                {{ __('warehouse.label.expires') }} {{ $lot->expires_at->timezone($tz)->format('d.m.Y') }}
            </div>
        @endif
    </div>

    {{-- Der Code traegt einen Verweis, keine Adresse -- siehe ScanCode. Er
         steht neben der Losnummer im Klartext: Wer scannt, bekommt dasselbe,
         was er lesen kann, und kann es damit auch pruefen. --}}
    @if ($withQr)
        <div class="qr">{!! QrSvg::render(ScanCode::forLot($lot->lot_number)) !!}</div>
    @endif
</div>
