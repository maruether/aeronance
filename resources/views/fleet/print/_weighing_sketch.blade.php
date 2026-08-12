{{--
    Die bebilderte Erklaerung der Schwerpunktermittlung -- nach dem Vorbild
    des klassischen Waegeformulars (BWLV-Massenuebersicht), als EIGENE
    Zeichnung: Die Skizze zeigt, was a, b, G1, G2 und X am Flugzeug SIND,
    und die Formelzeile rechnet mit den Zahlen dieser Waegung vor. Wer den
    Bericht in drei Jahren liest, muss die Konvention nicht erraten.

    Erwartet: $a, $b, $g1, $g2, $g, $x (float|null), $fmt (Formatter).
    Die Skizze passt sich dem Vorzeichen von a an: negativ = vordere
    Auflage VOR dem Bezugspunkt (Formel wirkt als "− a", linke Variante
    des klassischen Formulars), positiv = dahinter (rechte Variante).
--}}
@php($vorBp = ($a ?? 0) < 0)

<div style="display:flex; gap:6mm; margin:4mm 0; align-items:stretch">
    <div style="flex:1.2; border:0.3mm solid #000; padding:2mm">
        <svg viewBox="0 0 340 150" style="width:100%; height:auto" xmlns="http://www.w3.org/2000/svg"
             font-family="sans-serif" font-size="10" fill="none" stroke="#000" stroke-width="1.2">
            {{-- Bezugslinie B.L. (horizontal, strichpunktiert) --}}
            <line x1="10" y1="52" x2="330" y2="52" stroke-dasharray="8 3 1.5 3"/>
            <text x="318" y="48" stroke="none" fill="#000">B.L.</text>

            {{-- Rumpf: Nase, Haube, Leitwerkstraeger, Seitenflosse --}}
            <path d="M22,60 Q26,50 44,46 Q60,42 78,44 Q92,40 108,44 L150,50 Q210,54 258,52 L262,26 L282,26 L280,52 Q292,53 300,58 L292,64 Q240,66 150,62 Q70,66 34,66 Q24,64 22,60 Z"/>
            {{-- Tragflaeche (Profilsehne) --}}
            <line x1="86" y1="49" x2="180" y2="52" stroke-width="2.4"/>
            {{-- Hoehenleitwerk --}}
            <line x1="258" y1="30" x2="296" y2="30" stroke-width="2"/>

            @php($xbp = $vorBp ? 116 : 60)   {{-- Bezugspunkt --}}
            @php($xg1 = $vorBp ? 84 : 96)    {{-- vordere Auflage --}}
            @php($xs  = $vorBp ? 138 : 128)  {{-- Schwerpunkt --}}

            {{-- Bezugspunkt B.P. (senkrechte Strichlinie) --}}
            <line x1="{{ $xbp }}" y1="18" x2="{{ $xbp }}" y2="98" stroke-dasharray="4 2"/>
            <text x="{{ $xbp - 12 }}" y="14" stroke="none" fill="#000">B.P.</text>

            {{-- Schwerpunkt S mit Gewichtspfeil G --}}
            <line x1="{{ $xs }}" y1="30" x2="{{ $xs }}" y2="92" stroke-dasharray="4 2"/>
            <text x="{{ $xs + 4 }}" y="28" stroke="none" fill="#000">S</text>
            <line x1="{{ $xs }}" y1="66" x2="{{ $xs }}" y2="92"/>
            <path d="M{{ $xs - 4 }},86 L{{ $xs }},94 L{{ $xs + 4 }},86 Z" fill="#000" stroke="none"/>
            <text x="{{ $xs + 6 }}" y="90" stroke="none" fill="#000">G</text>

            {{-- Auflagen: Pfeile von unten gegen den Rumpf --}}
            <line x1="{{ $xg1 }}" y1="112" x2="{{ $xg1 }}" y2="72"/>
            <path d="M{{ $xg1 - 4 }},78 L{{ $xg1 }},70 L{{ $xg1 + 4 }},78 Z" fill="#000" stroke="none"/>
            <text x="{{ $xg1 - 22 }}" y="110" stroke="none" fill="#000">G1</text>

            <line x1="268" y1="112" x2="268" y2="72"/>
            <path d="M264,78 L268,70 L272,78 Z" fill="#000" stroke="none"/>
            <text x="274" y="110" stroke="none" fill="#000">G2</text>

            {{-- Massband a: B.P. <-> G1 --}}
            <line x1="{{ min($xbp, $xg1) }}" y1="122" x2="{{ max($xbp, $xg1) }}" y2="122"/>
            <line x1="{{ $xbp }}" y1="117" x2="{{ $xbp }}" y2="127"/>
            <line x1="{{ $xg1 }}" y1="117" x2="{{ $xg1 }}" y2="127"/>
            <text x="{{ (($xbp + $xg1) / 2) - 3 }}" y="134" stroke="none" fill="#000">a</text>

            {{-- Massband b: G1 <-> G2 --}}
            <line x1="{{ $xg1 }}" y1="142" x2="268" y2="142"/>
            <line x1="{{ $xg1 }}" y1="137" x2="{{ $xg1 }}" y2="147"/>
            <line x1="268" y1="137" x2="268" y2="147"/>
            <text x="{{ (($xg1 + 268) / 2) - 3 }}" y="139" stroke="none" fill="#000">b</text>

            {{-- Massband x: B.P. <-> S (oben) --}}
            <line x1="{{ $xbp }}" y1="22" x2="{{ $xs }}" y2="22"/>
            <line x1="{{ $xs }}" y1="18" x2="{{ $xs }}" y2="26"/>
            <text x="{{ (($xbp + $xs) / 2) - 3 }}" y="19" stroke="none" fill="#000">x</text>
        </svg>
        <div class="note" style="margin-top:1mm">
            Hebelarme hinter dem Bezugspunkt zählen positiv, davor negativ —
            hier liegt die vordere Auflage {{ $vorBp ? 'VOR' : 'hinter' }} dem B.P.
            (a = {{ $fmt($a, 0) }} mm).
        </div>
    </div>

    <div style="flex:1; border:0.3mm solid #000; padding:2mm; display:flex; flex-direction:column; justify-content:center">
        <div style="text-align:center; font-size:11pt; margin-bottom:3mm">
            X&nbsp;=&nbsp;<span style="display:inline-block; text-align:center; vertical-align:middle">
                <span style="display:block; border-bottom:0.3mm solid #000; padding:0 2mm">G2&nbsp;·&nbsp;b</span>
                <span style="display:block">G</span>
            </span>&nbsp;+&nbsp;a
        </div>
        @if ($x !== null)
            <div style="text-align:center">
                =&nbsp;<span style="display:inline-block; text-align:center; vertical-align:middle">
                    <span style="display:block; border-bottom:0.3mm solid #000; padding:0 2mm">{{ $fmt($g2) }}&nbsp;·&nbsp;{{ $fmt($b, 0) }}</span>
                    <span style="display:block">{{ $fmt($g) }}</span>
                </span>&nbsp;{{ ($a ?? 0) < 0 ? '−' : '+' }}&nbsp;{{ $fmt(abs($a ?? 0), 0) }}
                &nbsp;=&nbsp;<b>{{ $fmt($x, 1) }} mm hinter B.P.</b>
            </div>
        @else
            <div class="note" style="text-align:center">
                Ohne Auflagenabstand b keine rechnerische Schwerpunktlage —
                siehe Bemerkungen.
            </div>
        @endif
    </div>
</div>
