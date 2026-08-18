{{--
    Der Befundbericht — der Blattkörper, für Bildschirm und Papier derselbe.

    ─────────────────────────────────────────────────────────────────────────────
    Vorgabe: „Sollte der Befundbericht nach dem Schema ‚Laufende Nummer - Befund
    - Behebung - Ausgeführt durch - Geprüft durch - freigegeben durch'
    aufgebaut sein."

    Die drei letzten Spalten werden NICHT eigens geführt. Sie stehen an der
    Arbeitskarte, die den Punkt behebt: fertiggemeldet von, unabhängig
    kontrolliert von, abgezeichnet von. Ein Blatt mit eigenen Namensfeldern
    daneben könnte etwas anderes sagen als die Akte darunter -- und geglaubt
    würde das Blatt.

    Farben über currentColor statt fest Schwarz: Am Bildschirm läuft das Panel
    auch dunkel, auf Papier ist es Papier.
    ─────────────────────────────────────────────────────────────────────────────

    Erwartet: $order (WorkOrder), $points (list<array{position,finding,card}>),
              $release (?ReleaseToService)
--}}
@php
    $aircraft = $order->aircraft;

    $datum = fn ($wert): string => $wert === null ? '' : $wert->format('d.m.Y');

    /*
     * Was in der Spalte „Art der Behebung" steht. Ein Punkt, der NICHT behoben
     * wurde, bekommt trotzdem seinen Satz: „zurückgestellt bis" ist eine
     * Aussage, für die jemand einsteht, und sie gehört auf dasselbe Blatt wie
     * die erledigten Punkte -- sonst liest sich der Bericht, als wäre alles
     * gemacht.
     */
    $behebung = function ($punkt): string {
        $befund = $punkt['finding'];
        $karte = $punkt['card'];

        if ($befund->state === \App\Modules\TaskCards\Enums\FindingState::Deferred) {
            return trim(__('taskcards.finding_report.deferred', [
                'date' => $befund->deferred_until?->format('d.m.Y') ?? '—',
            ]).' '.(string) $befund->deferral_reason);
        }

        if ($befund->state === \App\Modules\TaskCards\Enums\FindingState::Dismissed) {
            return trim(__('taskcards.finding_state.dismissed').': '.(string) $befund->resolution);
        }

        $text = (string) ($karte?->work_performed ?? '');

        if ($text === '' && $karte?->state === \App\Modules\TaskCards\Enums\TaskCardState::Cancelled) {
            $text = trim(__('taskcards.state.cancelled').': '.(string) $karte->cancellation_reason);
        }

        if ($text === '') {
            $text = (string) ($befund->resolution ?? '');
        }

        return $text;
    };
@endphp

<div class="sheet-head">
    <div class="sheet-title">{{ __('taskcards.finding_report.title') }}</div>
    <div class="sheet-org">
        {{ config('aeronance.organisation.name') }}
    </div>
</div>

<table class="ident">
    <tr>
        <td><b>{{ __('fleet.aircraft.field.registration') }}</b> {{ $aircraft?->registration ?? '—' }}</td>
        <td><b>{{ __('fleet.aircraft.field.model') }}</b> {{ $aircraft?->model ?? '—' }}</td>
        <td><b>{{ __('fleet.aircraft.field.serial_number') }}</b> {{ $aircraft?->serial_number ?? '—' }}</td>
        <td><b>{{ __('taskcards.finding_report.sheet_no') }}</b> {{ $order->number }}</td>
    </tr>
</table>

