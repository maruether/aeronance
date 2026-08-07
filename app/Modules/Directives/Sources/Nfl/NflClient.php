<?php

declare(strict_types=1);

namespace App\Modules\Directives\Sources\Nfl;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The Nachrichten für Luftfahrer, as data.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE OFFICIAL GAZETTE, and since 31 March 2026 free of charge -- which is what
 * makes this possible at all. Everything below was measured against the live
 * service, not read off a manual.
 *
 * The portal is a DHTMLX grid, so none of it is in the page. The chain is:
 *
 *   1. startApplication.php                    a session (PHPSESSID)
 *   2. connGrid_1470_<hash>.php?grBag=…        the list, as XML, 400 at a time
 *   3. POST CustomPDF_NfL.php                  grVariableBag with NfL_ID -> a GUID
 *   4. getNfL.php?GUID=…                       the bulletin, as PDF
 *
 * THREE THINGS COST AN HOUR EACH, and each is a trap worth naming:
 *
 *  - The UI adds signature parameters (uschrift=…) that are bound to ITS
 *    session. Sent along, the connector answers 403. Left off, it answers.
 *
 *  - A 403 means "your session is old", not "you are locked out". The same URL
 *    that failed answered again with a fresh PHPSESSID. Anyone reading 403 as a
 *    wall gives up on a service that is wide open.
 *
 *  - The document is NOT addressed by the row id. Step 3 takes a differently
 *    named parameter -- grVariableBag, not grBag -- and inside it NfL_ID, not
 *    rowID. With the wrong one the service answers {"PDF":{"URL":""}}: status
 *    ok, no error, no document. That is the shape this module fears most, and
 *    it took driving a browser and recording its request bodies to see it.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class NflClient
{
    private const BASE = 'https://nfl.dfs.de';

    /** The grid behind the public list. The hash is part of the file name and stable. */
    private const CONNECTOR = '/basic/scripts/connector/connGrid_1470_fca875cbb02a92965989e4a4a5b9eeb1.php';

    /** The guest identity of the portal -- not an account, and not a login. */
    private const GUEST = 'IUSR';

    /** How many rows one connector call returns. Fixed by the service. */
    private const PAGE = 400;

    /** @var array<string, string> */
    private array $cookies = [];

    private bool $started = false;

    /**
     * Every entry of the gazette, newest first.
     *
     * @return list<array{id: string, number: string, part: string, issued: string, title: string}>
     */
    public function entries(int $limit): array
    {
        $this->start();

        $bag = base64_encode(sprintf(
            '{"linkID":null,"rowID":null,"User":"%s"}',
            self::GUEST,
        ));

        $rows = [];

        for ($start = 0; count($rows) < $limit; $start += self::PAGE) {
            $xml = $this->get(self::CONNECTOR."?grBag={$bag}&posStart={$start}&count=".self::PAGE);

            preg_match_all("#<row id='(\d+)'>(.*?)</row>#s", $xml, $matches, PREG_SET_ORDER);

            if ($matches === []) {
                break;
            }

            foreach ($matches as $match) {
                preg_match_all('#<!\[CDATA\[(.*?)\]\]>#s', $match[2], $cells);
                $cell = $cells[1] ?? [];

                if (count($cell) < 7) {
                    continue;
                }

                $rows[] = [
                    'id' => $match[1],
                    'part' => trim($cell[1]),
                    'number' => trim($cell[2]),
                    'issued' => trim($cell[5]),
                    'title' => trim($cell[6]),
                ];
            }
        }

        return array_slice($rows, 0, $limit);
    }

    /**
     * One bulletin, as the PDF the authority publishes.
     *
     * Two calls, because the service mints a one-shot GUID for the document
     * rather than serving it by id. An empty URL in the answer is treated as a
     * failure here and not as "no document": the field is empty exactly when the
     * request was malformed, and a caller that shrugs it off reports a gazette
     * issue with no directives in it.
     */
    public function document(string $nflId): string
    {
        $this->start();

        $answer = $this->post('/basic/scripts/custom/CustomPDF_NfL.php', [
            'grVariableBag' => sprintf(
                '{"linkID":null,"rowID":null,"User":"%s","NfL_ID":"%s"}',
                self::GUEST,
                $nflId,
            ),
        ]);

        $decoded = json_decode($answer, true);
        $url = is_array($decoded) ? (string) ($decoded['PDF']['URL'] ?? '') : '';

        if ($url === '') {
            throw new RuntimeException(sprintf(
                'Die NfL %s liefert keine Dokumentadresse. Die Antwort war "%s" -- der '
                .'Dienst meldet dabei "ok" und nennt keinen Fehler, weshalb eine leere '
                .'Adresse hier als Fehlschlag gilt und nicht als "kein Dokument".',
                $nflId,
                mb_substr(trim($answer), 0, 120),
            ));
        }

        return $this->get($url);
    }

    /** The session the whole chain hangs on. */
    private function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->get('/basic/scripts/stub/startApplication.php');
        $this->started = true;
    }

    private function get(string $path): string
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(60)
            ->get($this->absolute($path));

        $this->remember($response->cookies());

        if ($response->status() === 403) {
            throw new RuntimeException(
                'Die NfL antwortet mit 403. Das heisst hier "die Sitzung ist abgelaufen", '
                .'nicht "gesperrt" -- der Dienst ist frei zugänglich.',
            );
        }

        if ($response->failed()) {
            throw new RuntimeException(sprintf('Die NfL antwortete auf %s mit HTTP %d.', $path, $response->status()));
        }

        return $response->body();
    }

    /**
     * @param  array<string, string>  $form
     */
    private function post(string $path, array $form): string
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(60)
            ->asForm()
            ->post($this->absolute($path), $form);

        $this->remember($response->cookies());

        if ($response->failed()) {
            throw new RuntimeException(sprintf('Die NfL antwortete auf %s mit HTTP %d.', $path, $response->status()));
        }

        return $response->body();
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, 'http') ? $path : self::BASE.$path;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $headers = [
            'User-Agent' => 'Aeronance/0.1 (+https://github.com/maruether/aeronance)',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ];

        if ($this->cookies !== []) {
            $pairs = [];

            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name.'='.$value;
            }

            $headers['Cookie'] = implode('; ', $pairs);
        }

        return $headers;
    }

    private function remember(mixed $jar): void
    {
        if (! is_object($jar) || ! method_exists($jar, 'toArray')) {
            return;
        }

        foreach ($jar->toArray() as $cookie) {
            if (isset($cookie['Name'], $cookie['Value'])) {
                $this->cookies[$cookie['Name']] = $cookie['Value'];
            }
        }
    }
}
