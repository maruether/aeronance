<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\Lang;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Jeder Bereich und jedes Ereignis des Protokolls ist übersetzt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE LÜCKE, DIE DIESER TEST SCHLIESST, WAR DA — und der Scanner über die
 * wörtlichen Schlüssel konnte sie nicht sehen.
 *
 * Die Protokollseite übersetzt zusammengesetzt: `__('audit.area.'.$state)` und
 * `__('audit.event.'.$state)`. Kein Scanner löst das auf. Gemessen standen in
 * der Sprachdatei drei Bereiche, im Code sechs — `fleet`, `vereinsflieger` und
 * `directive_credentials` erschienen in der Oberfläche als „audit.area.fleet".
 * Bei den Ereignissen dasselbe: vier übersetzt, im Code sieben.
 *
 * Beides fiel niemandem auf, weil Laravel bei einem fehlenden Schlüssel nichts
 * wirft, sondern den Schlüssel anzeigt. Die Seite antwortet mit 200, der Text
 * ist bloß Unsinn.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER TEST LIEST DEN CODE, nicht eine gepflegte Liste: Was `useLogName('…')`
 * setzt und was `->log('…')` schreibt, muss übersetzt sein. Ein neues Modul mit
 * eigenem Protokollbereich fällt am Tag seiner Entstehung auf.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class AuditVocabularyTest extends TestCase
{
    /**
     * Ereignisse, die das Protokollpaket selbst schreibt.
     *
     * Sie stehen in keinem `->log()`-Aufruf, kommen aber in jedem Eintrag eines
     * überwachten Modells vor.
     *
     * @var list<string>
     */
    private const VOM_PAKET = ['created', 'updated', 'deleted', 'restored'];

    #[Test]
    public function every_log_area_used_in_the_code_is_translated(): void
    {
        $bereiche = $this->matchesInSource("/useLogName\(\s*'([a-z0-9_]+)'\s*\)/i");

        // Der Rückfallwert der Seite, wenn ein Eintrag keinen Bereich trägt.
        $bereiche[] = 'default';

        $this->assertAllTranslated('audit.area.', $bereiche, 'useLogName()');
    }

    #[Test]
    public function every_logged_event_is_translated(): void
    {
        $ereignisse = [...$this->matchesInSource("/->log\(\s*'([a-z0-9_]+)'\s*\)/i"), ...self::VOM_PAKET];

        $this->assertAllTranslated('audit.event.', $ereignisse, '->log()');
    }

    /**
     * Der Gegentest — sonst wäre der Test grün, wenn er nichts fände.
     *
     * Ein regulärer Ausdruck, der ins Leere greift, macht jede Behauptung über
     * „alle Treffer" trivial wahr. Diese Zahlen sind bewusst niedrig: Sie
     * sollen einen kaputten Suchausdruck melden, nicht bei jedem neuen Modul
     * nachgezogen werden müssen.
     */
    #[Test]
    public function the_search_actually_finds_something(): void
    {
        $this->assertGreaterThanOrEqual(
            4,
            count($this->matchesInSource("/useLogName\(\s*'([a-z0-9_]+)'\s*\)/i")),
            'Kein useLogName gefunden — stimmt der Suchausdruck noch?',
        );

        $this->assertGreaterThanOrEqual(
            2,
            count($this->matchesInSource("/->log\(\s*'([a-z0-9_]+)'\s*\)/i")),
            'Kein ->log() gefunden — stimmt der Suchausdruck noch?',
        );
    }

    /**
     * @param  list<string>  $werte
     */
    private function assertAllTranslated(string $praefix, array $werte, string $woher): void
    {
        $fehlend = [];

        foreach (array_unique($werte) as $wert) {
            if (! Lang::has($praefix.$wert, 'de')) {
                $fehlend[] = $praefix.$wert;
            }
        }

        sort($fehlend);

        $this->assertSame([], $fehlend, sprintf(
            "Diese Schlüssel setzt der Code über %s, übersetzt sind sie nicht:\n  %s\n"
            .'In der Oberfläche steht dann der Schlüssel selbst.',
            $woher,
            implode("\n  ", $fehlend),
        ));
    }

    /**
     * @return list<string>
     */
    private function matchesInSource(string $pattern): array
    {
        $treffer = [];

        /** @var SplFileInfo $datei */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $datei) {
            if (! $datei->isFile() || $datei->getExtension() !== 'php') {
                continue;
            }

            preg_match_all($pattern, (string) file_get_contents($datei->getPathname()), $m);

            $treffer = [...$treffer, ...$m[1]];
        }

        return array_values(array_unique($treffer));
    }
}
