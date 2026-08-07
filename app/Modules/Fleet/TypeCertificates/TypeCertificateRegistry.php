<?php

declare(strict_types=1);

namespace App\Modules\Fleet\TypeCertificates;

use RuntimeException;

/**
 * The authorities that can be asked.
 *
 * Singleton in the container -- registered at boot, read on every search. The
 * directive source registry was documented as a singleton without being bound
 * once, and every import failed; that mistake is not worth repeating.
 */
final class TypeCertificateRegistry
{
    /** @var array<string, TypeCertificateSource> */
    private array $sources = [];

    public function register(TypeCertificateSource $source): void
    {
        $this->sources[$source->authority()] = $source;
    }

    public function has(string $authority): bool
    {
        return isset($this->sources[$authority]);
    }

    public function get(string $authority): TypeCertificateSource
    {
        return $this->sources[$authority]
            ?? throw new RuntimeException(sprintf('No type-certificate source for "%s".', $authority));
    }

    /** @return array<string, TypeCertificateSource> */
    public function all(): array
    {
        return $this->sources;
    }

    /** @return array<string, string> authority => label */
    public function options(): array
    {
        return array_map(fn (TypeCertificateSource $s): string => $s->label(), $this->sources);
    }

    /**
     * Every authority asked at once.
     *
     * What the searchable list uses: a person looking for "ASK 21" does not know
     * or care which authority certified it. Failures are swallowed per source --
     * one authority being down must not hide the others' answers.
     *
     * @return list<TypeCertificateCandidate>
     */
    public function searchAll(
        string $designation,
        CertificateSubject $subject = CertificateSubject::Aircraft,
    ): array {
        return $this->searchWithProblems($designation, $subject)['candidates'];
    }

    /**
     * The same search, plus what went wrong on the way.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * This used to swallow every failure on the grounds that "a search is not a
     * place to fail" -- one authority being down must not hide the others. The
     * first half of that is still true and is why the loop continues.
     *
     * The second half was wrong, and became dangerous the moment reading the
     * Blaues Buch started requiring poppler-utils. On a system without it, EVERY
     * lookup returns nothing, forever, and says "kein Treffer" -- which the user
     * reads as "dieses Muster steht nicht drin" and answers by typing the
     * Kennblatt in by hand. A missing hit is NOT visible as a missing hit; that
     * is exactly what makes it dangerous.
     *
     * So failures are collected and handed back. The caller shows them next to
     * the results: still no exception, still every other authority answered, but
     * nobody is told "nothing found" when the truth is "nothing was asked".
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @return array{candidates: list<TypeCertificateCandidate>, problems: array<string, string>}
     */
    public function searchWithProblems(
        string $designation,
        CertificateSubject $subject = CertificateSubject::Aircraft,
    ): array {
        $found = [];
        $problems = [];

        foreach ($this->sources as $authority => $source) {
            try {
                foreach ($source->search($designation, $subject) as $candidate) {
                    $found[] = $candidate;
                }
            } catch (\Throwable $e) {
                $problems[$source->label()] = $e->getMessage();

                continue;
            }
        }

        return ['candidates' => $found, 'problems' => $problems];
    }
}
