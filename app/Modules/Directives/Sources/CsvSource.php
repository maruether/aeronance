<?php

declare(strict_types=1);

namespace App\Modules\Directives\Sources;

use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Enums\SubjectKind;
use InvalidArgumentException;

/**
 * A pasted or uploaded list.
 *
 * The fallback that always works, for every manufacturer who publishes a PDF or
 * a web page and nothing machine-readable. Semicolon-separated by default
 * because that is what a German Excel produces, with the comma as a fallback --
 * getting that wrong turns a whole file into one column, and it is the first
 * thing that goes wrong in practice.
 *
 * Unknown columns are ignored and missing ones default rather than throwing:
 * an import that refuses a whole file over one unexpected header is an import
 * nobody uses.
 */
final class CsvSource implements DirectiveSource
{
    public function name(): string
    {
        return 'csv';
    }

    public function label(): string
    {
        return __('directives.source.csv');
    }

    public function isAutomatic(): bool
    {
        return false;
    }

    /**
     * @param  array{body?: string, kind?: string, subject_kind?: string, issuer?: string, model?: string}  $options
     * @return list<DirectiveRow>
     */
    public function fetch(array $options = []): array
    {
        $body = trim((string) ($options['body'] ?? ''));

        if ($body === '') {
            throw new InvalidArgumentException('Nothing to import -- the list is empty.');
        }

        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];
        $lines = array_values(array_filter($lines, fn (string $l): bool => trim($l) !== ''));

        if ($lines === []) {
            throw new InvalidArgumentException('Nothing to import -- the list is empty.');
        }

        $delimiter = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';

        $header = array_map(
            fn (string $h): string => strtolower(trim($h, " \t\"'\xEF\xBB\xBF")),
            str_getcsv($lines[0], $delimiter),
        );

        // A header row is only a header if it names a column we know. Otherwise
        // the file has none and the first line is data.
        $hasHeader = array_intersect($header, ['nummer', 'number', 'nr', 'titel', 'title']) !== [];

        $rows = [];

        foreach (array_slice($lines, $hasHeader ? 1 : 0) as $line) {
            $cells = str_getcsv($line, $delimiter);

            $get = function (array $names, ?string $fallback = null) use ($cells, $header, $hasHeader): ?string {
                if ($hasHeader) {
                    foreach ($names as $n) {
                        $i = array_search($n, $header, strict: true);

                        if ($i !== false && isset($cells[$i]) && trim((string) $cells[$i]) !== '') {
                            return trim((string) $cells[$i]);
                        }
                    }

                    return $fallback;
                }

                return $fallback;
            };

            // Without a header, positional: number; title; date; deadline.
            $number = $hasHeader ? $get(['nummer', 'number', 'nr']) : trim((string) ($cells[0] ?? ''));
            $title = $hasHeader ? $get(['titel', 'title', 'bezeichnung']) : trim((string) ($cells[1] ?? ''));

            if ($number === null || $number === '' || $title === null || $title === '') {
                // A line without a number or a title says nothing; skipped rather
                // than imported as a blank that somebody has to assess.
                continue;
            }

            $rows[] = new DirectiveRow(
                number: $number,
                title: $title,
                /*
                 * Die Art aus der Nummer, wo das Dokument sie selbst nennt
                 * ("TM 300/12" IST eine TM) -- die Auswahl im Dialog ist nur
                 * noch der Vorgabewert fuer Zeilen ohne Kuerzel. Feldtest:
                 * "koennen wir beim import auf die 'art' verzichten und das
                 * automatisch rausfinden?"
                 */
                kind: DirectiveKind::fromNumber($number)
                    ?? (DirectiveKind::tryFrom((string) ($options['kind'] ?? 'lta')) ?? DirectiveKind::Lta),
                subjectKind: SubjectKind::tryFrom((string) ($options['subject_kind'] ?? 'aircraft_model'))
                    ?? SubjectKind::AircraftModel,

                // Explicit if the import says so, otherwise derived from the kind.
                bindingness: Bindingness::tryFrom((string) ($options['bindingness'] ?? '')),
                issuer: $get(['herausgeber', 'issuer'], $options['issuer'] ?? null),
                summary: $get(['inhalt', 'summary', 'beschreibung']),
                issuedAt: $this->date($hasHeader ? $get(['datum', 'issued_at', 'ausgabedatum']) : ($cells[2] ?? null)),
                complyBefore: $this->date($hasHeader ? $get(['frist', 'comply_before', 'faellig']) : ($cells[3] ?? null)),
                subjectModel: $get(['muster', 'model', 'typ'], $options['model'] ?? null),
                subjectDesignation: $get(['bauteil', 'component', 'bezeichnung_bauteil']),
                subjectPartNumber: $get(['teilenummer', 'part_number', 'pn']),
                serialFrom: $get(['sn_von', 'serial_from', 'von']),
                serialTo: $get(['sn_bis', 'serial_to', 'bis']),
                referenceUrl: $get(['link', 'url', 'reference_url']),
            );
        }

        return $rows;
    }

    /**
     * German and ISO dates, and nothing else.
     *
     * Returns null rather than guessing on anything else: a wrong date on a
     * deadline is worse than an empty one, because an empty field is visible.
     */
    private function date(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value)) {
            return $value;
        }

        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return null;
    }
}
