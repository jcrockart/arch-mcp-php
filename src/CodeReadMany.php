<?php

namespace ArchMcp;

/**
 * Read several code-lane files in one call (arch_code_read_many), added
 * 2026-10-29, so a chat is not forced into one round trip per file after
 * arch_code_list_files.
 *
 * Adds no file-access logic of its own: every file goes through
 * ArchTools::archCodeReadFile(), so the lane, stage-path and dot-component
 * rules are exactly those of arch_code_read_file. Needs no session.
 *
 * LIMITS: at most MAX_FILES paths per call and MAX_TOTAL_BYTES of file text in
 * the reply. Whole files only, never a cut-off file: a file that would pass the
 * byte total is reported with an error saying so (later, smaller files may still
 * fit), and paths beyond MAX_FILES are listed under `not_read`. Either way the
 * reply says `truncated: true` and what to do next. A failing path (missing,
 * outside the lane, not text) is a per-file error and never fails the call.
 */
final class CodeReadMany
{
    public const MAX_FILES = 40;
    public const MAX_TOTAL_BYTES = 400000;
    public const FORMAT = 'paths must be a JSON list such as ["src/a.php","src/b.php"] (one path per line, or comma-separated, also works)';

    /**
     * @return array{paths: list<string>, error?: string}
     */
    public static function parsePaths(string $raw): array
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return ['paths' => [], 'error' => 'no paths given; '.self::FORMAT];
        }

        if ('[' === $raw[0]) {
            $decoded = json_decode($raw, true);
            if (!\is_array($decoded) || !array_is_list($decoded)) {
                return ['paths' => [], 'error' => 'the JSON list of paths could not be read; '.self::FORMAT];
            }
            $items = [];
            foreach ($decoded as $value) {
                if (!\is_string($value)) {
                    return ['paths' => [], 'error' => 'every entry of the paths list must be a string; '.self::FORMAT];
                }
                $items[] = $value;
            }
        } else {
            $items = preg_split('/[,\r\n]+/', $raw) ?: [];
        }

        $paths = [];
        foreach ($items as $item) {
            $path = trim(trim($item), "\"' \t");
            if ('' !== $path && !\in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }
        if ([] === $paths) {
            return ['paths' => [], 'error' => 'no paths given; '.self::FORMAT];
        }

        return ['paths' => $paths];
    }

    /**
     * @return array<string, mixed>
     */
    public static function run(ArchTools $tools, string $pathsText): array
    {
        $parsed = self::parsePaths($pathsText);
        if (isset($parsed['error'])) {
            return ['success' => false, 'error' => $parsed['error']];
        }

        $files = [];
        $notRead = [];
        $total = 0;
        $read = 0;
        $truncated = false;
        foreach ($parsed['paths'] as $i => $path) {
            if ($i >= self::MAX_FILES) {
                $notRead[] = $path;
                $truncated = true;
                continue;
            }
            $result = $tools->archCodeReadFile($path);
            if (true !== ($result['success'] ?? false) || !\is_string($result['content'] ?? null)) {
                $files[] = ['path' => $path, 'success' => false, 'error' => (string) ($result['error'] ?? 'could not read the file')];
                continue;
            }
            $content = $result['content'];
            if (!mb_check_encoding($content, 'UTF-8')) {
                $files[] = ['path' => $path, 'success' => false, 'error' => 'not valid UTF-8 text (binary?), so it is not returned'];
                continue;
            }
            $size = \strlen($content);
            if ($total + $size > self::MAX_TOTAL_BYTES) {
                $files[] = ['path' => $path, 'success' => false, 'bytes' => $size, 'error' => "not returned: {$size} bytes would pass this call's ".self::MAX_TOTAL_BYTES.'-byte total; read it on its own with arch_code_read_file (start/end for part of it)'];
                $truncated = true;
                continue;
            }
            $total += $size;
            ++$read;
            $files[] = ['path' => $path, 'success' => true, 'bytes' => $size, 'content' => $content];
        }

        $out = [
            'success' => true,
            'files' => $files,
            'files_read' => $read,
            'total_bytes' => $total,
            'truncated' => $truncated,
        ];
        if ([] !== $notRead) {
            $out['not_read'] = $notRead;
        }
        if ($truncated) {
            $out['note'] = 'the reply was cut short by the limits ('.self::MAX_FILES.' files, '.self::MAX_TOTAL_BYTES.' bytes of text per call); ask for the files marked or listed under not_read in a further call';
        }

        return $out;
    }
}
