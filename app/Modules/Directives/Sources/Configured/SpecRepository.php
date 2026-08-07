<?php

declare(strict_types=1);

namespace App\Modules\Directives\Sources\Configured;

use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Where manufacturer specs come from.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * TWO DIRECTORIES, and the second is the reason this exists.
 *
 *   resources/directive-sources/   shipped with the release, replaced on update
 *   storage/app/directive-sources/ the club's own, untouched by updates
 *
 * Vorgabe: "das macht die Verbreitung und updates einfacher." Updates are
 * `git checkout <tag>`, which would overwrite anything in the repo -- so a club's
 * own manufacturer file has to live where CLAUDE.md already promises updates do
 * not reach: storage/.
 *
 * A local file WINS over a shipped one of the same name. That is deliberate: when
 * a manufacturer redesigns their site mid-release-cycle, a club can fix it that
 * afternoon instead of waiting, and the fix survives the update that ships the
 * same repair.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SpecRepository
{
    /** @var array<string, SourceSpec>|null */
    private ?array $specs = null;

    public function __construct(
        private readonly string $shippedPath,
        private readonly string $localPath,
    ) {}

    /**
     * @return array<string, SourceSpec>
     */
    public function all(): array
    {
        if ($this->specs !== null) {
            return $this->specs;
        }

        $specs = [];

        // Shipped first, local second -- so local overwrites by key.
        foreach ([$this->shippedPath, $this->localPath] as $path) {
            foreach ($this->filesIn($path) as $file) {
                $spec = $this->load($file);

                if ($spec !== null) {
                    $specs[$spec->name] = $spec;
                }
            }
        }

        return $this->specs = $specs;
    }

    /**
     * Specs that could not be loaded, with the reason.
     *
     * Reported rather than thrown: one broken file must not take the working
     * manufacturers down with it, and a silent skip would be worse than either --
     * an import that finds nothing looks exactly like a manufacturer with nothing
     * new.
     *
     * @return array<string, string> file => reason
     */
    public function problems(): array
    {
        $problems = [];

        foreach ([$this->shippedPath, $this->localPath] as $path) {
            foreach ($this->filesIn($path) as $file) {
                try {
                    $this->parse($file);
                } catch (InvalidArgumentException|ParseException $e) {
                    $problems[basename($file)] = $e->getMessage();
                }
            }
        }

        return $problems;
    }

    public function forget(): void
    {
        $this->specs = null;
    }

    private function load(string $file): ?SourceSpec
    {
        try {
            return $this->parse($file);
        } catch (InvalidArgumentException|ParseException) {
            // See problems(): a broken file is skipped here and reported there.
            return null;
        }
    }

    private function parse(string $file): SourceSpec
    {
        $raw = Yaml::parseFile($file);

        if (! is_array($raw)) {
            throw new InvalidArgumentException(sprintf(
                'The source spec %s does not contain a mapping.',
                basename($file),
            ));
        }

        return SourceSpec::fromArray($raw, basename($file));
    }

    /**
     * @return list<string>
     */
    private function filesIn(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $files = glob(rtrim($path, '/').'/*.{yaml,yml}', GLOB_BRACE) ?: [];

        sort($files);

        return array_values($files);
    }
}
