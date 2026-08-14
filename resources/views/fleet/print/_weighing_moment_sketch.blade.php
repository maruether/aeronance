{{--
    Die Schwerpunktermittlung des MOTORFLUG-Blattes -- und das ist eine andere
    Rechnung als beim Segelflugzeug.

    Ein Motorflugzeug steht auf DREI Auflagen (links, rechts, vorn/hinten), und
    das Formular rechnet nicht über einen Hebel, sondern über Momente: jede
    Auflage mit ihrem eigenen Arm ab Bezugspunkt, Summe der Momente durch Summe
    der Massen. Die Hebelskizze des Segelflugblattes hier zu zeigen wäre das
    falsche Bild zur richtigen Zahl -- deshalb diese eigene Zeichnung.

    Erwartet: $supports (list<array{label,mass,arm}>), $x (float|null),
    $total (float), $fmt (Formatter).

    Die Auflagen stehen an ihrer MASSSTÄBLICHEN Stelle (aus den Armen
    skaliert); der Rumpf dahinter ist Kulisse, keine Vermessung.
--}}
@php($mitArm = collect($supports)->filter(fn (array $s): bool => $s['arm'] !== null)->values())
@php($arme = $mitArm->pluck('arm')->map(fn ($v): float => (float) $v)->all())
@php($alle = array_merge($arme, [0.0], $x !== null ? [(float) $x] : []))
@php($rMin = $alle === [] ? 0.0 : min($alle))
@php($rMax = $alle === [] ? 1.0 : max($alle))
@php($spanne = max(1.0, $rMax - $rMin))
@php($px = fn (float $r): float => 40 + (($r - $rMin) / $spanne) * 260)

<div style="display:flex; gap:6mm; margin:4mm 0; align-items:stretch">
    <div style="flex:1.2; border:0.3mm solid #000; padding:2mm">
        <svg viewBox="0 0 340 150" style="width:100%; height:auto" xmlns="http://www.w3.org/2000/svg"
             font-family="sans-serif" font-size="10" fill="none" stroke="#000" stroke-width="1.2">
            {{-- Bezugslinie --}}
            <line x1="10" y1="52" x2="330" y2="52" stroke-dasharray="8 3 1.5 3"/>
            <text x="318" y="48" stroke="none" fill="#000">B.L.</text>

            {{-- Rumpf mit Motorhaube und Propellerkreis -- Kulisse, nicht Maß --}}
            <path d="M40,58 Q44,46 62,44 Q78,38 96,42 L150,48 Q214,52 262,50 L266,24 L286,24 L284,50 Q296,52 304,58 L296,64 Q240,66 150,62 Q76,66 46,64 Q40,62 40,58 Z"/>
            <line x1="36" y1="34" x2="36" y2="74" stroke-width="2"/>
            <line x1="88" y1="47" x2="182" y2="51" stroke-width="2.4"/>
            <line x1="262" y1="28" x2="300" y2="28" stroke-width="2"/>

            {{-- Bezugspunkt --}}
            <line x1="{{ $px(0.0) }}" y1="16" x2="{{ $px(0.0) }}" y2="100" stroke-dasharray="4 2"/>
            <text x="{{ $px(0.0) - 12 }}" y="13" stroke="none" fill="#000">B.P.</text>

            {{-- Jede Auflage an ihrer Stelle, von unten gegen das Flugzeug --}}
            @foreach ($mitArm as $i => $auflage)
                @php($sx = $px((float) $auflage['arm']))
                <line x1="{{ $sx }}" y1="114" x2="{{ $sx }}" y2="72"/>
                <path d="M{{ $sx - 4 }},78 L{{ $sx }},70 L{{ $sx + 4 }},78 Z" fill="#000" stroke="none"/>
                <text x="{{ $sx - 6 }}" y="126" stroke="none" fill="#000" font-size="9">G{{ $i + 1 }}</text>

                {{-- Maßband B.P. -> Auflage, gestaffelt, damit sich nichts überdeckt --}}
                @php($y = 134 + ($i % 2) * 10)
                <line x1="{{ min($px(0.0), $sx) }}" y1="{{ $y }}" x2="{{ max($px(0.0), $sx) }}" y2="{{ $y }}" stroke-width="0.8"/>
                <line x1="{{ $sx }}" y1="{{ $y - 3 }}" x2="{{ $sx }}" y2="{{ $y + 3 }}" stroke-width="0.8"/>
                <text x="{{ (($px(0.0) + $sx) / 2) - 10 }}" y="{{ $y - 2 }}" stroke="none" fill="#000" font-size="8">r{{ $i + 1 }}</text>
            @endforeach

            {{-- Schwerpunkt --}}
            @if ($x !== null)
                <line x1="{{ $px((float) $x) }}" y1="20" x2="{{ $px((float) $x) }}" y2="92" stroke-dasharray="4 2"/>
                <text x="{{ $px((float) $x) + 4 }}" y="20" stroke="none" fill="#000">S</text>
                <path d="M{{ $px((float) $x) - 4 }},86 L{{ $px((float) $x) }},94 L{{ $px((float) $x) + 4 }},86 Z" fill="#000" stroke="none"/>
                <line x1="{{ $px(0.0) }}" y1="24" x2="{{ $px((float) $x) }}" y2="24"/>
                <text x="{{ (($px(0.0) + $px((float) $x)) / 2) - 3 }}" y="21" stroke="none" fill="#000">X</text>
            @endif
        </svg>
        <div class="note" style="margin-top:1mm">
            Drei Auflagen, drei Hebelarme ab Bezugspunkt — hinter dem B.P.
            positiv, davor negativ. Der Rumpf ist Kulisse; maßstäblich stehen
            nur B.P., Auflagen und Schwerpunkt.
        </div>
    </div>

    <div style="flex:1; border:0.3mm solid #000; padding:2mm; display:flex; flex-direction:column; justify-content:center">
        <div style="text-align:center; font-size:11pt; margin-bottom:3mm">
            X&nbsp;=&nbsp;<span style="display:inline-block; text-align:center; vertical-align:middle">
                <span style="display:block; border-bottom:0.3mm solid #000; padding:0 2mm">Σ&nbsp;(G&nbsp;·&nbsp;r)</span>
                <span style="display:block">Σ&nbsp;G</span>
            </span>
        </div>

        @if ($x !== null && $total > 0)
            <div style="text-align:center; font-size:9pt">
                =&nbsp;<span style="display:inline-block; text-align:center; vertical-align:middle">
                    <span style="display:block; border-bottom:0.3mm solid #000; padding:0 2mm">{{ collect($supports)->filter(fn (array $s): bool => $s['arm'] !== null)->map(fn (array $s): string => $fmt((float) $s['mass']).'·'.$fmt((float) $s['arm'], 0))->implode(' + ') }}</span>
                    <span style="display:block">{{ $fmt($total) }}</span>
                </span>
                &nbsp;=&nbsp;<b>{{ $fmt((float) $x, 1) }} mm hinter B.P.</b>
            </div>
        @else
            <div class="note" style="text-align:center">
                Ohne Hebelarme an den Auflagen keine rechnerische
                Schwerpunktlage — die Arme gehören in die Auflagenzeilen.
            </div>
        @endif
    </div>
</div>
