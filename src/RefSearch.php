<?php

namespace ArchMcp;

require_once __DIR__.'/RefGuard.php';
require_once __DIR__.'/RefRepo.php';

/**
 * Literal-text search across several projects' committed code (ARCH-80,
 * arch_ref). The caller hands in one git callable per project it is already a
 * member of, so this class never decides who may read what; it only searches,
 * with caps so one call cannot run for long or flood the reply.
 *
 * Plain substring match, never a regular expression. Results are labelled
 * `project` (unsanitized reference material); the `library` label arrives with
 * arch-library.
 */
final class RefSearch
{
    public const DEFAULT_RESULTS = 30;
    public const MAX_RESULTS = 100;
    public const MAX_PROJECTS = 25;
    public const PER_PROJECT = 10;
    private const PROJECT_SCAN_BYTES = 2097152;
    private const TOTAL_SCAN_BYTES = 8388608;

    /**
     * @param array<string, callable(list<string>): array{exit_code: int, stdout: string, stderr: string}> $gits slug => git callable
     *
     * @return array<string, mixed>
     */
    public static function search(array $gits, string $text, string $pathPrefix = '', bool $ignoreCase = false, int $maxResults = self::DEFAULT_RESULTS): array
    {
        if ('' === $text) {
            return ['success' => false, 'error' => 'no search text given: pass the exact text to look for as `text` (a literal substring, not a regex)'];
        }
        $maxResults = max(1, min($maxResults, self::MAX_RESULTS));
        ksort($gits);

        $matches = [];
        $searched = [];
        $skipped = [];
        $files = 0;
        $skippedFiles = 0;
        $scannedTotal = 0;
        $truncated = false;

        foreach ($gits as $slug => $git) {
            $slug = (string) $slug;
            if (\count($searched) >= self::MAX_PROJECTS) {
                $truncated = true;
                $skipped[] = $slug.' (project limit reached)';
                continue;
            }
            if (!RefRepo::hasRef($git)) {
                $skipped[] = $slug.' (no committed main branch)';
                continue;
            }
            $stage = RefRepo::stagePaths($git);
            if (null === $stage || [] === $stage) {
                $skipped[] = $slug.' (no code stage paths on main)';
                continue;
            }
            $searched[] = $slug;
            $scanned = 0;
            $found = 0;
            foreach (RefRepo::files($git, $stage) as $file) {
                if ('' !== $pathPrefix && !str_starts_with($file, $pathPrefix)) {
                    continue;
                }
                if ($scanned >= self::PROJECT_SCAN_BYTES || $scannedTotal >= self::TOTAL_SCAN_BYTES) {
                    $truncated = true;
                    break;
                }
                $content = RefRepo::show($git, $file);
                if (null === $content) {
                    ++$skippedFiles;
                    continue;
                }
                $scanned += \strlen($content);
                $scannedTotal += \strlen($content);
                ++$files;
                foreach (explode("\n", $content) as $i => $line) {
                    $hit = $ignoreCase ? false !== stripos($line, $text) : false !== strpos($line, $text);
                    if (!$hit) {
                        continue;
                    }
                    if (\count($matches) >= $maxResults) {
                        $truncated = true;
                        break 3;
                    }
                    if ($found >= self::PER_PROJECT) {
                        $truncated = true;
                        break 2;
                    }
                    $matches[] = ['project' => $slug, 'tag' => 'project', 'path' => $file, 'line' => $i + 1, 'text' => RefGuard::display($line)];
                    ++$found;
                }
            }
        }

        $out = [
            'success' => true,
            'matches' => $matches,
            'match_count' => \count($matches),
            'projects_searched' => $searched,
            'files_searched' => $files,
            'truncated' => $truncated,
        ];
        if ([] !== $skipped) {
            $out['skipped_projects'] = $skipped;
        }
        if ($skippedFiles > 0) {
            $out['skipped_files'] = $skippedFiles;
        }
        if ($truncated) {
            $out['note'] = 'results were cut off; narrow the search with a single project in scope, a path prefix or a longer search text';
        } elseif ([] === $searched) {
            $out['note'] = 'no project with committed code to search';
        }

        return $out;
    }
}