<table class="points">
    <thead>
        <tr>
            <th class="nr">{{ __('taskcards.finding_report.column.position') }}</th>
            <th class="finding">{{ __('taskcards.finding_report.column.finding') }}</th>
            <th class="fix">{{ __('taskcards.finding_report.column.fix') }}</th>
            <th class="sig">{{ __('taskcards.finding_report.column.done_by') }}</th>
            <th class="sig">{{ __('taskcards.finding_report.column.checked_by') }}</th>
            <th class="sig">{{ __('taskcards.finding_report.column.certified_by') }}</th>
        </tr>
    </thead>
    <tbody>
    @forelse ($points as $punkt)
        @php($befund = $punkt['finding'])
        @php($karte = $punkt['card'])
        <tr>
            <td class="nr">{{ $punkt['position'] }}</td>
            <td>
                <b>{{ $befund->title }}</b>
                <div class="klein">{{ $befund->description }}</div>
                <div class="klein zart">
                    {{ $befund->number }}@if ($karte) · {{ $karte->number }}@endif
                </div>
            </td>
            <td>{{ $behebung($punkt) }}</td>
            <td class="sig">
                {{ $karte?->completed_by_name }}
                <div class="klein zart">{{ $datum($karte?->completed_at) }}</div>
            </td>
            <td class="sig">
                {{ $karte?->inspected_by_name }}
                <div class="klein zart">{{ $datum($karte?->inspected_at) }}</div>
            </td>
            <td class="sig">
                {{ $karte?->certified_by_name }}
                <div class="klein zart">
                    {{ $karte?->qualification_reference }}
                    @if ($karte?->certified_at) <br>{{ $datum($karte->certified_at) }} @endif
                </div>
            </td>
        </tr>
    @empty
        <tr>
            <td class="nr">1</td>
            <td colspan="5" class="leer">{{ __('taskcards.finding_report.empty') }}</td>
        </tr>
    @endforelse

    {{--
        Die vorgedruckte letzte Zeile. Sie steht auf dem Blatt über der
        Unterschrift und meint den ganzen Vorgang: Man räumt einmal auf, nicht
        je Befund. Ein vergessener Schraubenschlüssel im Rumpf ist genau die
        Sorte Fund, für die diese Zeile gedruckt wurde.
    --}}
    <tr class="fod">
        <td class="nr"></td>
        <td>{{ __('taskcards.finding_report.foreign_object_check') }}</td>
        <td>
            <span class="box {{ $order->foreignObjectCheckDone() ? 'on' : '' }}"></span>
            {{ __('taskcards.finding_report.carried_out') }}
        </td>
        <td class="sig">
            {{ $order->foreign_object_check_by_name }}
            <div class="klein zart">{{ $datum($order->foreign_object_check_at) }}</div>
        </td>
        <td class="sig"></td>
        <td class="sig"></td>
    </tr>
    </tbody>
</table>

<div class="sheet-foot">
    <div class="block">
        <div class="block-title">{{ __('taskcards.finding_report.made_by') }}</div>
        <div class="sig-lines">
            <div class="line"><span class="label">{{ __('taskcards.finding_report.date') }}</span></div>
            <div class="line"><span class="label">{{ __('taskcards.finding_report.signature') }}</span></div>
        </div>
        <div class="sig-lines">
            <div class="line"><span class="label">{{ __('taskcards.finding_report.name') }}</span></div>
            <div class="line"><span class="label">{{ __('taskcards.finding_report.licence') }}</span></div>
        </div>
    </div>

    {{--
        „Abschließend geprüft" ist die Freigabe. Sie wird hier NICHT ein zweites
        Mal unterschrieben, sondern abgebildet, sobald es sie gibt: Zwei
        Unterschriftsfelder für dieselbe Handlung wären zwei Wahrheiten darüber,
        wer freigegeben hat.
    --}}
    <div class="block">
        <div class="block-title">{{ __('taskcards.finding_report.checked_finally') }}</div>
        @if ($release !== null)
            <div class="release">
                <b>{{ $release->number }}</b> · {{ $datum($release->released_at) }}<br>
                {{ $release->released_by_name }}
                @if ($release->qualification_reference)
                    · {{ $release->qualification_reference }}
                @endif
                @if ($release->is_external && $release->external_organisation)
                    <div class="klein zart">{{ $release->external_organisation }}</div>
                @endif
            </div>
        @else
            <div class="sig-lines">
                <div class="line"><span class="label">{{ __('taskcards.finding_report.date') }}</span></div>
                <div class="line"><span class="label">{{ __('taskcards.finding_report.stamp') }}</span></div>
                <div class="line"><span class="label">{{ __('taskcards.finding_report.certifying_staff') }}</span></div>
            </div>
        @endif
    </div>
</div>
