<?php

namespace ArchMcp;

/**
 * Read-only helpers for the code lane, added 2026-10-06: a line-range read
 * and a literal text search, so a 90 KB file does not have to be pulled
 * through the conversation to look at 20 lines or find one name.
 *
 * Neither adds any file-access logic of its own. Both go through
 * ArchTools::archCodeReadFile() / archCodeListFiles(), so the lane,
 * stage-path, dot-component and (for reads) no-session rules are exactly
 * the ones every other code-lane read already has. Search is a plain
 * substring match, never a regular expression, so no pattern can be slow.
 */
final class CodeRead
{
    public const DEFAULT_LINES = 200;
    public const MAX_LINES = 400;
    public const DEFAULT_RESULTS = 50;
    public const MAX_RESULTS = 200;
    private const MAX_FILE_BYTES = 1048576;
    private const MAX_SCAN_BYTES = 8388608;
    private const MAX_LINE_CHARS = 300;

    /**
     * Pure step: pick lines $start..$end (1-based, inclusive) out of $content.
     *
     * @return array<string, mixed>
     */
    public static function slice(string $content, int $start, int $end): array
    {
        if ($start < 1) {
            return ['success' => false, 'error' => 'start must be 1 or more'];
        }
        if ($end < $start) {
            return ['success' => false, 'error' => 'end must not be less than start'];
        }
        $lines = explode("\n", $content);
        if (\count($lines) > 1 && '' === end($lines)) {
            array_pop($lines);
        }
        if ('' === $content) {
            $lines = [];
        }
        $total = \count($lines);
        if ($start > $total) {
            return ['success' => false, 'total_lines' => $total, 'error' => "file has {$total} lines; start {$start} is past the end"];
        }
        $capped = false;
        if ($end - $start + 1 > self::MAX_LINES) {
            $end = $start + self::MAX_LINES - 1;
            $capped = true;
        }
        $end = min($end, $total);

        return [
            'success' => true,
            'start' => $start,
            'end' => $end,
            'total_lines' => $total,
            'more_after' => $end < $total,
            'capped' => $capped,
            'content' => implode("\n", \array_slice($lines, $start - 1, $end - $start + 1)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function range(ArchTools $tools, string $path, int $start = 1, int $end = 0): array
    {
        if ($end <= 0) {
            $end = $start + self::DEFAULT_LINES - 1;
        }
        $read = $tools->archCodeReadFile($path);
        if (true !== ($read['success'] ?? false) || !\is_string($read['content'] ?? null)) {
            return ['success' => false, 'path' => $path, 'error' => (string) ($read['error'] ?? 'could not read the file')];
        }

        return ['path' => $path] + self::slice($read['content'], $start, $end);
    }

    /**
     * Pure step: literal search of one file's text.
     *
     * @return list<array{line: int, text: string}>
     */
    public static function findInText(string $content, string $needle, bool $ignoreCase): array
    {
        $hits = [];
        foreach (explode("\n", $content) as $i => $line) {
            $found = $ignoreCase ? false !== stripos($line, $needle) : false !== strpos($line, $needle);
            if ($found) {
                $text = rtrim($line, "\r");
                if (mb_strlen($text) > self::MAX_LINE_CHARS) {
                    $text = mb_substr($text, 0, self::MAX_LINE_CHARS).'…';
                }
                $hits[] = ['line' => $i + 1, 'text' => $text];
            }
        }

        return $hits;
    }

    /**
     * @return array<string, mixed>
     */
    public static function search(ArchTools $tools, string $text, string $pathPrefix = '', bool $ignoreCase = false, int $maxResults = self::DEFAULT_RESULTS): array
    {
        if ('' === $text) {
            return ['success' => false, 'error' => 'search text must not be empty'];
        }
        $maxResults = max(1, min($maxResults, self::MAX_RESULTS));

        $list = $tools->archCodeListFiles();
        if (true !== ($list['success'] ?? false)) {
            return ['success' => false, 'error' => (string) ($list['error'] ?? 'could not list the code lane')];
        }
        $files = \is_array($list['files'] ?? null) ? $list['files'] : [];
        if ([] === $files) {
            return ['success' => true, 'matches' => [], 'match_count' => 0, 'files_searched' => 0, 'truncated' => false, 'note' => (string) ($list['note'] ?? 'the code lane has no files')];
        }

        $matches = [];
        $searched = 0;
        $scanned = 0;
        $skipped = [];
        $truncated = false;
        foreach ($files as $file) {
            if (!\is_string($file) || ('' !== $pathPrefix && !str_starts_with($file, $pathPrefix))) {
                continue;
            }
            if ($scanned >= self::MAX_SCAN_BYTES) {
                $truncated = true;
                $skipped[] = $file.' (scan limit reached)';
                continue;
            }
            $read = $tools->archCodeReadFile($file);
            if (true !== ($read['success'] ?? false) || !\is_string($read['content'] ?? null)) {
                $skipped[] = $file.' (unreadable)';
                continue;
            }
            $content = $read['content'];
            if (\strlen($content) > self::MAX_FILE_BYTES || str_contains($content, "\0")) {
                $skipped[] = $file.' (too large or binary)';
                continue;
            }
            $scanned += \strlen($content);
            ++$searched;
            foreach (self::findInText($content, $text, $ignoreCase) as $hit) {
                if (\count($matches) >= $maxResults) {
                    $truncated = true;
                    break 2;
                }
                $matches[] = ['path' => $file] + $hit;
            }
        }

        $out = [
            'success' => true,
            'matches' => $matches,
            'match_count' => \count($matches),
            'files_searched' => $searched,
            'truncated' => $truncated,
        ];
        if ([] !== $skipped) {
            $out['skipped'] = $skipped;
        }
        if ($truncated) {
            $out['note'] = 'results were cut off; narrow the search with a path prefix or a longer search text';
        } elseif ([] === $matches && 0 === $searched) {
            $out['note'] = '' === $pathPrefix ? 'no readable files to search' : "no code-lane files start with '{$pathPrefix}'";
        }

        return $out;
    }
}
