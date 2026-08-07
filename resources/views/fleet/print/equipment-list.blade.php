<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Ausrüstungsverzeichnis {{ $aircraft->registration }}</title>
    @include('fleet.print._sheet')
</head>
<body>

<div class="sheet-head">
    <div class="sheet-title">Ausrüstungsverzeichnis</div>
    <div class="sheet-org">
        {{ config('aeronance.organisation.name') }}
    </div>
</div>

<div class="sheet-ident">
    <div><b>Kennzeichen:</b> {{ $aircraft->registration }}</div>
    <div><b>Muster:</b> {{ $aircraft->model }}</div>
    <div><b>Werk-Nr.:</b> {{ $aircraft->serial_number ?? '—' }}</div>
    @if ($aircraft->holder)
        <div><b>Halter:</b> {{ $aircraft->holder->name }}</div>
    @endif
</div>

<table>
    <thead>
        <tr>
            <th style="width: 8mm">*)</th>
            <th style="width: 8mm">**)</th>
            <th>Benennung</th>
            <th>Baumuster</th>
            <th>Hersteller</th>
            <th>Werk-Nr.</th>
            <th>Einbauort ***)</th>
            <th style="width: 22mm">Hebelarm</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td class="tick"><span class="box {{ $row->is_present ? 'on' : '' }}"></span></td>
                <td class="tick"><span class="box {{ $row->is_minimum_equipment ? 'on' : '' }}"></span></td>
                <td>{{ $row->part_name }}</td>
                <td>{{ $row->type_designation ?? '' }}</td>
                <td>{{ $row->manufacturer ?? '' }}</td>
                <td>
                    {{ $row->serial_number ?? '' }}
                    @if ($row->wasTranscribed())
                        <span style="font-size:6.5pt">({{ __('fleet.installation.transcribed') }})</span>
                    @endif
                </td>
                <td>{{ $row->position ?? '' }}</td>
                <td class="num">
                    {{-- Signed, always: the datum is not at the nose, and a lever
                         arm without its sign is a number nobody can use. --}}
                    {{ $row->lever_arm_mm !== null ? sprintf('%+d mm', $row->lever_arm_mm) : '' }}
                </td>
            </tr>
        @empty
            <tr><td colspan="8">Keine Ausrüstung erfasst.</td></tr>
        @endforelse

        {{-- Blank rows, so the sheet can be completed by hand where something
             was fitted before anybody got to a keyboard. --}}
        @for ($i = 0; $i < max(0, 12 - $rows->count()); $i++)
            <tr>
                <td class="tick"><span class="box"></span></td>
                <td class="tick"><span class="box"></span></td>
                <td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td>
            </tr>
        @endfor
    </tbody>
</table>

<div class="note">
    *) ankreuzen, wenn vorhanden &nbsp;·&nbsp;
    **) Mindestausrüstung — ohne dieses Teil ist das Luftfahrzeug nicht verwendbar &nbsp;·&nbsp;
    ***) oder Hebelarm in mm vom Bezugspunkt (Vorzeichen beachten)
</div>

<div class="sheet-foot">
    <div class="sig">Datum</div>
    <div class="sig">Stempel</div>
    <div class="sig">Freigabeberechtigter</div>
</div>

</body>
</html>
