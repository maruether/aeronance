{{--
    Hebelverfahren -- die bebilderte Erklärung zu X = (G2 · b) / G + a.

    Warum überhaupt ein Bild: Wer das Blatt in drei Jahren nachrechnet, soll die
    Konvention nicht erraten müssen. Die Zeichnung sagt, was a, b, G1, G2 und X
    am Flugzeug SIND; der Kasten daneben rechnet mit den Zahlen dieser Wägung.

    Warum maßstäblich statt fest verdrahtet: Die Vorgängerfassung setzte die
    x-Positionen fest und spiegelte nur das Vorzeichen von a. Damit konnte das
    Bild einen Schwerpunkt VOR der hinteren Auflage zeigen, wo er in Wahrheit
    dahinter liegt. Ein Bild, das der danebenstehenden Zahl widerspricht, ist
    schlechter als gar keins -- geglaubt wird nämlich das Bild. Jetzt kommen
    B.P., G1, G2 und S aus a, b und X; feste Werte gibt es nur noch als
    Rückfall, solange die Zahlen fehlen.

    Der Rumpf ist Kulisse und EIGEN gezeichnet -- kein Nachzeichnen einer
    Vorlage. Maßstäblich sind allein die senkrechten Linien und die Maßketten.

    Erwartet: $weighing (Weighing). Sonst nichts. Das Partial hängt im Druck UND
    in der Eingabemaske; ein zweiter Aufrufweg mit eigenen Variablen wäre ein
    zweiter Ort, an dem Bild und Zahl auseinanderlaufen können.
--}}
@php
    /*
     * Beschriftungen im Bild bleiben symbolisch, Zahlen stehen im Klartext
     * darunter: „1.234,5" quer über eine Maßkette sprengt jedes Layout, und
     * beim Verkleinern auf Spaltenbreite ist es als Erstes unlesbar.
     */
    $fmt = fn (?float $v, int $d = 1): string => $v === null ? '—' : number_format($v, $d, ',', '.');
    $mm = fn (?float $v): string => $v === null ? '—' : number_format($v, 0, ',', '.');

    $supports = $weighing->entriesOf('support')->values();

    $g1 = $supports->get(0)?->netto();
    $g2 = $supports->get(1)?->netto();
    $g = ($g1 ?? 0.0) + ($g2 ?? 0.0);
    $gShown = ($g1 === null && $g2 === null) ? null : $g;

    $a = $weighing->front_support_arm_mm === null ? null : (float) $weighing->front_support_arm_mm;
    $b = $weighing->support_distance_mm === null ? null : (float) $weighing->support_distance_mm;

    /*
     * Dieselbe Gleichung wie im Rechenweg -- mit vorzeichenbehaftetem a, das
     * die beiden Formeln des Papiers („− a" und „+ a") zu einer macht.
     */
    $x = ($b !== null && $g2 !== null && $g > 0.0)
        ? (($g2 * $b) / $g) + ($a ?? 0.0)
        : null;

    // Alles, was eingezeichnet wird, in Millimetern ab Bezugspunkt.
    $mmBp = 0.0;
    $mmG1 = $a ?? 0.0;
    $mmG2 = $b === null ? null : $mmG1 + $b;
    $mmS = $x;

    /*
     * Von Millimetern auf die Zeichenfläche, in zwei Stufen:
     *
     *  1. Die beiden Auflagen auf ihre plausiblen Plätze an der Kulisse legen
     *     (Hauptrad unter die Fläche, hintere Auflage ans Heck) und B.P. und S
     *     mit DEMSELBEN Maßstab dazu. Das bleibt eine einzige lineare
     *     Abbildung -- nur eine, unter der der Rumpf nicht lügt.
     *  2. Fällt dabei etwas vom Blatt (kurzes b bei weit vorn liegendem B.P.),
     *     wird über alle Marken normiert. Dann steht eine Auflage vielleicht
     *     unterm Leitwerk, aber die REIHENFOLGE stimmt -- und die ist das,
     *     was gelesen wird.
     */
    $map = null;

    if ($mmG2 !== null && $b > 0.0) {
        $unit = (300.0 - 120.0) / $b;
        $anchored = fn (float $v): float => round(120.0 + ($v - $mmG1) * $unit, 1);
        $probe = array_filter(
            [$anchored($mmBp), $mmS === null ? null : $anchored($mmS)],
            fn (?float $v): bool => $v !== null
        );

        if ($probe === [] || (min($probe) >= 26.0 && max($probe) <= 340.0)) {
            $map = $anchored;
        }
    }

    if ($map === null && $mmG2 !== null) {
        $marks = array_filter([$mmBp, $mmG1, $mmG2, $mmS], fn (?float $v): bool => $v !== null);
        $markLo = min($marks);
        $markHi = max($marks);

        if ($markHi - $markLo >= 1.0) {
            $map = fn (float $v): float => round(64.0 + (($v - $markLo) / ($markHi - $markLo)) * 232.0, 1);
        }
    }

    $scaled = $map !== null;

    if ($scaled) {
        $xBp = $map($mmBp);
        $xG1 = $map($mmG1);
        $xG2 = $map($mmG2);
        $xS = $mmS === null ? null : $map($mmS);
    } else {
        /*
         * Rückfall: nichts ist maßstäblich, also ist das Bild reine Legende --
         * und dann gehört S dazu, sonst fehlt genau das, was erklärt werden
         * soll. Das Vorzeichen von a wird trotzdem beachtet, soweit bekannt.
         */
        $xG1 = 128.0;
        $xG2 = 284.0;
        $xBp = $a === null || $a == 0.0 ? 104.0 : ($a < 0 ? 96.0 : 156.0);
        $xS = 178.0;
    }

    // Unterkante der Kulisse -- damit die Auflagepfeile am Rumpf anliegen und
    // nicht in der Luft enden, egal wo eine Auflage maßstäblich landet.
    $belly = function (float $at): float {
        if ($at <= 60.0) {
            return 79.0;
        }

        if ($at <= 180.0) {
            return 82.0;
        }

        return max(74.0, 82.0 - ($at - 180.0) * 0.0755);
    };

    /*
     * Maßkette und Pfeil als Bausteine: beide kommen mehrfach vor, und drei
     * Zeilen SVG je Vorkommen wären drei Zeilen, die auseinanderdriften.
     * Kurze Ketten bekommen ihre Beschriftung DANEBEN -- mittig passt ein
     * Buchstabe nicht zwischen zwei eng stehende Begrenzungsstriche.
     */
    $chain = function (float $from, float $to, float $y, string $text, bool $below = false): string {
        $lo = min($from, $to);
        $hi = max($from, $to);

        if ($hi - $lo < 3.0) {
            return '';
        }

        // $below für die oberste Kette: dort steht darüber schon die Zeile mit
        // B.P., B.E. und S, und ein „x" dazwischen liest sich als vierte Marke.
        $label = ($hi - $lo) >= 22.0
            ? sprintf(
                '<text x="%.1f" y="%.1f" text-anchor="middle" stroke="none" fill="currentColor" font-size="9">%s</text>',
                ($lo + $hi) / 2.0, $below ? $y + 9.0 : $y - 5.0, e($text)
            )
            : sprintf(
                '<text x="%.1f" y="%.1f" stroke="none" fill="currentColor" font-size="9">%s</text>',
                $hi + 3.0, $y + 3.0, e($text)
            );

        return sprintf(
            '<line x1="%1$.1f" y1="%3$.1f" x2="%2$.1f" y2="%3$.1f" stroke-width="0.8"/>'
            .'<line x1="%1$.1f" y1="%4$.1f" x2="%1$.1f" y2="%5$.1f" stroke-width="0.8"/>'
            .'<line x1="%2$.1f" y1="%4$.1f" x2="%2$.1f" y2="%5$.1f" stroke-width="0.8"/>',
            $lo, $hi, $y, $y - 3.5, $y + 3.5
        ).$label;
    };

    $arrowUp = fn (float $at, float $tip, float $tail): string => sprintf(
        '<line x1="%1$.1f" y1="%2$.1f" x2="%1$.1f" y2="%3$.1f"/>'
        .'<path d="M%4$.1f,%5$.1f L%1$.1f,%3$.1f L%6$.1f,%5$.1f Z" fill="currentColor" stroke="none"/>',
        $at, $tail, $tip, $at - 3.6, $tip + 7.0, $at + 3.6
    );

    $arrowDown = fn (float $at, float $top, float $tip): string => sprintf(
        '<line x1="%1$.1f" y1="%2$.1f" x2="%1$.1f" y2="%3$.1f"/>'
        .'<path d="M%4$.1f,%5$.1f L%1$.1f,%3$.1f L%6$.1f,%5$.1f Z" fill="currentColor" stroke="none"/>',
        $at, $top, $tip, $at - 3.6, $tip - 7.0, $at + 3.6
    );

    /*
     * Beschriftungen einer Zeile, von links nach rechts aufgereiht statt
     * einzeln zentriert. Sobald der Schwerpunkt nahe am Bezugspunkt liegt --
     * beim Segelflugzeug der Normalfall -- stehen die Senkrechten wenige Pixel
     * auseinander, und aus „S" und „B.P." wird sonst „SB.P.".
     */
    $tagRow = function (array $items, float $y = 14.0): string {
        usort($items, fn (array $left, array $right): int => $left['at'] <=> $right['at']);

        $out = '';
        $cursor = 2.0;

        foreach ($items as $item) {
            // Grobe Textbreite: großzügig geschätzt, weil zu viel Abstand nur
            // etwas luftig aussieht, zu wenig aber „G1G2" ergibt.
            $width = strlen($item['text']) * 5.8;
            $at = min(max($cursor, $item['at'] - $width / 2.0), 358.0 - $width);
            $out .= sprintf(
                '<text x="%.1f" y="%.1f" stroke="none" fill="currentColor" font-size="9">%s</text>',
                $at, $y, e($item['text'])
            );
            $cursor = $at + $width + 3.0;
        }

        return $out;
    };

    $where = fn (float $v): string => $v < 0 ? 'vor B.P.' : 'hinter B.P.';
