<?php

namespace ArchMcp;

/**
 * Exact-match edit for the code lane, added 2026-10-06.
 *
 * WHY: arch_code_write_file replaces a whole file, so changing one line of a
 * 90 KB file meant sending all 90 KB, and any copying slip silently altered
 * unrelated code. This replaces ONE occurrence of an exact string instead.
 *
 * RULES (all enforced by apply(), no fuzzy matching anywhere):
 *  - `old` must not be empty and must differ from `new`;
 *  - `old` must match the file EXACTLY ONCE, byte for byte, including
 *    whitespace and line endings. Overlapping occurrences count, so "aa" in
 *    "aaa" is two matches, not one;
 *  - zero matches or more than one match changes nothing and says so.
 *
 * run() adds no file-access logic of its own. It reads and writes through
 * ArchTools::archCodeReadFile()/archCodeWriteFile(), so the active-session
 * check, the code-lane check, the stage-path scoping, the dot-component
 * rule and the write-extension allowlist are exactly the ones every other
 * code-lane write already has. The reply never echoes file content.
 */
final class CodeEdit
{
    /** Counting stops here; the message only needs "more than one". */
    private const COUNT_CAP = 1000;

    /**
     * Pure string step: replace the single exact occurrence of $old.
     *
     * @return array{success: true, content: string, line: int}|array{success: false, error: string, matches?: int}
     */
    public static function apply(string $content, string $old, string $new): array
    {
        if ('' === $old) {
            return ['success' => false, 'error' => 'old text must not be empty (to create a file use arch_code_write_file)'];
        }
        if ($old === $new) {
            return ['success' => false, 'error' => 'old and new are identical, so there is nothing to change'];
        }

        $first = strpos($content, $old);
        if (false === $first) {
            return ['success' => false, 'matches' => 0, 'error' => 'old text not found; matching is exact, including whitespace and line endings'];
        }

        $matches = 1;
        $offset = $first + 1;
        while ($matches < self::COUNT_CAP && false !== ($next = strpos($content, $old, $offset))) {
            ++$matches;
            $offset = $next + 1;
        }
        if ($matches > 1) {
            $shown = $matches >= self::COUNT_CAP ? $matches.'+' : (string) $matches;

            return ['success' => false, 'matches' => $matches, 'error' => "old text matches {$shown} places; include more surrounding text so it matches exactly once"];
        }

        return [
            'success' => true,
            'content' => substr_replace($content, $new, $first, \strlen($old)),
            'line' => 1 + substr_count(substr($content, 0, $first), "\n"),
        ];
    }

    /**
     * Read, replace once, write back. Nothing is written unless the match is
     * unique and every ArchTools check on the write passes.
     *
     * @return array<string, mixed>
     */
    public static function run(ArchTools $tools, string $path, string $old, string $new): array
    {
        $read = $tools->archCodeReadFile($path);
        if (true !== ($read['success'] ?? false) || !\is_string($read['content'] ?? null)) {
            return ['success' => false, 'path' => $path, 'error' => (string) ($read['error'] ?? 'could not read the file')];
        }

        $edit = self::apply($read['content'], $old, $new);
        if (true !== $edit['success']) {
            return ['success' => false, 'path' => $path] + array_diff_key($edit, ['success' => true]);
        }

        $write = $tools->archCodeWriteFile($path, $edit['content']);
        if (true !== ($write['success'] ?? false)) {
            return ['success' => false, 'path' => $path, 'error' => (string) ($write['error'] ?? 'could not write the file')];
        }

        return [
            'success' => true,
            'path' => $path,
            'replaced' => 1,
            'line' => $edit['line'],
            'bytes' => $write['bytes'] ?? \strlen($edit['content']),
        ];
    }
}
