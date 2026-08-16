{{--
    Momentenverfahren -- die bebilderte Erklärung zu X = Σ (G · x) / Σ G.

    Warum eine eigene Zeichnung neben der Hebelskizze: Das ist ein anderer
    Rechenweg, kein anderes Aussehen. Der Hebel legt EINEN Abstand zwischen
    zwei Auflagen; hier bekommt jeder Wägepunkt seinen eigenen Arm ab
    Bezugspunkt. Die Hebelskizze zu einer Momentenrechnung zu stellen wäre das
    falsche Bild zur richtigen Zahl -- und geglaubt wird das Bild.

    Was das Bild aus den Daten nimmt: die Wägepunkte stehen an ihrer
    MASSSTÄBLICHEN Stelle, aus arm_mm skaliert, zusammen mit B.P., B.E. und S.
    Fehlt ein Arm, fällt die ganze Zeichnung auf feste Abstände zurück -- halb
    maßstäblich wäre die schlechteste Variante, weil man ihr nicht ansieht,
    welche Hälfte stimmt.

    Was das Fahrwerk steuert: die Plätze im Bild und den Rückfall. Bugrad heißt
    dritter Punkt vorn, Spornrad hinten, zwei Punkte die einfache Form. Am
    Rechnen ändert es nichts -- das steckt in den Armen selbst.

    Der Rumpf ist Kulisse und EIGEN gezeichnet -- kein Nachzeichnen einer
    Vorlage.

    Erwartet: $weighing (Weighing). Sonst nichts.
--}}
@php
    // Zahlen gehören unter das Bild, nicht hinein -- lange Zahlen über einer
    // Maßkette sind beim Verkleinern als Erstes unlesbar.
    $fmt = fn (?float $v, int $d = 1): string => $v === null ? '—' : number_format($v, $d, ',', '.');
    $mm = fn (?float $v): string => $v === null ? '—' : number_format($v, 0, ',', '.');

    $supports = $weighing->entriesOf('support')->values();
    $noseWheel = $weighing->undercarriage?->isNoseWheel() ?? true;

    $arms = [];
    $masses = [];
    $labels = [];

    foreach ($supports as $entry) {
        $arms[] = $entry->arm_mm === null ? null : (float) $entry->arm_mm;
        $masses[] = $entry->netto();
        $labels[] = (string) $entry->label;
    }

    $count = count($arms);
    $total = array_sum($masses);
    $complete = $count > 0 && ! in_array(null, $arms, true);

    $moment = null;

    if ($complete) {
        $moment = 0.0;

        foreach ($arms as $i => $arm) {
            $moment += $masses[$i] * $arm;
        }
    }

    // Der Schwerpunkt der Wägekonfiguration -- das, was die gezeichneten Pfeile
    // im Gleichgewicht halten. Die Abzüge kommen erst danach.
    $x = ($complete && $total > 0.0) ? $moment / $total : null;

    /*
     * Die Bezugsebene B.E. wird nicht gespeichert, sondern abgeleitet -- und nur,
     * wenn die Daten sie hergeben: `front_support_arm_mm` (a) ist der Abstand
     * B.P. → vorderste Auflage. Steht er auf einem Momentenblatt UND haben die
     * Wägepunkte eigene Arme, dann sind zwei Ursprünge im Spiel: die Ebene, ab
     * der die Arme gemessen wurden, und der Punkt, ab dem a gemessen wurde.
     * Beide zu zeichnen macht die Differenz sichtbar, statt sie stillschweigend
     * auf einen zu ziehen. Fehlt a -- der Normalfall auf diesem Blatt --, bleibt
     * es bei B.P. allein.
     */
    $a = $weighing->front_support_arm_mm === null ? null : (float) $weighing->front_support_arm_mm;
    $mmBe = null;

    if ($complete && $a !== null) {
        $candidate = min($arms) - $a;
        $mmBe = abs($candidate) >= 1.0 ? $candidate : null;
    }

    /*
     * Von Millimetern auf die Zeichenfläche, in zwei Stufen:
     *
     *  1. Die äußeren Wägepunkte auf ihre plausiblen Plätze an der Kulisse
     *     legen -- Bugrad unter die Haube und Haupträder hinter die Fläche,
     *     beim Spornrad umgekehrt -- und B.P., B.E. und S mit DEMSELBEN
     *     Maßstab dazu. Das bleibt eine einzige lineare Abbildung, nur eine,
     *     unter der der Rumpf nicht lügt.
     *  2. Fällt dabei etwas vom Blatt (Bezugspunkt weit vor dem Flugzeug bei
     *     eng beieinanderliegenden Armen), wird über alle Marken normiert.
     *     Dann steht ein Hauptrad vielleicht unterm Leitwerk, aber die
     *     REIHENFOLGE stimmt -- und die ist das, was gelesen wird.
     */
    $map = null;

    if ($complete) {
        $armLo = min($arms);
        $armHi = max($arms);

        if ($armHi - $armLo >= 1.0) {
            if ($count >= 3) {
                [$pxLo, $pxHi] = $noseWheel ? [96.0, 196.0] : [176.0, 302.0];
            } else {
                [$pxLo, $pxHi] = [130.0, 300.0];
            }

            $unit = ($pxHi - $pxLo) / ($armHi - $armLo);
            $anchored = fn (float $v): float => round($pxLo + ($v - $armLo) * $unit, 1);

            $probe = array_filter(
                [$anchored(0.0), $x === null ? null : $anchored($x), $mmBe === null ? null : $anchored($mmBe)],
                fn (?float $v): bool => $v !== null
            );

            if ($probe === [] || (min($probe) >= 26.0 && max($probe) <= 340.0)) {
                $map = $anchored;
            }

            if ($map === null) {
                $marks = array_merge($arms, [0.0]);

                if ($x !== null) {
                    $marks[] = $x;
                }

                if ($mmBe !== null) {
                    $marks[] = $mmBe;
                }

                $markLo = min($marks);
                $markHi = max($marks);
                $map = fn (float $v): float => round(64.0 + (($v - $markLo) / ($markHi - $markLo)) * 232.0, 1);
            }
        }
    }

    $scaled = $map !== null;

    if ($scaled) {
        $xBp = $map(0.0);
        $pos = array_map($map, $arms);
        $xS = $x === null ? null : $map($x);
        $xBe = $mmBe === null ? null : $map($mmBe);
    } else {
        /*
         * Rückfall: reine Legende, also nach Fahrwerk aufgestellt -- Bugrad
         * vorn, Spornrad hinten. S gehört dazu, sonst fehlt genau das, was
         * erklärt werden soll. Eine B.E. wird hier nicht behauptet: ohne
         * Maßstab wäre ihr Abstand zum B.P. frei erfunden.
         */
        $xBp = 62.0;
        $xBe = null;

        if ($count === 0) {
            $pos = [];
        } elseif ($count === 1) {
            $pos = [186.0];
        } elseif ($count === 2) {
            $pos = [140.0, 292.0];
        } elseif ($count === 3) {
            $pos = $noseWheel ? [196.0, 196.0, 104.0] : [166.0, 166.0, 302.0];
        } else {
            $pos = [];

            for ($i = 0; $i < $count; $i++) {
                $pos[] = 100.0 + $i * (200.0 / ($count - 1));
            }
        }

        $xS = $count === 0 ? null : ($noseWheel ? 180.0 : 172.0);
    }

    /*
     * Zwei Haupträder stehen in der Seitenansicht an DERSELBEN Stelle -- ohne
     * leichten Versatz läge der zweite Pfeil unsichtbar unter dem ersten. Der
     * Versatz gilt nur für Rad und Pfeil; die Maßkette bleibt am echten Ort,
     * sonst würde das Bild ein Maß behaupten, das es nicht gibt.
     */
    $drawX = [];

    foreach ($pos as $i => $at) {
        $shift = 0.0;

        foreach ($pos as $j => $other) {
            if ($j < $i && abs($at - $other) < 7.0) {
                $shift += 6.0;
            }
        }

        $drawX[$i] = $at + $shift;
    }

    // Unterkante der Kulisse -- damit die Auflagepfeile am Rumpf anliegen und
    // nicht in der Luft enden, egal wo ein Wägepunkt maßstäblich landet.
    $belly = function (float $at): float {
        if ($at <= 62.0) {
            return 81.0;
        }

        if ($at <= 180.0) {
            return 84.0;
        }

        return max(74.0, 84.0 - ($at - 180.0) * 0.0926);
    };

    /*
     * Maßkette und Pfeil als Bausteine: beide kommen mehrfach vor, und drei
     * Zeilen SVG je Vorkommen wären drei Zeilen, die auseinanderdriften.
     * Kurze Ketten bekommen ihre Beschriftung DANEBEN -- mittig passt „x3"
     * nicht zwischen zwei eng stehende Begrenzungsstriche.
     */
    $chain = function (float $from, float $to, float $y, string $text, bool $below = false): string {
        $lo = min($from, $to);
        $hi = max($from, $to);

        if ($hi - $lo < 3.0) {
            return '';
        }

        // $below für die oberste Kette: dort steht darüber schon die Zeile mit
        // B.P., B.E. und S, und ein „x" dazwischen liest sich als vierte Marke.
        $label = ($hi - $lo) >= 26.0
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
     * einzeln zentriert. Oben stehen bis zu drei davon (B.P., B.E., S), unten
     * die Wägepunkte -- und zwei Haupträder an derselben Station machen aus
     * „G1" und „G2" sonst zuverlässig „G1G2".
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

    /*
     * Der Leermassen-Schwerpunkt liegt woanders als der gezeichnete: Kraftstoff
     * aus einem Flügeltank zu nehmen verschiebt ihn und macht nicht nur
     * leichter. Genannt wird er nur, wenn es die Zahl wirklich bewegt -- sonst
     * stünden zwei fast gleiche Werte nebeneinander und niemand wüsste, welcher
     * der gesuchte ist.
     *
     * In try/catch, weil dieses Partial in der Eingabemaske bei jedem
     * Tastendruck neu zeichnet: eine Ausnahme aus der Rechnung darf die Maske
     * nicht zerlegen.
     */
    $emptyCg = null;

    if ($weighing->kind === \App\Modules\Fleet\Enums\WeighingKind::Powered
        && $weighing->entriesOf('deduction')->isNotEmpty()) {
        try {
            $emptyCg = $weighing->result()->emptyCgMm;
        } catch (\Throwable) {
            $emptyCg = null;
        }
    }

    $emptyCgShift = ($emptyCg !== null && $x !== null && abs((float) $emptyCg - $x) >= 0.5)
        ? (float) $emptyCg
        : null;
@endphp

<div style="display:flex; flex-wrap:wrap; gap:5mm; margin:4mm 0; align-items:stretch">
    <div style="flex:1 1 58%; min-width:200px; border:0.3mm solid currentColor; padding:2mm">
        {{-- stroke="currentColor" statt #000: in der Eingabemaske stünde die
             Zeichnung sonst schwarz auf dunklem Grund -- da, aber unsichtbar.
             Im Druck setzt das Blatt-CSS die Farbe auf Schwarz. --}}
        <svg viewBox="0 0 360 190" style="width:100%; height:auto" xmlns="http://www.w3.org/2000/svg"
             role="img" font-family="sans-serif" font-size="10"
             fill="none" stroke="currentColor" stroke-width="1.2">
            <title>Momentenverfahren: Bezugspunkt, Wägepunkte mit Hebelarmen und Schwerpunkt</title>

            {{-- Bezugslinie B.L., waagerecht strichpunktiert --}}
            <line x1="24" y1="66" x2="344" y2="66" stroke-dasharray="8 3 1.5 3" stroke-width="0.9"/>
            <text x="330" y="62" stroke="none" fill="currentColor" font-size="9">B.L.</text>

            {{-- Kulisse: eigene, schematische Seitenansicht eines Motorflugzeugs.
                 Blasser als der Rest -- sie ist das Einzige im Bild, das NICHT
                 maßstäblich ist, und soll sich auch so lesen. --}}
            <g stroke-opacity="0.6">
                <path d="M36,72 Q40,58 58,54 L78,46 Q98,42 118,46 L160,56 Q240,62 288,60
                         L296,32 L316,32 L312,60 Q322,64 328,70 Q308,74 288,74
                         L180,84 L62,84 Q38,82 36,72 Z" stroke-width="1.1"/>
                <line x1="32" y1="38" x2="32" y2="98" stroke-width="2.2"/>
                <line x1="120" y1="58" x2="204" y2="62" stroke-width="2.4"/>
                <line x1="294" y1="34" x2="330" y2="34" stroke-width="2"/>
            </g>

            {{-- Bezugspunkt B.P., senkrecht gestrichelt --}}
            <line x1="{{ $xBp }}" y1="18" x2="{{ $xBp }}" y2="132" stroke-dasharray="5 3" stroke-width="0.9"/>

            {{-- Bezugsebene B.E. -- nur wenn sie sich aus a ableiten lässt --}}
            @if ($xBe !== null)
                <line x1="{{ $xBe }}" y1="18" x2="{{ $xBe }}" y2="186" stroke-dasharray="2 2.5" stroke-width="0.9"/>
            @endif

            {{-- Schwerpunkt S mit Gewichtspfeil G --}}
            @if ($xS !== null)
                <line x1="{{ $xS }}" y1="18" x2="{{ $xS }}" y2="86" stroke-dasharray="5 3" stroke-width="0.9"/>

                {{-- Zwei Feinheiten, beide aus dem Bild heraus: Beim
                     Spornradflugzeug liegt der Schwerpunkt fast über den
                     Haupträdern, und der Gewichtspfeil liefe mitten durch
                     deren Auflagepfeile -- dann wird er nach OBEN verlegt und
                     zeigt weiter auf S, steht aber nicht mehr im Weg. Und das
                     G stellt sich auf die Seite, auf der Platz ist. --}}
                @php($gRight = min(array_merge([INF], array_map(fn (float $p): float => $p - $xS, array_filter($pos, fn (float $p): bool => $p >= $xS)))))
                @php($gLeft = min(array_merge([INF], array_map(fn (float $p): float => $xS - $p, array_filter($pos, fn (float $p): bool => $p <= $xS)))))
                @php($gAbove = min($gRight, $gLeft) < 10.0)
                @php($gSide = ($gRight < 22.0 && $gLeft >= 22.0) ? -1 : 1)

                @if ($gAbove)
                    {!! $arrowDown($xS, 33.0, 49.0) !!}
                    <text x="{{ $xS + 8 * $gSide }}" y="44" text-anchor="{{ $gSide < 0 ? 'end' : 'start' }}"
                          stroke="none" fill="currentColor">G</text>
                @else
                    {!! $arrowDown($xS, 86.0, 112.0) !!}
                    <text x="{{ $xS + 8 * $gSide }}" y="106" text-anchor="{{ $gSide < 0 ? 'end' : 'start' }}"
                          stroke="none" fill="currentColor">G</text>
                @endif
            @endif

            {!! $tagRow(array_filter([
                ['at' => $xBp, 'text' => 'B.P.'],
                $xBe === null ? null : ['at' => $xBe, 'text' => 'B.E.'],
                $xS === null ? null : ['at' => $xS, 'text' => 'S'],
            ])) !!}

            {{-- Jeder Wägepunkt an seiner Stelle: Rad auf der Kulisse, Pfeil von unten --}}
            @foreach ($drawX as $i => $at)
                @php($ground = $belly($at))
                <circle cx="{{ $at }}" cy="{{ $ground + 7.0 }}" r="5.5" stroke-width="1"/>
                {!! $arrowUp($at, $ground + 13.0, 128.0) !!}
            @endforeach

            {!! $tagRow(array_map(fn (int $i): array => ['at' => $drawX[$i], 'text' => 'G'.($i + 1)], array_keys($drawX)), 138.0) !!}

            {{-- Maßketten: x oben (B.P. → S), x1…xn unten, gestaffelt --}}
            @if ($xS !== null)
                {!! $chain($xBp, $xS, 26.0, 'x', true) !!}
            @endif

            @foreach ($pos as $i => $at)
                {!! $chain($xBp, $at, 148.0 + ($i % 3) * 11.0, 'x'.($i + 1)) !!}
            @endforeach

            {{-- a: B.E. → vorderster Wägepunkt, das gespeicherte Maß --}}
            @if ($xBe !== null && $pos !== [])
                {!! $chain($xBe, min($pos), 182.0, 'a') !!}
            @endif
        </svg>

        <div style="margin-top:1.5mm; font-size:0.82em; line-height:1.35">
            @foreach ($labels as $i => $label)
                G{{ $i + 1 }} {{ $label }}: {{ $fmt($masses[$i]) }}&nbsp;kg ·
                x{{ $i + 1 }}&nbsp;=&nbsp;{{ $mm($arms[$i]) }}&nbsp;mm<br>
            @endforeach
            Σ&nbsp;G&nbsp;=&nbsp;{{ $fmt($total) }}&nbsp;kg
            @if ($weighing->undercarriage)
                · Fahrwerk: {{ $weighing->undercarriage->label() }}
            @endif
            @if ($scaled)
                <br>Der Rumpf ist Kulisse; maßstäblich stehen nur B.P.{{ $xBe === null ? '' : ', B.E.' }},
                die Wägepunkte und S.
            @else
                <br>Schematisch — die Abstände im Bild sind noch nicht maßstäblich.
            @endif
            @if ($xBe !== null)
                <br>B.E. aus a&nbsp;=&nbsp;{{ $mm($a) }}&nbsp;mm abgeleitet: Die Hebelarme sind
                ab B.P. gemessen, a ab der Bezugsebene — beide Ursprünge stehen im Bild.
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
                <span style="display:block; border-bottom:0.3mm solid currentColor; padding:0 2mm">Σ&nbsp;(G&nbsp;·&nbsp;x)</span>
                <span style="display:block">Σ&nbsp;G</span>
            </span>
        </div>

        @if ($x !== null)
            <div style="text-align:center; font-size:0.82em">
                =&nbsp;<span style="display:inline-block; text-align:center; vertical-align:middle">
                    <span style="display:block; border-bottom:0.3mm solid currentColor; padding:0 2mm">{{ implode(' + ', array_map(fn (int $i): string => $fmt($masses[$i]).'·'.$mm($arms[$i]), array_keys($arms))) }}</span>
                    <span style="display:block">{{ $fmt($total) }}</span>
                </span>
            </div>
            <div style="text-align:center; margin-top:2mm">
                X&nbsp;=&nbsp;<b>{{ $fmt($x, 1) }}&nbsp;mm</b>
                <span style="font-size:0.82em">({{ $where($x) }})</span>
            </div>
            @if ($emptyCgShift !== null)
                <div style="text-align:center; margin-top:1.5mm; font-size:0.82em">
                    Nach Abzug der ausfliegbaren Betriebsstoffe:
                    Leermassen-Schwerpunkt <b>{{ $fmt($emptyCgShift, 1) }}&nbsp;mm</b>
                    ({{ $where($emptyCgShift) }})
                </div>
            @endif
        @else
            <div style="text-align:center; font-size:0.85em">
                @if (! $complete)
                    Ohne Hebelarm an jedem Wägepunkt keine rechnerische Schwerpunktlage —
                    die Arme gehören in die Auflagenzeilen.
                @else
                    Ohne Auflagemassen keine rechnerische Schwerpunktlage.
                @endif
            </div>
        @endif
    </div>
</div>