@endphp

<div style="display:flex; flex-wrap:wrap; gap:5mm; margin:4mm 0; align-items:stretch">
    <div style="flex:1 1 58%; min-width:200px; border:0.3mm solid currentColor; padding:2mm">
        {{-- stroke="currentColor" statt #000: in der Eingabemaske stünde die
             Zeichnung sonst schwarz auf dunklem Grund -- da, aber unsichtbar.
             Im Druck setzt das Blatt-CSS die Farbe auf Schwarz. --}}
        <svg viewBox="0 0 360 170" style="width:100%; height:auto" xmlns="http://www.w3.org/2000/svg"
             role="img" font-family="sans-serif" font-size="10"
             fill="none" stroke="currentColor" stroke-width="1.2">
            <title>Hebelverfahren: Bezugspunkt, zwei Auflagen und Schwerpunkt</title>

            {{-- Bezugslinie B.L., waagerecht strichpunktiert --}}
            <line x1="24" y1="64" x2="344" y2="64" stroke-dasharray="8 3 1.5 3" stroke-width="0.9"/>
            <text x="330" y="60" stroke="none" fill="currentColor" font-size="9">B.L.</text>

            {{-- Kulisse: eigene, schematische Seitenansicht eines Segelflugzeugs.
                 Blasser als der Rest -- sie ist das Einzige im Bild, das NICHT
                 maßstäblich ist, und soll sich auch so lesen. --}}
            <g stroke-opacity="0.6">
                <path d="M34,74 Q36,58 56,52 Q78,42 104,48 L150,56 Q226,60 284,58
                         L292,30 L312,30 L308,58 Q318,62 324,68 Q306,74 286,74
                         L180,82 L60,82 Q38,80 34,74 Z" stroke-width="1.1"/>
                <line x1="112" y1="57" x2="198" y2="60" stroke-width="2.4"/>
                <line x1="286" y1="32" x2="322" y2="32" stroke-width="2"/>
            </g>

            {{-- Bezugspunkt B.P. und Schwerpunkt S, senkrecht gestrichelt --}}
            <line x1="{{ $xBp }}" y1="18" x2="{{ $xBp }}" y2="128" stroke-dasharray="5 3" stroke-width="0.9"/>

            @if ($xS !== null)
                <line x1="{{ $xS }}" y1="18" x2="{{ $xS }}" y2="84" stroke-dasharray="5 3" stroke-width="0.9"/>

                {{-- Gewichtspfeil G. Zwei Feinheiten, beide aus dem Bild
                     heraus: Liegt der Schwerpunkt fast über einer Auflage,
                     liefe der Pfeil mitten durch deren Auflagepfeil -- dann
                     wird er nach OBEN verlegt und zeigt weiter auf S, steht
                     aber nicht mehr im Weg. Und das G stellt sich auf die
                     Seite, auf der Platz ist. --}}
                @php($gAbove = min(abs($xS - $xG1), abs($xS - $xG2)) < 10.0)
                @php($gSide = (abs($xS - $xG2) < 22.0 && abs($xS - $xG1) >= 22.0) ? -1 : 1)

                @if ($gAbove)
                    {!! $arrowDown($xS, 31.0, 47.0) !!}
                    <text x="{{ $xS + 8 * $gSide }}" y="42" text-anchor="{{ $gSide < 0 ? 'end' : 'start' }}"
                          stroke="none" fill="currentColor">G</text>
                @else
                    {!! $arrowDown($xS, 84.0, 110.0) !!}
                    <text x="{{ $xS + 8 * $gSide }}" y="104" text-anchor="{{ $gSide < 0 ? 'end' : 'start' }}"
                          stroke="none" fill="currentColor">G</text>
                @endif
            @endif

            {!! $tagRow(array_filter([
                ['at' => $xBp, 'text' => 'B.P.'],
                $xS === null ? null : ['at' => $xS, 'text' => 'S'],
            ])) !!}

            {{-- Auflagen: Pfeile von unten gegen den Rumpf --}}
            {!! $arrowUp($xG1, $belly($xG1) + 2.0, 124.0) !!}
            {!! $arrowUp($xG2, $belly($xG2) + 2.0, 124.0) !!}
            {!! $tagRow([['at' => $xG1, 'text' => 'G1'], ['at' => $xG2, 'text' => 'G2']], 136.0) !!}

            {{-- Maßketten: x oben (B.P. → S), a und b unten --}}
            @if ($xS !== null)
                {!! $chain($xBp, $xS, 26.0, 'x', true) !!}
            @endif
            {!! $chain($xBp, $xG1, 148.0, 'a') !!}
            {!! $chain($xG1, $xG2, 163.0, 'b') !!}
        </svg>

        <div style="margin-top:1.5mm; font-size:0.82em; line-height:1.35">
            a&nbsp;=&nbsp;{{ $mm($a) }}&nbsp;mm · b&nbsp;=&nbsp;{{ $mm($b) }}&nbsp;mm ·
            G1&nbsp;=&nbsp;{{ $fmt($g1) }}&nbsp;kg · G2&nbsp;=&nbsp;{{ $fmt($g2) }}&nbsp;kg ·
            G&nbsp;=&nbsp;{{ $fmt($gShown) }}&nbsp;kg
            @if ($scaled)
                <br>Der Rumpf ist Kulisse; maßstäblich stehen nur B.P., die Auflagen und S.
            @else
                <br>Schematisch — die Abstände im Bild sind noch nicht maßstäblich.
            @endif
            <br>Hebelarme hinter dem Bezugspunkt zählen positiv, davor negativ.
            @if ($a !== null)
                Die vordere Auflage liegt hier
                {{ $a < 0 ? 'vor' : ($a > 0 ? 'hinter' : 'genau auf') }} dem B.P.
            @endif
            @if ($weighing->datum_reference)
                <br>B.P.: {{ \Illuminate\Support\Str::limit($weighing->datum_reference, 70) }}
            @endif
            @if ($weighing->reference_line)
                <br>B.L.: {{ \Illuminate\Support\Str::limit($weighing->reference_line, 70) }}
            @endif
        </div>
    </div>

    <div style="flex:1 1 32%; min-width:170px; border:0.3mm solid currentColor; padding:2mm; display:flex; flex-direction:column; justify-content:center">
        <div style="text-align:center; font-size:1.3em; margin-bottom:3mm">
            X&nbsp;=&nbsp;<span style="display:inline-block; text-align:center; vertical-align:middle">
                <span style="display:block; border-bottom:0.3mm solid currentColor; padding:0 2mm">G2&nbsp;·&nbsp;b</span>
                <span style="display:block">G</span>
            </span>&nbsp;+&nbsp;a
        </div>

        @if ($x !== null)
            <div style="text-align:center; font-size:0.92em">
                =&nbsp;<span style="display:inline-block; text-align:center; vertical-align:middle">
                    <span style="display:block; border-bottom:0.3mm solid currentColor; padding:0 2mm">{{ $fmt($g2) }}&nbsp;·&nbsp;{{ $mm($b) }}</span>
                    <span style="display:block">{{ $fmt($g) }}</span>
                </span>
                &nbsp;{{ ($a ?? 0.0) < 0 ? '−' : '+' }}&nbsp;{{ $mm(abs($a ?? 0.0)) }}
            </div>
            <div style="text-align:center; margin-top:2mm">
                X&nbsp;=&nbsp;<b>{{ $fmt($x, 1) }}&nbsp;mm</b>
                <span style="font-size:0.82em">({{ $where($x) }})</span>
            </div>
        @else
            <div style="text-align:center; font-size:0.85em">
                Ohne
                {{ implode(' und ', array_filter([
                    $b === null ? 'den Auflagenabstand b' : null,
                    ($g2 === null || $g <= 0.0) ? 'die Auflagemassen G1 und G2' : null,
                ])) }}
                keine rechnerische Schwerpunktlage.
            </div>
        @endif
    </div>
</div>
