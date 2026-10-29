<?php

namespace ArchMcp;

/**
 * Policy for arch_code_delete_file, added 2026-10-29.
 *
 * The deletion itself is ArchTools::archCodeDeleteFile(), which applies the
 * same session, lane, stage-path, dot-component and write-extension rules as
 * archCodeWriteFile(). This class holds the two refusals that are specific to
 * deleting, as pure functions so they can be tested without a repository.
 *
 *  - PROTECTED: arch-gate.json (it defines the gate and the stage paths) and
 *    metadata/project.config.json can be edited but never deleted from a chat.
 *  - A file that is itself an entry of code.stage.paths (README.md,
 *    composer.json ...) is refused too. A session commit only stages stage-path
 *    entries that still exist on disk, so it could not record that deletion:
 *    the file would stay in git while gone from the working tree. Files
 *    inside a stage-path folder (src/, tests/ ...) are recorded normally.
 */
final class CodeDelete
{
    /** Lower-case, repo-relative. */
    public const PROTECTED_FILES = ['arch-gate.json', 'metadata/project.config.json'];

    /** Compare form of a path: no empty or "." parts, forward slashes, lower case. */
    public static function normalize(string $path): string
    {
        $parts = array_filter(
            explode('/', str_replace('\\', '/', $path)),
            static fn (string $p): bool => '' !== $p && '.' !== $p
        );

        return strtolower(implode('/', $parts));
    }

    public static function protectedRefusal(string $path): ?string
    {
        $norm = self::normalize($path);
        foreach (self::PROTECTED_FILES as $protected) {
            if ($norm === $protected) {
                return "'{$protected}' is protected: change it with arch_code_edit_file, but it cannot be deleted from a chat";
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $stagePaths code.stage.paths from arch-gate.json
     */
    public static function stageEntryRefusal(string $path, array $stagePaths): ?string
    {
        $norm = self::normalize($path);
        foreach ($stagePaths as $entry) {
            if (\is_string($entry) && '' !== self::normalize($entry) && $norm === self::normalize($entry)) {
                return "'{$path}' is itself a stage path in arch-gate.json, and a commit cannot record its deletion; only files inside a stage folder (such as src/ or tests/) can be deleted";
            }
        }

        return null;
    }
}
