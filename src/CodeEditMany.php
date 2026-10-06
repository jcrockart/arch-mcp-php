<?php

namespace ArchMcp;

require_once __DIR__.'/CodeEdit.php';

/**
 * Several exact-match edits in one call, added 2026-10-06 (batch writes).
 *
 * Each edit is {path, old, new} and follows CodeEdit::apply() exactly: `old`
 * must match exactly once. Edits to the same file are applied in the order
 * given, each against the result of the one before, so a later edit can
 * build on an earlier one.
 *
 * ALL-OR-NOTHING ON MATCHING: every edit is checked in memory first. If any
 * edit fails to match once, nothing at all is written and the reply names
 * the failing edit (by position) and why.
 *
 * Writing then goes through ArchTools::archCodeWriteFile(), one file at a
 * time, so session, lane, stage-path, dot-component and write-extension
 * rules are the same as for every other code-lane write. Those checks can
 * differ per file (the extension allowlist), so if a write is refused part
 * way through, the reply lists the files already written and the one that
 * failed; nothing is rolled back (discard the session to undo).
 */
final class CodeEditMany
{
    public const MAX_EDITS = 50;

    /**
     * @param mixed $edits decoded JSON: a list of {path, old, new}
     *
     * @return array<string, mixed>
     */
    public static function run(ArchTools $tools, mixed $edits): array
    {
        if (!\is_array($edits) || [] === $edits || !array_is_list($edits)) {
            return ['success' => false, 'error' => 'edits must be a non-empty JSON list of {"path", "old", "new"} objects'];
        }
        if (\count($edits) > self::MAX_EDITS) {
            return ['success' => false, 'error' => 'at most '.self::MAX_EDITS.' edits per call'];
        }
        foreach ($edits as $i => $edit) {
            $n = $i + 1;
            if (!\is_array($edit) || !isset($edit['path'], $edit['old'], $edit['new']) || !\is_string($edit['path']) || !\is_string($edit['old']) || !\is_string($edit['new']) || '' === $edit['path']) {
                return ['success' => false, 'failed_edit' => $n, 'error' => "edit {$n} must have string fields path, old and new"];
            }
        }

        // Phase 1: read each file once, apply its edits in memory.
        $contents = [];
        $counts = [];
        $firstLine = [];
        foreach ($edits as $i => $edit) {
            $n = $i + 1;
            $path = $edit['path'];
            if (!isset($contents[$path])) {
                $read = $tools->archCodeReadFile($path);
                if (true !== ($read['success'] ?? false) || !\is_string($read['content'] ?? null)) {
                    return ['success' => false, 'failed_edit' => $n, 'path' => $path, 'error' => (string) ($read['error'] ?? 'could not read the file'), 'written' => []];
                }
                $contents[$path] = $read['content'];
                $counts[$path] = 0;
            }
            $result = CodeEdit::apply($contents[$path], $edit['old'], $edit['new']);
            if (true !== $result['success']) {
                return ['success' => false, 'failed_edit' => $n, 'path' => $path, 'written' => []] + array_diff_key($result, ['success' => true]);
            }
            $contents[$path] = $result['content'];
            ++$counts[$path];
            $firstLine[$path] ??= $result['line'];
        }

        // Phase 2: write.
        $written = [];
        foreach ($contents as $path => $content) {
            $write = $tools->archCodeWriteFile((string) $path, $content);
            if (true !== ($write['success'] ?? false)) {
                return [
                    'success' => false,
                    'path' => (string) $path,
                    'error' => (string) ($write['error'] ?? 'could not write the file'),
                    'written' => $written,
                    'note' => [] === $written ? 'nothing was written' : 'files already written were not rolled back; discard the session to undo them',
                ];
            }
            $written[] = ['path' => (string) $path, 'edits' => $counts[$path], 'first_line' => $firstLine[$path], 'bytes' => $write['bytes'] ?? \strlen($content)];
        }

        return ['success' => true, 'edits_applied' => \count($edits), 'files' => $written];
    }

    /**
     * Same as run(), taking the edits as the JSON text the tool receives.
     *
     * @return array<string, mixed>
     */
    public static function runJson(ArchTools $tools, string $editsJson): array
    {
        $decoded = json_decode($editsJson, true);
        if (\JSON_ERROR_NONE !== json_last_error()) {
            return ['success' => false, 'error' => 'editsJson is not valid JSON: '.json_last_error_msg()];
        }

        return self::run($tools, $decoded);
    }
}
